@props(['status'])

@php
    $value = $status instanceof \BackedEnum ? $status->value : (string) $status;
    $tone = match ($value) {
        'active', 'delivered', 'completed', 'approved', 'live', 'shipped', 'confirmed', 'settled', 'redeemed', 'on_sale', 'paid', 'fulfilled', 'won', 'sold' => 'success',
        'pending_review', 'placed', 'submitted', 'under_review', 'submission_open', 'processing', 'packed', 'pending', 'requested', 'inspecting', 'held', 'pending_documents', 'pending_contract', 'almost_full', 'in_progress', 'open', 'approved_pending_payment', 'budget_exhausted', 'reviewing' => 'warning',
        'rejected', 'cancelled', 'out_of_stock', 'refunded', 'returned', 'sold_out', 'failed', 'disputed', 'on_hold' => 'danger',
        'scheduled', 'closed', 'out_for_delivery', 'partially_shipped', 'partially_delivered', 'submission_closed', 'awaiting_pickup', 'in_transit', 'received' => 'info',
        default => 'neutral',
    };
@endphp

<x-ui.badge :tone="$tone" {{ $attributes }}>{{ str_replace('_', ' ', ucfirst($value)) }}</x-ui.badge>
