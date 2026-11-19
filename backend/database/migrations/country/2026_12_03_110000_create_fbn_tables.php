<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fulfilled by Nawy (MASTER_PLAN §8 "FBN inbound and storage, transfers"):
 * warehouses, warehouse stock per listing (on hand / reserved / damaged)
 * with an append-only movement log, order reservations, inbound shipments
 * (ASN), transfers, removal orders and the daily storage snapshots that
 * the monthly storage invoices are built from. The listing's own stock
 * ledger (`inventory_movements`) stays the sellable quantity; for an FBN
 * listing it always equals the warehouses' available units.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->string('code', 16)->unique();
            $table->string('name_en');
            $table->string('name_ar');
            $table->string('address')->nullable();
            $table->string('city', 80)->nullable();
            $table->foreignId('shipping_zone_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('capacity_cubic_feet', 12, 3)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('over_capacity_since')->nullable();
            $table->timestamps();
        });

        Schema::create('warehouse_stock', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained();
            $table->foreignId('listing_id')->constrained();
            $table->unsignedInteger('on_hand')->default(0);
            $table->unsignedInteger('reserved')->default(0);
            $table->unsignedInteger('damaged')->default(0);
            $table->string('bin_code', 32)->nullable();
            $table->timestamp('first_received_at')->nullable();
            $table->timestamp('damaged_since')->nullable();
            $table->timestamp('long_term_flagged_at')->nullable();
            $table->timestamp('unsellable_flagged_at')->nullable();
            $table->timestamps();

            $table->unique(['warehouse_id', 'listing_id']);
            $table->index('listing_id');
        });

        Schema::create('warehouse_stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained();
            $table->foreignId('listing_id')->constrained();
            $table->string('bucket', 12);
            $table->integer('quantity');
            $table->string('reason', 40);
            $table->string('reference_type', 48)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('note')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['listing_id', 'created_at']);
            $table->index(['warehouse_id', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
        });

        Schema::create('fbn_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained();
            $table->foreignId('sub_order_id')->constrained();
            $table->foreignId('listing_id')->constrained();
            $table->foreignId('warehouse_id')->constrained();
            $table->unsignedInteger('quantity');
            $table->string('status', 16)->default('reserved');
            $table->timestamps();

            $table->index(['sub_order_id', 'status']);
            $table->index(['order_item_id', 'status']);
        });

        Schema::create('fbn_inbound_shipments', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->nullable()->unique();
            $table->foreignId('vendor_id')->constrained();
            $table->foreignId('warehouse_id')->constrained();
            $table->string('status', 16)->default('draft');
            $table->unsignedSmallInteger('cartons')->default(1);
            $table->date('expected_at')->nullable();
            $table->string('carrier_name', 80)->nullable();
            $table->string('tracking_number', 80)->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('arrived_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamps();

            $table->index(['vendor_id', 'status']);
            $table->index(['warehouse_id', 'status']);
        });

        Schema::create('fbn_inbound_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fbn_inbound_shipment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('listing_id')->constrained();
            $table->unsignedInteger('quantity_expected');
            $table->unsignedSmallInteger('cartons')->default(1);
            $table->unsignedInteger('quantity_received')->default(0);
            $table->unsignedInteger('quantity_damaged')->default(0);
            $table->string('bin_code', 32)->nullable();
            $table->timestamps();

            $table->unique(['fbn_inbound_shipment_id', 'listing_id']);
        });

        Schema::create('fbn_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->nullable()->unique();
            $table->foreignId('from_warehouse_id')->constrained('warehouses');
            $table->foreignId('to_warehouse_id')->constrained('warehouses');
            $table->string('status', 16)->default('requested');
            $table->string('note')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
        });

        Schema::create('fbn_transfer_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fbn_transfer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('listing_id')->constrained();
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('quantity_received')->default(0);
            $table->timestamps();
        });

        Schema::create('fbn_removal_orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->nullable()->unique();
            $table->foreignId('vendor_id')->constrained();
            $table->foreignId('warehouse_id')->constrained();
            $table->string('type', 16)->default('return');
            $table->string('status', 16)->default('requested');
            $table->json('address')->nullable();
            $table->string('currency', 3);
            $table->decimal('fee', 19, 4)->default(0);
            $table->string('reason')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['vendor_id', 'status']);
        });

        Schema::create('fbn_removal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fbn_removal_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('listing_id')->constrained();
            $table->string('source', 12)->default('sellable');
            $table->unsignedInteger('quantity');
            $table->timestamps();
        });

        Schema::create('fbn_storage_snapshots', function (Blueprint $table) {
            $table->id();
            $table->date('snapshot_on');
            $table->foreignId('vendor_id')->constrained();
            $table->foreignId('warehouse_id')->constrained();
            $table->foreignId('listing_id')->constrained();
            $table->unsignedInteger('units');
            $table->unsignedInteger('damaged_units');
            $table->unsignedInteger('long_term_units')->default(0);
            $table->unsignedInteger('unsellable_units')->default(0);
            $table->decimal('unit_cubic_feet', 12, 6);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['snapshot_on', 'warehouse_id', 'listing_id']);
            $table->index(['vendor_id', 'snapshot_on']);
        });

        Schema::create('fbn_storage_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->nullable()->unique();
            $table->foreignId('vendor_id')->constrained();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('currency', 3);
            $table->decimal('storage_cubic_feet', 14, 4)->default(0);
            $table->decimal('long_term_cubic_feet', 14, 4)->default(0);
            $table->decimal('unsellable_cubic_feet', 14, 4)->default(0);
            $table->decimal('storage_fee', 19, 4)->default(0);
            $table->decimal('long_term_fee', 19, 4)->default(0);
            $table->decimal('unsellable_fee', 19, 4)->default(0);
            $table->decimal('total', 19, 4)->default(0);
            $table->json('lines')->nullable();
            $table->timestamp('charged_at')->nullable();
            $table->timestamps();

            $table->unique(['vendor_id', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fbn_storage_invoices');
        Schema::dropIfExists('fbn_storage_snapshots');
        Schema::dropIfExists('fbn_removal_lines');
        Schema::dropIfExists('fbn_removal_orders');
        Schema::dropIfExists('fbn_transfer_lines');
        Schema::dropIfExists('fbn_transfers');
        Schema::dropIfExists('fbn_inbound_lines');
        Schema::dropIfExists('fbn_inbound_shipments');
        Schema::dropIfExists('fbn_reservations');
        Schema::dropIfExists('warehouse_stock_movements');
        Schema::dropIfExists('warehouse_stock');
        Schema::dropIfExists('warehouses');
    }
};
