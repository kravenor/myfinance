<?php

namespace App\Services;

use App\Models\CategorizationRule;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class CategorizationRuleMatcher
{
    /** Passi di backtracking per una regex utente (default PHP 1.000.000, ~2 ms su un pattern catastrofico). */
    private const REGEX_BACKTRACK_LIMIT = 10000;

    /** @var Collection<int, CategorizationRule>|null */
    private ?Collection $cache = null;

    /** @var array<int, int> */
    private array $hits = [];

    /** @var array<int, true> Regole regex andate in errore in questo run. */
    private array $brokenRegex = [];

    public function preload(): void
    {
        /** @var Collection<int, CategorizationRule> $rules */
        $rules = CategorizationRule::query()
            ->where('is_active', true)
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        $this->cache = $rules;
    }

    public function match(?string $description, string $type): ?CategorizationRule
    {
        // Un giroconto non ha categoria: le regole valgono solo per entrate e uscite.
        if ($type === 'transfer' || $description === null || trim($description) === '') {
            return null;
        }

        if ($this->cache === null) {
            $this->preload();
        }

        $desc = mb_strtolower($description);

        /** @var Collection<int, CategorizationRule> $cache */
        $cache = $this->cache;

        foreach ($cache as $rule) {
            if ($rule->applies_to_type !== 'any' && $rule->applies_to_type !== $type) {
                continue;
            }
            if ($this->matches($desc, $rule)) {
                return $rule;
            }
        }

        return null;
    }

    public function recordHit(CategorizationRule $rule): void
    {
        $this->hits[$rule->id] = ($this->hits[$rule->id] ?? 0) + 1;
    }

    public function flushHits(): void
    {
        if ($this->hits === []) {
            return;
        }

        $now = now();
        foreach ($this->hits as $ruleId => $count) {
            CategorizationRule::withoutGlobalScopes()
                ->whereKey($ruleId)
                ->update([
                    'times_applied' => DB::raw('times_applied + '.(int) $count),
                    'last_applied_at' => $now,
                ]);
        }

        $this->hits = [];
    }

    public function reset(): void
    {
        $this->cache = null;
        $this->hits = [];
        $this->brokenRegex = [];
    }

    private function matches(string $desc, CategorizationRule $rule): bool
    {
        $needle = mb_strtolower($rule->pattern);

        return match ($rule->match_type) {
            'contains' => str_contains($desc, $needle),
            'starts_with' => str_starts_with($desc, $needle),
            'equals' => $desc === $needle,
            'regex' => $this->matchesRegex($desc, $rule),
            default => false,
        };
    }

    /**
     * Le regex sono scritte dall'utente e girano su ogni transazione (import fino a
     * 5000 righe, applica regole): limite di backtracking basso perché un pattern
     * catastrofico costi poco, e la regola che va in errore si salta per il resto del run.
     */
    private function matchesRegex(string $desc, CategorizationRule $rule): bool
    {
        if (isset($this->brokenRegex[$rule->id])) {
            return false;
        }

        $previous = ini_set('pcre.backtrack_limit', (string) self::REGEX_BACKTRACK_LIMIT);
        $result = @preg_match('/'.str_replace('/', '\/', $rule->pattern).'/iu', $desc);
        if ($previous !== false) {
            ini_set('pcre.backtrack_limit', $previous);
        }

        if ($result === false) {
            $this->brokenRegex[$rule->id] = true;

            return false;
        }

        return $result === 1;
    }
}
