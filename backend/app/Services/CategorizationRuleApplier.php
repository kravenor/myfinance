<?php

namespace App\Services;

use App\Models\Transaction;
use Illuminate\Database\Eloquent\Collection;

class CategorizationRuleApplier
{
    public function __construct(
        private readonly CategorizationRuleMatcher $matcher,
    ) {}

    /**
     * Applica le regole di categorizzazione alle transazioni esistenti.
     *
     * @param  array{only_uncategorized?: bool, account_id?: int|null, from?: string|null, to?: string|null}  $filters
     * @return array{matched: int, updated: int, by_rule: array<int, array{rule_id: int, name: string, count: int}>, sample: array<int, array<string, mixed>>}
     */
    public function run(array $filters, bool $dryRun): array
    {
        $this->matcher->reset();
        $this->matcher->preload();

        $query = Transaction::query();

        if (($filters['only_uncategorized'] ?? true) === true) {
            $query->whereNull('category_id');
        }
        if (! empty($filters['account_id'])) {
            $query->where('account_id', $filters['account_id']);
        }
        if (! empty($filters['from'])) {
            $query->whereDate('occurred_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->whereDate('occurred_at', '<=', $filters['to']);
        }

        $matched = 0;
        $updated = 0;

        /** @var array<int, array{rule_id: int, name: string, count: int}> $byRule */
        $byRule = [];
        /** @var array<int, array<string, mixed>> $sample */
        $sample = [];
        /** @var array<int, int> $updates  tx_id => category_id */
        $updates = [];

        $query->orderBy('id')->chunk(500, function (Collection $txs) use (&$matched, &$updates, &$byRule, &$sample, $dryRun): void {
            foreach ($txs as $tx) {
                $rule = $this->matcher->match($tx->description, $tx->type);
                if (! $rule) {
                    continue;
                }

                $matched++;

                if (! isset($byRule[$rule->id])) {
                    $byRule[$rule->id] = ['rule_id' => $rule->id, 'name' => $rule->name, 'count' => 0];
                }
                $byRule[$rule->id]['count']++;

                if (count($sample) < 50) {
                    $sample[] = [
                        'transaction_id' => $tx->id,
                        'occurred_at' => $tx->occurred_at->toDateString(),
                        'description' => $tx->description,
                        'suggested_category_id' => $rule->category_id,
                        'rule_id' => $rule->id,
                    ];
                }

                if (! $dryRun) {
                    $updates[$tx->id] = $rule->category_id;
                    $this->matcher->recordHit($rule);
                }
            }
        });

        if (! $dryRun && $updates !== []) {
            $groupByCategory = [];
            foreach ($updates as $txId => $categoryId) {
                $groupByCategory[$categoryId][] = $txId;
            }
            foreach ($groupByCategory as $categoryId => $ids) {
                Transaction::query()->whereIn('id', $ids)->update(['category_id' => $categoryId]);
                $updated += count($ids);
            }
            $this->matcher->flushHits();
        }

        return [
            'matched' => $matched,
            'updated' => $updated,
            'by_rule' => array_values($byRule),
            'sample' => $sample,
        ];
    }
}
