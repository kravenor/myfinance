<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;

/**
 * Posizione (holding) su un asset detenuto in un conto di tipo investment.
 * Quantità e costo medio non si scrivono a mano: sono derivati dal registro
 * movimenti (`transactions`) da HoldingPositionRecalculator.
 * Il prezzo effettivo segue la precedenza: quotazione automatica risolta
 * (da `instrument_prices`, via InvestmentPriceResolver) → `last_price` manuale
 * → costo medio.
 *
 * @property int $id
 * @property int $user_id
 * @property int $account_id
 * @property string $name
 * @property string|null $symbol
 * @property string|null $omi_zone LinkZona OMI (solo immobili, vedi RealEstateIndex)
 * @property int|null $omi_type Cod_Tip OMI (tipologia)
 * @property string|null $omi_state stato conservativo OMI
 * @property string|null $surface_sqm m² dell'immobile, solo per il confronto con la media OMI
 * @property string|null $isin
 * @property string $asset_type
 * @property string $currency
 * @property string $quantity
 * @property string $avg_cost
 * @property string $realized_pl
 * @property string $net_invested
 * @property string|null $last_price
 * @property Carbon|null $last_price_at
 * @property string|null $notes
 * @property-read Account|null $account
 * @property-read OmiQuotation|null $omiZone
 * @property-read Collection<int, InvestmentTransaction> $transactions
 * @property-read string|null $transactions_sum_fees solo con withSum('transactions', 'fees')
 */
class InvestmentHolding extends Model
{
    use BelongsToUser, HasFactory;

    /** Strumenti per utente: lo storico mensile e le quotazioni notturne lavorano su ognuno. */
    public const MAX_PER_USER = 200;

    protected $fillable = [
        'user_id',
        'account_id',
        'name',
        'symbol',
        'omi_zone',
        'omi_type',
        'omi_state',
        'surface_sqm',
        'isin',
        'asset_type',
        'currency',
        'quantity',
        'avg_cost',
        'realized_pl',
        'net_invested',
        'last_price',
        'last_price_at',
        'notes',
    ];

    /**
     * Prezzo da quotazione automatica (valuta dell'holding), impostato in modo
     * transitorio da InvestmentPriceResolver::hydrate(); null se non risolto.
     */
    private ?float $resolvedPrice = null;

    /** Data (Y-m-d) della quotazione automatica risolta; null se non risolta. */
    private ?string $resolvedAsOf = null;

    /** Fonte dell'indice dell'ultimo punto usato, per gli immobili: 'omi' o 'istat'. */
    private ?string $resolvedIndex = null;

    /** Ultima quotazione OMI della zona scelta, per il confronto con la superficie. */
    private ?OmiQuotation $omiReference = null;

    protected static function booted(): void
    {
        // Obbligazioni e certificati non hanno un ticker: l'auto-fetch
        // (BorsaItalianaProvider, TeleborsaProvider) li cerca per ISIN, che diventa
        // quindi il `symbol` dell'holding. Nel model e non nelle Form Request perché
        // in update `asset_type` può non essere nel payload: qui l'attributo è già
        // quello definitivo.
        static::saving(function (self $holding): void {
            if (in_array($holding->asset_type, ['bond', 'certificate'], true) && ! $holding->symbol && $holding->isin) {
                $holding->symbol = $holding->isin;
            }
            // Immobili: senza territorio scelto si indicizza sulla media nazionale.
            if ($holding->asset_type === 'real_estate' && ! $holding->symbol) {
                $holding->symbol = 'IT';
            }
            // La zona OMI ha senso solo per un immobile, e solo completa di tipologia e stato.
            if ($holding->asset_type !== 'real_estate' || ! $holding->omi_zone) {
                $holding->omi_zone = $holding->omi_type = $holding->omi_state = null;
            }
            if ($holding->asset_type !== 'real_estate') {
                $holding->surface_sqm = null;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'omi_type' => 'integer',
            'surface_sqm' => 'decimal:2',
            'quantity' => 'decimal:8',
            'avg_cost' => 'decimal:8',
            'realized_pl' => 'decimal:2',
            'net_invested' => 'decimal:2',
            'last_price' => 'decimal:8',
            'last_price_at' => 'datetime',
        ];
    }

    public function usingResolvedPrice(?float $price, ?string $asOf = null, ?string $index = null): static
    {
        $this->resolvedPrice = $price;
        $this->resolvedAsOf = $asOf;
        $this->resolvedIndex = $index;

        return $this;
    }

    public function resolvedPrice(): ?float
    {
        return $this->resolvedPrice;
    }

    public function resolvedAsOf(): ?string
    {
        return $this->resolvedAsOf;
    }

    public function resolvedIndex(): ?string
    {
        return $this->resolvedIndex;
    }

    public function usingOmiReference(?OmiQuotation $quotation): static
    {
        $this->omiReference = $quotation;

        return $this;
    }

    public function omiReference(): ?OmiQuotation
    {
        return $this->omiReference;
    }

    /**
     * Provider delle quotazioni automatiche, da `asset_type`; null se il tipo non ne ha.
     * Le quote si leggono sempre per (provider, symbol): lo stesso simbolo con un tipo
     * diverso, anche di un altro utente, non deve cambiare la fonte del prezzo.
     */
    public function priceProvider(): ?string
    {
        $provider = config('finance.prices.providers')[$this->asset_type] ?? null;

        return is_string($provider) ? $provider : null;
    }

    /** Chiave delle quote di questo holding in `instrument_prices`, vedi InstrumentPrice::key(). */
    public function priceKey(): string
    {
        return InstrumentPrice::key($this->priceProvider(), $this->symbol);
    }

    /** Le quote di questo holding sono un numero indice da applicare a un valore, non un prezzo. */
    public function isIndexed(): bool
    {
        return $this->asset_type === 'real_estate';
    }

    /**
     * Valore indicizzato al $at: l'ancora × indice($at) / indice(data dell'ancora). L'ancora
     * è la valutazione manuale (`last_price` a `last_price_at`) se non è successiva a $at,
     * altrimenti il costo ($cost al $costAt, il primo movimento). A differenza delle quote,
     * qui `last_price` vale anche nel passato: è una stima datata, non un prezzo di oggi.
     * Null se manca l'indice per una delle due date (serie non ancora scaricata).
     *
     * @param  SupportCollection<int, InstrumentPrice>  $index  ordinato per as_of
     */
    public function indexedPrice(SupportCollection $index, Carbon $at, float $cost, ?Carbon $costAt): ?float
    {
        [$anchor, $anchorAt] = $this->last_price !== null && $this->last_price_at?->lte($at)
            ? [(float) $this->last_price, $this->last_price_at]
            : [$cost, $costAt];

        if ($anchorAt === null) {
            return null;
        }

        $from = $index->last(fn (InstrumentPrice $p) => $p->as_of->lte($anchorAt));
        $to = $index->last(fn (InstrumentPrice $p) => $p->as_of->lte($at));

        return $from && $to && (float) $from->price > 0
            ? $anchor * (float) $to->price / (float) $from->price
            : null;
    }

    /**
     * Origine del prezzo effettivo: 'auto' (quotazione risolta) → 'manual'
     * (`last_price`) → 'cost' (costo medio). Stessa precedenza di effectivePrice().
     */
    public function priceSource(): string
    {
        if ($this->resolvedPrice !== null) {
            return 'auto';
        }

        return $this->last_price !== null ? 'manual' : 'cost';
    }

    /**
     * Prezzo per il valore di mercato, in ordine di precedenza: quotazione
     * automatica risolta → `last_price` manuale → costo medio (parità).
     */
    public function effectivePrice(): float
    {
        return (float) ($this->resolvedPrice ?? $this->last_price ?? $this->avg_cost);
    }

    public function marketValue(): float
    {
        return (float) $this->quantity * $this->effectivePrice();
    }

    public function costBasis(): float
    {
        return (float) $this->quantity * (float) $this->avg_cost;
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** Registro dei movimenti da cui è derivata la posizione. */
    /** Quotazione più recente della zona OMI scelta, per mostrarne comune e nome. */
    public function omiZone(): HasOne
    {
        return $this->hasOne(OmiQuotation::class, 'link_zona', 'omi_zone')->latestOfMany('semester');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(InvestmentTransaction::class);
    }
}
