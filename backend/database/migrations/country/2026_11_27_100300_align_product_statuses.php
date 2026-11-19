<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * QC-016: product states follow catalogue §1.2 (draft, active, restricted,
 * discontinued). The wave-2 `inactive` (admin switched off) becomes
 * `restricted`, which can be reactivated.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('products')->where('status', 'inactive')->update(['status' => 'restricted']);
    }

    public function down(): void
    {
        DB::table('products')->where('status', 'restricted')->update(['status' => 'inactive']);
    }
};
