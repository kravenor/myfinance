<?php

namespace App\Notifications;

use App\Notifications\Concerns\ChannelsFromPreferences;
use App\Notifications\Contracts\Dedupable;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
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
        $net = (float) $m['net'];
        $saving = ($net < 0 ? 'risparmio negativo ' : 'risparmio ').Money::format(abs($net), $m['currency']);
        $trend = $this->trend();

        return [
            'key' => $this->dedupKey(),
            'type' => 'monthly_summary',
            'level' => 'info',
            'title' => "Riepilogo di {$this->monthName()}",
            'message' => 'Entrate '.Money::format($m['income'], $m['currency']).', uscite '.Money::format($m['expense'], $m['currency']).", {$saving}.".($trend ? " {$trend}" : ''),
            'url' => "/reports?tab=trend&period=custom&from={$m['from']}&to={$m['to']}",
        ];
    }

    /** Email con template proprio: importi in tabella, entrate in verde e uscite in rosso. */
    public function toMail(object $notifiable): MailMessage
    {
        $m = $this->month;
        $data = $this->toArray($notifiable);

        return (new MailMessage)
            ->subject($data['title'])
            ->markdown('mail.monthly-summary', [
                'name' => $notifiable->name ?? null,
                'month' => $this->monthName(),
                'income' => Money::format($m['income'], $m['currency']),
                'expense' => Money::format($m['expense'], $m['currency']),
                'net' => Money::format($m['net'], $m['currency']),
                'netPositive' => (float) $m['net'] >= 0,
                'trend' => $this->trend(),
                'url' => url($data['url']),
            ]);
    }

    private function monthName(): string
    {
        return ucfirst(Carbon::parse($this->month['label'].'-01')->locale('it')->translatedFormat('F Y'));
    }

    private function trend(): ?string
    {
        $pct = $this->month['expense_pct'];

        return $pct === null
            ? null
            : 'Uscite '.((float) $pct > 0 ? 'in aumento' : 'in calo').' del '.abs(round((float) $pct)).'% sul mese prima.';
    }
}
