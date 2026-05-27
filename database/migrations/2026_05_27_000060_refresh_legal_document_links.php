<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Refresh public links for the three legal documents that gate the new
 * partner acceptance flow:
 *
 *   #1 Публичная оферта       — accepted in cabinet after IP verification
 *   #2 Политика обработки ПД  — accepted on Step 1 of registration
 *   #3 Согласие на обработку  — accepted on Step 1 of registration
 *
 * Documents #4 (Стандарты) and #5 (Фото/видео) keep their existing
 * links — #4 is accepted as an annex to the Оферта, #5 is purely
 * informational and not part of the new flow.
 *
 * Old links are preserved in down() so the migration is reversible.
 */
return new class extends Migration
{
    private const NEW_LINKS = [
        1 => 'https://docs.google.com/document/d/13xayyrQ9xiQmjlj3mdWyEXS3eTFVFWBd/edit?usp=sharing',
        2 => 'https://docs.google.com/document/d/13RQ8dBpRXHBbPPyb0Axp6D3JDgCsJPP4/edit?usp=drivesdk',
        3 => 'https://docs.google.com/document/d/1qY9srYpwzcnSSZaddPEZnc5OTps_KwwA/edit?usp=drivesdk',
    ];

    private const OLD_LINKS = [
        1 => 'https://docs.google.com/document/d/16A8WLTNvlylcgJLGjD8NA1Vt3wJBb-ae/edit',
        2 => 'https://docs.google.com/document/d/1UUkyfFIR3H6RoWkN_AaWYKpPhdTL9H2-/edit',
        3 => 'https://docs.google.com/file/d/1N7OBxSF9GYY-j-RruxgsSOaAMFKUeUK-/edit?usp=docslist_api&filetype=msword',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('agreementPartnersDocuments')) {
            return;
        }
        foreach (self::NEW_LINKS as $id => $link) {
            DB::table('agreementPartnersDocuments')
                ->where('id', $id)
                ->update(['link' => $link]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('agreementPartnersDocuments')) {
            return;
        }
        foreach (self::OLD_LINKS as $id => $link) {
            DB::table('agreementPartnersDocuments')
                ->where('id', $id)
                ->update(['link' => $link]);
        }
    }
};
