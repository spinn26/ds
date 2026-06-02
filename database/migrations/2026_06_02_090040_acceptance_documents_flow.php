<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reshape the acceptance-document set (2026-06-02) to exactly the documents
 * the partner signs in the flow:
 *
 *   #2 Политика обработки ПД   — registration step 1
 *   #3 Согласие на обработку   — registration step 1
 *   #1 Публичная оферта        — cabinet, after IP verification
 *   #6 ПЭП-подтверждение       — cabinet, accepted together with the Оферта
 *                                ("заключение договора в эл. форме + простая
 *                                электронная подпись"); has no external link.
 *
 * #4 Стандарты и #5 Фото/видео are removed from the mandatory flow (kept in
 * the table for history but flagged in_acceptance_flow = false, so they no
 * longer appear in the admin ledger, the profile list, or the filter).
 *
 * Links for #1/#2/#3 are (re)applied here idempotently so prod ends in the
 * correct state regardless of whether 2026_05_27_000060 ran.
 */
return new class extends Migration
{
    private const LINKS = [
        1 => 'https://docs.google.com/document/d/13xayyrQ9xiQmjlj3mdWyEXS3eTFVFWBd/edit?usp=sharing',
        2 => 'https://docs.google.com/document/d/13RQ8dBpRXHBbPPyb0Axp6D3JDgCsJPP4/edit?usp=drivesdk',
        3 => 'https://docs.google.com/document/d/1qY9srYpwzcnSSZaddPEZnc5OTps_KwwA/edit?usp=drivesdk',
    ];

    private const PEP_ID = 6;
    private const PEP_NAME = 'Подтверждение заключения договора в электронной форме (простая электронная подпись)';

    public function up(): void
    {
        if (! Schema::hasTable('agreementPartnersDocuments')) {
            return;
        }

        if (! Schema::hasColumn('agreementPartnersDocuments', 'in_acceptance_flow')) {
            Schema::table('agreementPartnersDocuments', function ($table) {
                $table->boolean('in_acceptance_flow')->default(true);
            });
        }

        foreach (self::LINKS as $id => $link) {
            DB::table('agreementPartnersDocuments')->where('id', $id)->update(['link' => $link]);
        }

        DB::table('agreementPartnersDocuments')->whereIn('id', [1, 2, 3])->update(['in_acceptance_flow' => true]);
        DB::table('agreementPartnersDocuments')->whereIn('id', [4, 5])->update(['in_acceptance_flow' => false]);

        // ПЭП — отдельный акцептуемый пункт без внешней ссылки. Таблица без
        // sequence на id, поэтому вставляем явный id.
        DB::table('agreementPartnersDocuments')->updateOrInsert(
            ['id' => self::PEP_ID],
            [
                'number' => self::PEP_ID,
                'name' => self::PEP_NAME,
                'link' => null,
                'in_acceptance_flow' => true,
            ],
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('agreementPartnersDocuments')) {
            return;
        }

        DB::table('agreementPartnersDocuments')->where('id', self::PEP_ID)->delete();

        if (Schema::hasColumn('agreementPartnersDocuments', 'in_acceptance_flow')) {
            Schema::table('agreementPartnersDocuments', function ($table) {
                $table->dropColumn('in_acceptance_flow');
            });
        }
    }
};
