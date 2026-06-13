<?php

namespace App\Services;

use App\Models\SavingsGoal;
use App\Models\User;
use App\Notifications\BudgetThresholdNotification;
use App\Notifications\Contracts\Dedupable;
use App\Notifications\SavingsGoalRiskNotification;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

class NotificationScanner
{
    public function __construct(
        private readonly BudgetAlertService $budgetAlerts,
        private readonly SavingsGoalProgressService $progress,
    ) {}

    /**
     * Genera le notifiche per l'utente autenticato (scope attivo), evitando
     * duplicati nello stesso periodo. Ritorna il numero di notifiche inviate.
     */
    public function scan(User $user): int
    {
        $sent = 0;
        $now = Carbon::now();
        $prefs = $user->notificationPreferences();

        if ($prefs['budget']) {
            $threshold = (float) $prefs['budget_threshold'];
            foreach ($this->budgetAlerts->alerts($now->year, $now->month, $threshold) as $alert) {
                $sent += $this->dispatch($user, new BudgetThresholdNotification($alert));
            }
        }

        if ($prefs['savings_goals']) {
            $goals = SavingsGoal::query()
                ->where('status', 'active')
                ->whereNotNull('target_date')
                ->get();
            $this->progress->attachProgress($goals->all(), $now);

            foreach ($goals as $goal) {
                $status = $goal->getAttribute('pace')['status'] ?? null;
                if (! in_array($status, ['behind', 'overdue'], true)) {
                    continue;
                }
                $sent += $this->dispatch($user, new SavingsGoalRiskNotification($goal, $status));
            }
        }

        return $sent;
    }

    /**
     * Invia la notifica solo se non già presente (stessa dedupKey).
     */
    private function dispatch(User $user, Notification&Dedupable $notification): int
    {
        $key = $notification->dedupKey();

        if ($user->notifications()->where('data->key', $key)->exists()) {
            return 0;
        }

        $user->notify($notification);

        return 1;
    }
}
