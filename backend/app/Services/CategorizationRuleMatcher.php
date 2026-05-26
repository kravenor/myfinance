<?php

namespace App\Services;

use App\Models\CategorizationRule;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class CategorizationRuleMatcher
{
    /** @var Collection<int, CategorizationRule>|null */
    private ?Collection $cache = null;

    /** @var array<int, int> */
    private array $hits = [];

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
        if ($description === null || trim($description) === '') {
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
    }

    private function matches(string $desc, CategorizationRule $rule): bool
    {
        $needle = mb_strtolower($rule->pattern);

        return match ($rule->match_type) {
            'contains' => str_contains($desc, $needle),
            'starts_with' => str_starts_with($desc, $needle),
            'equals' => $desc === $needle,
            'regex' => @preg_match('/'.str_replace('/', '\/', $rule->pattern).'/iu', $desc) === 1,
            default => false,
        };
    }
}
