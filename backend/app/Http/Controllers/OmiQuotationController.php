<?php

namespace App\Http\Controllers;

use App\Models\InvestmentHolding;
use App\Models\OmiQuotation;
use App\Services\OmiImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Quotazioni OMI caricate dall'utente per indicizzare gli immobili per zona.
 * Tutto passa dallo scope utente di OmiQuotation: nessuno vede le righe altrui.
 */
class OmiQuotationController extends Controller
{
    /** Semestri caricati, dal più recente, con il numero di comuni. */
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', InvestmentHolding::class);

        $semesters = OmiQuotation::query()
            ->selectRaw('semester, COUNT(DISTINCT comune) as comuni')
            ->groupBy('semester')
            ->orderByDesc('semester')
            ->get()
            ->map(fn (OmiQuotation $q) => ['semester' => $q->semester->toDateString(), 'comuni' => (int) $q->getAttribute('comuni')]);

        return response()->json(['data' => $semesters]);
    }

    public function import(Request $request, OmiImportService $importer): JsonResponse
    {
        $this->authorize('create', InvestmentHolding::class);

        $request->validate([
            // Lo zip nazionale delle Forniture OMI pesa ~2 MB; un CSV comunale o regionale molto meno.
            'file' => ['required', 'file', 'mimetypes:application/zip,application/x-zip-compressed,text/csv,text/plain,application/csv,application/octet-stream', 'max:10240'],
        ]);

        return response()->json(['data' => $importer->import($request->file('file'))], 201);
    }

    /**
     * Zone dei comuni che iniziano per `q`, o la sola zona `zone` (form in modifica), dal
     * semestre più recente in cui compaiono, con le combinazioni tipologia/stato quotate.
     */
    public function zones(Request $request): JsonResponse
    {
        $this->authorize('viewAny', InvestmentHolding::class);

        $data = $request->validate([
            'q' => ['required_without:zone', 'string', 'min:2', 'max:80'],
            'zone' => ['nullable', 'string', 'max:16'],
        ]);

        $rows = OmiQuotation::query()
            ->when(
                $data['zone'] ?? null,
                fn ($q, string $zone) => $q->where('link_zona', $zone),
                fn ($q) => $q->where('comune', 'like', str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $data['q']).'%'),
            )
            ->orderByDesc('semester')
            ->limit(5000)
            ->get(['link_zona', 'comune', 'prov', 'zona', 'zona_descr', 'cod_tip', 'tipologia', 'stato', 'semester']);

        $zones = $rows->toBase()->groupBy('link_zona')->map(function (Collection $quotes): array {
            /** @var OmiQuotation $latest */
            $latest = $quotes->first();

            return [
                'link_zona' => $latest->link_zona,
                'comune' => $latest->comune,
                'prov' => $latest->prov,
                'zona' => $latest->zona,
                'zona_descr' => $latest->zona_descr,
                // Semestri caricati in cui la combinazione compare, dal più vecchio: sono i punti
                // della serie OMI dell'immobile. Completi con `zone`, con `q` il tetto di righe può tagliarli.
                'types' => $quotes->groupBy(fn (OmiQuotation $q) => $q->cod_tip.'|'.$q->stato)
                    ->map(fn (Collection $same) => [
                        'cod_tip' => $same->first()->cod_tip,
                        'tipologia' => $same->first()->tipologia,
                        'stato' => $same->first()->stato,
                        'semesters' => $same->map(fn (OmiQuotation $q) => $q->semester->toDateString())->sort()->values(),
                    ])
                    ->sortBy(['tipologia', 'stato'])
                    ->values(),
            ];
        })->sortBy(['comune', 'zona'])->take(100)->values();

        return response()->json(['data' => $zones]);
    }
}
