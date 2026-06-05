<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Products that exist only in products_catalog (no legacy_product_id) —
        // insert FK anchors so the contract form can reference them.
        $products = [
            [101, 'ИИ ДС'],
            [102, 'Investors Trust'],
            [103, 'БРОКЕР+'],
            [104, 'ЗПИФ Акцент'],
            [105, 'ЗПИФ Парус'],
        ];

        foreach ($products as [$id, $name]) {
            DB::table('product')->insertOrIgnore([
                'id'                  => $id,
                'name'                => $name,
                'active'              => true,
                'publish_status'      => 'published',
                'visibleToResident'   => false,
                'visibleToCalculator' => false,
            ]);
        }

        DB::table('products_catalog')->where('id', 61)->update(['legacy_product_id' => 101]);
        DB::table('products_catalog')->where('id', 66)->update(['legacy_product_id' => 102]);
        DB::table('products_catalog')->where('id', 10)->update(['legacy_product_id' => 103]);
        DB::table('products_catalog')->where('id', 68)->update(['legacy_product_id' => 104]);
        DB::table('products_catalog')->where('id', 67)->update(['legacy_product_id' => 105]);
    }

    public function down(): void
    {
        DB::table('products_catalog')
            ->whereIn('id', [61, 66, 10, 68, 67])
            ->update(['legacy_product_id' => null]);

        DB::table('product')->whereIn('id', [101, 102, 103, 104, 105])->delete();
    }
};
