<?php

namespace App\Domains\Campaigns\Support;

use App\Domains\Campaigns\Models\CampaignFeeRule;
use App\Domains\Catalog\Models\Category;
use App\Shared\Money\MoneyFactory;
use Brick\Money\Money;

/**
 * Campaign fee quote (SRS §11.10), computed server-side only:
 *
 *   base         = Max Commission Budget (unlimited: influencers' fixed total)
 *   fixed_fee    = fixed amount(country) + base × fixed %(country)
 *   variable_fee = amount(country, category, fulfilment) + base × %(…)
 *   fee          = fixed_fee + variable_fee     (no VAT, half-up)
 *   grand total  = base + fee
 *
 * The variable row is the category's (then its parents', then the
 * category-less row) matching the listing fulfilment, else any fulfilment.
 */
class CampaignFeeQuote
{
    public function __construct(private MoneyFactory $money) {}

    /**
     * @return array{base: string, fixed_fee: string, variable_fee: string, fee: string, grand_total: string, fixed_rule: int|null, variable_rule: int|null, fulfilment: string, category_id: int|null}
     */
    public function quote(string $currency, ?Category $category, string $fulfilment, ?Money $budget, Money $influencerFixedTotal): array
    {
        $base = $budget ?? $influencerFixedTotal;
        $fixedRule = CampaignFeeRule::query()->where('kind', CampaignFeeRule::KIND_FIXED)->orderBy('id')->first();
        $variableRule = $this->variableRule($category, $fulfilment);

        $fixedFee = $this->part($fixedRule, $base, $currency);
        $variableFee = $this->part($variableRule, $base, $currency);
        $fee = $fixedFee->plus($variableFee);

        return [
            'base' => (string) $base->getAmount(),
            'fixed_fee' => (string) $fixedFee->getAmount(),
            'variable_fee' => (string) $variableFee->getAmount(),
            'fee' => (string) $fee->getAmount(),
            'grand_total' => (string) $base->plus($fee)->getAmount(),
            'fixed_rule' => $fixedRule?->id,
            'variable_rule' => $variableRule?->id,
            'fulfilment' => $fulfilment,
            'category_id' => $category?->id,
        ];
    }

    private function part(?CampaignFeeRule $rule, Money $base, string $currency): Money
    {
        if ($rule === null) {
            return $this->money->zero($currency);
        }

        return $this->money->of((string) $rule->amount, $currency)->plus($this->money->percentageOf($base, (string) $rule->percent));
    }

    private function variableRule(?Category $category, string $fulfilment): ?CampaignFeeRule
    {
        $rules = CampaignFeeRule::query()->where('kind', CampaignFeeRule::KIND_VARIABLE)->get();
        $categoryIds = array_map(fn (Category $ancestor): int => $ancestor->id, $category?->lineage() ?? []);
        $categoryIds[] = null;

        foreach ($categoryIds as $categoryId) {
            $rows = $rules->where('category_id', $categoryId);
            $match = $rows->firstWhere('fulfilment', $fulfilment) ?? $rows->firstWhere('fulfilment', null);

            if ($match !== null) {
                return $match;
            }
        }

        return null;
    }
}
