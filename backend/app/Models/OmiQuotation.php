<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Quotazione OMI (Agenzia delle Entrate) di un semestre: intervallo di compravendita
 * in €/m² per zona (`link_zona`), tipologia (`cod_tip`) e stato conservativo.
 * Caricata dall'utente con OmiImportService; per gli immobili fa da indice locale,
 * vedi RealEstateIndex.
 *
 * @property int $id
 * @property int $user_id
 * @property Carbon $semester
 * @property string $link_zona
 * @property string $comune
 * @property string $prov
 * @property string $zona
 * @property string|null $zona_descr
 * @property int $cod_tip
 * @property string $tipologia
 * @property string $stato
 * @property string $compr_min
 * @property string $compr_max
 * @property string|null $sup_nl superficie a cui si riferisce il €/m²: L lorda, N netta
 */
class OmiQuotation extends Model
{
    use BelongsToUser;

    /** Stati conservativi del CSV OMI. */
    public const STATES = ['OTTIMO', 'NORMALE', 'SCADENTE'];

    protected $fillable = [
        'user_id',
        'semester',
        'link_zona',
        'comune',
        'prov',
        'zona',
        'zona_descr',
        'cod_tip',
        'tipologia',
        'stato',
        'compr_min',
        'compr_max',
        'sup_nl',
    ];

    protected function casts(): array
    {
        return [
            'semester' => 'date:Y-m-d',
            'cod_tip' => 'integer',
            'compr_min' => 'decimal:2',
            'compr_max' => 'decimal:2',
        ];
    }

    /** Valore centrale dell'intervallo, in €/m². */
    public function mid(): float
    {
        return ((float) $this->compr_min + (float) $this->compr_max) / 2;
    }
}
