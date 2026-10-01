<?php

namespace App\Support;

use App\Models\Category;

/**
 * Coerenza tra tipo di transazione (o ricorrente) e categoria: un giroconto non ha
 * categoria, un'entrata o un'uscita usa solo categorie dello stesso tipo.
 */
class CategoryTypeCheck
{
    private const LABELS = ['income' => 'entrata', 'expense' => 'uscita'];

    public static function error(mixed $categoryId, ?string $type): ?string
    {
        if ($categoryId === null || $categoryId === '' || $type === null) {
            return null;
        }

        if ($type === 'transfer') {
            return 'Un giroconto non ha categoria.';
        }

        $category = Category::query()->whereKey($categoryId)->first(['name', 'type']);
        if ($category === null || $category->type === $type) {
            return null;
        }

        $categoryLabel = self::LABELS[$category->type] ?? $category->type;
        $typeLabel = $type === 'income' ? "un'entrata" : "un'uscita";

        return "La categoria «{$category->name}» è di {$categoryLabel}: non si può usare per {$typeLabel}.";
    }
}
