<?php

namespace App\Domains\Catalog\Support;

use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Models\SizeGuideTemplate;
use App\Shared\StateMachine\TransitionRefused;
use Illuminate\Support\Str;

/**
 * Size-guide template engine (MP v2 §14b, Boksha audit).
 *
 * Types: `apparel_alpha` (size letters × body measurements), `shoes`
 * (EU / UK / US / foot length), `abaya_height_length` (height bands in cm →
 * abaya length and sleeve in inches) and `custom`. Resolution: the
 * product's own template → the seller's template for the nearest category
 * → the platform template for the nearest category. Measurement columns
 * switch between cm and inches; "Find my size" picks the abaya row for a
 * height given in cm or feet.
 */
class SizeGuides
{
    public const TYPES = ['apparel_alpha', 'abaya_height_length', 'shoes', 'custom'];

    /** Columns that are labels / size systems, never converted. */
    private const LABEL_COLUMNS = ['size', 'eu', 'uk', 'us', 'label'];

    public function resolve(Product $product, ?int $vendorId = null): ?SizeGuideTemplate
    {
        if ($product->size_guide_template_id !== null) {
            $own = SizeGuideTemplate::query()->where('is_active', true)->find($product->size_guide_template_id);

            if ($own !== null) {
                return $own;
            }
        }

        $lineage = array_map(fn (Category $category): int => $category->id, $product->category?->lineage() ?? []);

        foreach ($lineage as $categoryId) {
            $template = SizeGuideTemplate::query()->where('is_active', true)->where('category_id', $categoryId)
                ->where(fn ($query) => $query->whereNull('vendor_id')->when($vendorId !== null, fn ($vendor) => $vendor->orWhere('vendor_id', $vendorId)))
                ->orderByRaw('vendor_id IS NULL')->first();

            if ($template !== null) {
                return $template;
            }
        }

        return null;
    }

    /**
     * The table in the wanted unit (cm | in) for measurement columns.
     *
     * `columns` are the row keys in order (plain strings); `column_defs`
     * (G-W17) describes each: {key, label, unit (null for label columns),
     * is_label}. Each row is an object keyed by those keys.
     *
     * @return array{id: int, name: string, type: string, unit: string, columns: array<int, string>, column_defs: array<int, array{key: string, label: string, unit: string|null, is_label: bool}>, rows: array<int, array<string, mixed>>, notes: string|null}
     */
    public function render(SizeGuideTemplate $template, ?string $unit = null): array
    {
        $unit = in_array($unit, ['cm', 'in'], true) ? $unit : $template->unit;
        $factor = match (true) {
            $template->type === 'abaya_height_length' => 1.0,
            $template->unit === $unit => 1.0,
            $unit === 'in' => 1 / 2.54,
            default => 2.54,
        };
        $rows = array_map(function (array $row) use ($factor): array {
            foreach ($row as $column => $value) {
                if (is_numeric($value) && ! in_array($column, self::LABEL_COLUMNS, true) && $factor !== 1.0) {
                    $row[$column] = round((float) $value * $factor, 1);
                }
            }

            return $row;
        }, $template->rows ?? []);

        return [
            'id' => $template->id,
            'name' => $template->name,
            'type' => $template->type,
            'unit' => $template->type === 'abaya_height_length' ? 'in' : $unit,
            'columns' => $template->columns ?? [],
            'column_defs' => array_values(array_map(function (mixed $column) use ($template, $unit): array {
                $key = is_array($column) ? (string) ($column['key'] ?? '') : (string) $column;
                $isLabel = in_array($key, self::LABEL_COLUMNS, true);

                return [
                    'key' => $key,
                    'label' => is_array($column) && isset($column['label']) ? (string) $column['label'] : Str::headline($key),
                    'unit' => $isLabel ? null : ($template->type === 'abaya_height_length' ? 'in' : $unit),
                    'is_label' => $isLabel,
                ];
            }, $template->columns ?? [])),
            'rows' => $rows,
            'notes' => $template->notes,
        ];
    }

    /**
     * "Find my size" for abayas: height (cm, or feet as 5.4 = 5 ft 4 in)
     * → the band's length and sleeve in inches.
     *
     * @return array{height_cm: float, length_in: float, sleeve_in: float|null}
     */
    public function abayaFor(SizeGuideTemplate $template, float $height, string $unit = 'cm'): array
    {
        if ($template->type !== 'abaya_height_length') {
            throw new TransitionRefused(__('This size guide has no height finder.'));
        }

        $heightCm = $unit === 'ft' ? $this->feetToCm($height) : $height;

        foreach ($template->rows ?? [] as $row) {
            if ($heightCm >= (float) $row['height_min'] && $heightCm <= (float) $row['height_max']) {
                return ['height_cm' => round($heightCm, 1), 'length_in' => (float) $row['length'], 'sleeve_in' => isset($row['sleeve']) ? (float) $row['sleeve'] : null];
            }
        }

        throw new TransitionRefused(__('No size for this height; choose made to order with your measurements.'));
    }

    /**
     * 5.4 → 5 ft 4 in → 162.6 cm.
     */
    public function feetToCm(float $feet): float
    {
        $whole = (int) floor($feet);
        $inches = (int) round(($feet - $whole) * 10);

        return round(($whole * 12 + $inches) * 2.54, 1);
    }
}
