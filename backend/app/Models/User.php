<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Notifications\Notification;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property array<string, mixed>|null $notification_preferences
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

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
    ];

    protected $fillable = [
        'name',
        'email',
        'password',
        'currency',
        'locale',
        'notification_preferences',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'notification_preferences' => 'array',
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

    public function notificationPreference(string $key): mixed
    {
        return $this->notificationPreferences()[$key] ?? null;
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
