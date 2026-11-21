<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Invito alla registrazione creato da un admin. Non usa BelongsToUser:
 * gli admin vedono tutti gli inviti, non solo i propri.
 *
 * @property int $id
 * @property string|null $email
 * @property string $token_hash
 * @property int $invited_by
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property int|null $accepted_user_id
 * @property Carbon|null $created_at
 */
class Invitation extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = ['email', 'token_hash', 'invited_by', 'expires_at'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    /**
     * Crea l'invito e restituisce anche il token in chiaro, che non viene salvato.
     *
     * @return array{0: self, 1: string}
     */
    public static function issue(User $inviter, ?string $email): array
    {
        $token = Str::random(48);

        $invitation = self::create([
            'email' => $email !== null ? Str::lower($email) : null,
            'token_hash' => self::hash($token),
            'invited_by' => $inviter->id,
            'expires_at' => now()->addDays((int) config('finance.invitations.expires_days')),
        ]);

        return [$invitation, $token];
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Invito ancora usabile per quel token, o null (inesistente, scaduto o già usato).
     */
    public static function findUsable(?string $token): ?self
    {
        if ($token === null || $token === '') {
            return null;
        }

        return self::query()->usable()->where('token_hash', self::hash($token))->first();
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeUsable(Builder $query): void
    {
        $query->whereNull('accepted_at')->where('expires_at', '>', now());
    }

    public static function url(string $token): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/register?invite='.urlencode($token);
    }

    public function status(): string
    {
        if ($this->accepted_at !== null) {
            return self::STATUS_ACCEPTED;
        }

        return $this->expires_at->isPast() ? self::STATUS_EXPIRED : self::STATUS_PENDING;
    }

    public function allowsEmail(string $email): bool
    {
        return $this->email === null || $this->email === Str::lower($email);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function acceptedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_user_id');
    }
}
