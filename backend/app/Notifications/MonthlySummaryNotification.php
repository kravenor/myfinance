<?php

namespace App\Notifications;

use App\Notifications\Concerns\ChannelsFromPreferences;
use App\Notifications\Contracts\Dedupable;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/** Riepilogo del mese finanziario appena chiuso, con il confronto col precedente. */
class MonthlySummaryNotification extends Notification implements Dedupable, ShouldQueue
{
    use ChannelsFromPreferences, Queueable;

    /**
     * @param  array{label: string, from: string, to: string, income: string, expense: string, net: string, currency: string, expense_pct: ?string}  $month
     */
    public function __construct(private readonly array $month) {}

    public function dedupKey(): string
    {
        return "monthly-summary:{$this->month['label']}";
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $m = $this->month;
        $name = ucfirst(Carbon::parse($m['label'].'-01')->locale('it')->translatedFormat('F Y'));
        $net = (float) $m['net'];
        $saving = ($net < 0 ? 'risparmio negativo ' : 'risparmio ').Money::format(abs($net), $m['currency']);
        $trend = $m['expense_pct'] !== null
            ? ' Uscite '.((float) $m['expense_pct'] > 0 ? 'in aumento' : 'in calo').' del '.abs(round((float) $m['expense_pct'])).'% sul mese prima.'
            : '';

        return [
            'key' => $this->dedupKey(),
            'type' => 'monthly_summary',
            'level' => 'info',
            'title' => "Riepilogo di {$name}",
            'message' => 'Entrate '.Money::format($m['income'], $m['currency']).', uscite '.Money::format($m['expense'], $m['currency']).", {$saving}.{$trend}",
            'url' => "/reports?tab=trend&period=custom&from={$m['from']}&to={$m['to']}",
        ];
    }
}
