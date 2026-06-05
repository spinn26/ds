<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Catalog product_id → legacy product.id
    private const PRODUCT_MAP = [
        61 => 101, // ИИ ДС
        66 => 102, // Investors Trust
        10 => 103, // БРОКЕР+
        68 => 104, // ЗПИФ Акцент
        67 => 105, // ЗПИФ Парус
    ];

    public function up(): void
    {
        $startId = DB::table('program')->max('id') + 1;

        $rows = DB::table('programs_catalog as g')
            ->whereIn('g.product_id', array_keys(self::PRODUCT_MAP))
            ->where('g.active', true)
            ->orderBy('g.product_id')
            ->orderBy('g.name')
            ->select(['g.name', 'g.product_id'])
            ->get();

        $insert = [];
        foreach ($rows as $i => $r) {
            $insert[] = [
                'id'      => $startId + $i,
                'name'    => $r->name,
                'product' => self::PRODUCT_MAP[$r->product_id],
                'active'  => true,
            ];
        }

        if ($insert) {
            DB::table('program')->insert($insert);
        }
    }

    public function down(): void
    {
        DB::table('program')
            ->whereIn('product', array_values(self::PRODUCT_MAP))
            ->delete();
    }
};
