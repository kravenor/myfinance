<?php

namespace App\Models;

use App\Notifications\Contracts\Dedupable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use NotificationChannels\WebPush\HasPushSubscriptions;

/**
 * @property array<string, mixed>|null $notification_preferences
 * @property string|null $two_factor_secret
 * @property list<string>|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property int|null $two_factor_last_timestep
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasPushSubscriptions, Notifiable;

    /**
     * Preferenze notifiche di default (merge con quelle salvate dall'utente).
     *
     * @var array<string, mixed>
     */
    public const NOTIFICATION_DEFAULTS = [
        'email' => true,
        'email_address' => null,
        'budget' => true,
        'savings_goals' => true,
        'budget_threshold' => 80,
        'pac' => true,
        'stale_prices' => true,
        'monthly_summary' => true,
        // Spento finché non si sceglie una soglia: con un default qualsiasi sarebbe rumoroso.
        'large_expense' => false,
        'large_expense_threshold' => 500,
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'date_format' => 'd/m/Y',
        'month_start_day' => 1,
    ];

    protected $fillable = [
        'name',
        'email',
        'password',
        'currency',
        'locale',
        'date_format',
        'month_start_day',
        'notification_preferences',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_last_timestep',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'notification_preferences' => 'array',
            'month_start_day' => 'integer',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_last_timestep' => 'integer',
        ];
    }

    /**
     * Preferenze notifiche complete (default + override salvati).
     *
     * @return array<string, mixed>
     */
    public function notificationPreferences(): array
    {
        return array_merge(self::NOTIFICATION_DEFAULTS, $this->notification_preferences ?? []);
    }

    public function hasTwoFactor(): bool
    {
        return $this->two_factor_confirmed_at !== null && $this->two_factor_secret !== null;
    }

    public function notificationPreference(string $key): mixed
    {
        return $this->notificationPreferences()[$key] ?? null;
    }

    /**
     * Invia solo se l'utente non ha già una notifica con la stessa chiave (anche letta):
     * una per stato e periodo, che arrivi dalla scansione, da una scrittura o dal runner.
     */
    public function notifyOnce(Notification&Dedupable $notification): bool
    {
        if ($this->notifications()->where('data->key', $notification->dedupKey())->exists()) {
            return false;
        }

        $this->notify($notification);

        return true;
    }

    /**
     * Indirizzo email per le notifiche: quello personalizzato se impostato,
     * altrimenti l'email dell'account.
     */
    public function routeNotificationForMail(Notification $notification): string
    {
        $custom = $this->notificationPreference('email_address');

        return is_string($custom) && $custom !== '' ? $custom : $this->email;
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function budgets(): HasMany
    {
        return $this->hasMany(Budget::class);
    }

    public function recurringTransactions(): HasMany
    {
        return $this->hasMany(RecurringTransaction::class);
    }

    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class);
    }

    public function categorizationRules(): HasMany
    {
        return $this->hasMany(CategorizationRule::class);
    }

    public function savingsGoals(): HasMany
    {
        return $this->hasMany(SavingsGoal::class);
    }
}
