<?php

namespace App\Domains\Catalog\Support;

use App\Domains\Catalog\Enums\ProductStatus;
use App\Domains\Catalog\Models\Product;
use App\Domains\Orders\Enums\ActorType;
use App\Shared\StateMachine\StringStatusMachine;
use App\Shared\StateMachine\TransitionRefused;

/**
 * Product lifecycle (catalogue §1.2; QC-016). Guards: activating needs a
 * category and at least one variant; restricting needs a reason. Products
 * that are not `active` already drop out of search, product pages and offer
 * approval, so their listings pause with them.
 */
class ProductStateMachine
{
    public function __construct(private StringStatusMachine $machine) {}

    public function move(Product $product, ProductStatus $to, ActorType $actorType, ?int $actorUserId, ?string $reason = null): Product
    {
        if ($to === ProductStatus::Active && ($product->category_id === null || ! $product->variants()->exists())) {
            throw new TransitionRefused(__('A product needs a category and at least one variant before it goes active.'));
        }

        if ($to === ProductStatus::Restricted && mb_strlen(trim((string) $reason)) < 3) {
            throw new TransitionRefused(__('Give the legal or compliance reason for restricting this product.'));
        }

        return $this->machine->move($product, ProductStatus::class, $to, $actorType, $actorUserId, $reason);
    }
}
