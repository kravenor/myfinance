<?php

namespace App\Services;

use App\Models\InstrumentPrice;
use App\Models\InvestmentHolding;
use App\Models\SavingsGoal;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\BudgetThresholdNotification;
use App\Notifications\LargeExpenseNotification;
use App\Notifications\MonthlySummaryNotification;
use App\Notifications\SavingsGoalRiskNotification;
use App\Notifications\StalePriceNotification;
use App\Support\FinancialMonth;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use LogicException;

class NotificationScanner
{
    /** Giorni senza quotazioni dopo i quali uno strumento in portafoglio è «fermo». */
    public const STALE_PRICE_DAYS = 7;

    /** Il riepilogo del mese chiuso si manda solo nei primi giorni del mese nuovo. */
    public const MONTHLY_SUMMARY_DAYS = 7;

    public function __construct(
        private readonly BudgetAlertService $budgetAlerts,
        private readonly SavingsGoalProgressService $progress,
        private readonly ReportService $reports,
        private readonly CurrencyConverter $converter,
    ) {}

    /**
     * Genera le notifiche per l'utente autenticato (scope attivo), evitando
     * duplicati nello stesso periodo. Ritorna il numero di notifiche inviate.
     */
    public function scan(User $user): int
    {
        // Le query sotto filtrano via UserScope (Auth::id()), non via $user: senza questo
        // controllo un chiamante non autenticato notificherebbe $user con i dati di tutti.
        if ((int) Auth::id() !== (int) $user->id) {
            throw new LogicException('NotificationScanner::scan() richiede $user autenticato.');
        }

        $sent = 0;
        $now = Carbon::now();
        $prefs = $user->notificationPreferences();

        if ($prefs['budget']) {
            $threshold = (float) $prefs['budget_threshold'];
            [$currentStart] = FinancialMonth::range($now);
            foreach ($this->budgetAlerts->alerts($currentStart->year, $currentStart->month, $threshold) as $alert) {
                $sent += (int) $user->notifyOnce(new BudgetThresholdNotification($alert));
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
                $sent += (int) $user->notifyOnce(new SavingsGoalRiskNotification($goal, $status));
            }
        }

        if ($prefs['stale_prices']) {
            $sent += $this->stalePrices($user, $now);
        }

        if ($prefs['large_expense']) {
            $sent += $this->largeExpenses($user, (float) $prefs['large_expense_threshold'], $now);
        }

        if ($prefs['monthly_summary']) {
            $sent += $this->monthlySummary($user, $now);
        }

        return $sent;
    }

    /** Strumenti in portafoglio con quotazione automatica ferma da più di STALE_PRICE_DAYS giorni. */
    private function stalePrices(User $user, Carbon $now): int
    {
        $holdings = InvestmentHolding::query()
            ->whereNotNull('symbol')
            ->where('symbol', '!=', '')
            ->where('quantity', '>', 0)
            ->get()
            ->filter(fn (InvestmentHolding $h) => $h->priceProvider() !== null);

        if ($holdings->isEmpty()) {
            return 0;
        }

        $lastQuote = InstrumentPrice::query()->toBase()
            ->whereIn('symbol', $holdings->pluck('symbol')->unique())
            ->groupBy('provider', 'symbol')
            ->selectRaw('provider, symbol, MAX(as_of) as last_as_of')
            ->get()
            ->mapWithKeys(fn (object $row) => [InstrumentPrice::key($row->provider, $row->symbol) => $row->last_as_of]);

        $limit = $now->copy()->subDays(self::STALE_PRICE_DAYS)->startOfDay();
        $sent = 0;

        foreach ($holdings as $holding) {
            $last = $lastQuote[$holding->priceKey()] ?? null;
            $lastAsOf = $last !== null ? Carbon::parse($last)->toDateString() : null;
            // Uno strumento appena creato non è «fermo»: si conta dalla sua creazione.
            $reference = $lastAsOf ? Carbon::parse($lastAsOf) : $holding->created_at;
            if ($reference === null || $reference->gte($limit)) {
                continue;
            }

            $sent += (int) $user->notifyOnce(new StalePriceNotification($holding, $lastAsOf));
        }

        return $sent;
    }

    /** Uscite registrate di recente (anche importate o da ricorrente) oltre la soglia, in valuta base. */
    private function largeExpenses(User $user, float $threshold, Carbon $now): int
    {
        $base = strtoupper($user->currency);
        $transactions = Transaction::query()
            ->with('category:id,name')
            ->where('type', 'expense')
            ->where('created_at', '>=', $now->copy()->subDays(2))
            // Un import di estratti vecchi non deve generare decine di avvisi.
            ->whereDate('occurred_at', '>=', $now->copy()->subDays(7)->toDateString())
            ->get();

        $sent = 0;
        foreach ($transactions as $transaction) {
            $amount = $this->converter->convert((float) $transaction->amount, $transaction->currency, $base, $transaction->occurred_at);
            if ($amount < $threshold) {
                continue;
            }
            $sent += (int) $user->notifyOnce(new LargeExpenseNotification($transaction, $amount, $base, $transaction->category?->name));
        }

        return $sent;
    }

    /** Nei primi giorni del mese finanziario: entrate, uscite e risparmio del mese chiuso. */
    private function monthlySummary(User $user, Carbon $now): int
    {
        [$currentStart] = FinancialMonth::range($now);
        if ($now->gt($currentStart->copy()->addDays(self::MONTHLY_SUMMARY_DAYS - 1)->endOfDay())) {
            return 0;
        }

        [$previousStart] = FinancialMonth::range($currentStart->copy()->subDay());
        $comparison = $this->reports->periodComparison($previousStart, 'month');
        $month = $comparison['current'];

        if ((float) $month['income'] === 0.0 && (float) $month['expense'] === 0.0) {
            return 0; // mese senza movimenti (es. utente appena registrato)
        }

        return (int) $user->notifyOnce(new MonthlySummaryNotification([
            'label' => $month['label'],
            'from' => $month['from'],
            'to' => $month['to'],
            'income' => $month['income'],
            'expense' => $month['expense'],
            'net' => $month['net'],
            'currency' => $comparison['base_currency'],
            'expense_pct' => $comparison['delta']['expense_pct'],
        ]));
    }

    /**
     * Invia la notifica solo se non già presente (stessa dedupKey).
     */
}
