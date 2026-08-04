<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Одноразовая операция: подготовить всех существующих партнёров к
 * закрытой регистрационной модели.
 *
 *  1. Сгенерировать participantCode всем consultant'ам у кого его нет
 *     (для активных партнёров → можно приглашать новых).
 *  2. Проставить consultant.soldProducts = все ID активных продуктов
 *     (формально «все тесты сданы», чтобы существующим партнёрам не
 *     надо было заново сдавать обучение по каждому продукту).
 *
 *  php artisan partners:bootstrap-onboarding
 *  php artisan partners:bootstrap-onboarding --dry-run
 *  php artisan partners:bootstrap-onboarding --only=codes
 *  php artisan partners:bootstrap-onboarding --only=tests
 */
class PartnersBootstrapOnboarding extends Command
{
    protected $signature = 'partners:bootstrap-onboarding
                            {--dry-run : Показать что будет изменено, без записи}
                            {--only= : Только одна часть: codes|tests}';

    protected $description = 'Backfill реф-кодов и метки «все тесты сданы» для существующих партнёров.';

    public function handle(): int
    {
        $only = (string) $this->option('only');
        $dry = (bool) $this->option('dry-run');

        if ($only === '' || $only === 'codes') {
            $this->backfillCodes($dry);
        }
        if ($only === '' || $only === 'tests') {
            $this->markTestsPassed($dry);
        }

        return self::SUCCESS;
    }

    private function backfillCodes(bool $dry): void
    {
        $this->info('--- 1. Реф-коды ---');

        $rows = DB::table('consultant')
            ->whereNull('dateDeleted')
            ->whereNotIn('activity', [3, 5]) // не Терминирован и не Исключён
            ->where(function ($q) {
                $q->whereNull('participantCode')->orWhere('participantCode', '');
            })
            ->select('id', 'personName')
            ->get();

        $this->line('  Партнёров без participantCode: ' . $rows->count());

        if ($dry) {
            $rows->take(10)->each(fn ($r) => $this->line("    [{$r->id}] {$r->personName}"));
            if ($rows->count() > 10) $this->line('    ... +' . ($rows->count() - 10) . ' more');
            return;
        }

        // Заранее загрузить все существующие коды чтобы не делать SELECT на
        // каждой генерации.
        $taken = DB::table('consultant')
            ->whereNotNull('participantCode')
            ->pluck('participantCode')
            ->mapWithKeys(fn ($c) => [$c => true])
            ->toArray();

        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $alphaLen = strlen($alphabet) - 1;

        $bar = $this->output->createProgressBar($rows->count());
        $updated = 0;
        $failed = 0;

        foreach ($rows as $row) {
            $code = null;
            for ($attempt = 0; $attempt < 50; $attempt++) {
                $candidate = '';
                for ($i = 0; $i < 6; $i++) {
                    $candidate .= $alphabet[random_int(0, $alphaLen)];
                }
                if (! isset($taken[$candidate])) {
                    $code = $candidate;
                    $taken[$candidate] = true;
                    break;
                }
            }
            if (! $code) {
                $failed++;
                $bar->advance();
                continue;
            }
            DB::table('consultant')->where('id', $row->id)->update(['participantCode' => $code]);
            $updated++;
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();
        $this->info("  ✓ Сгенерировано кодов: {$updated}" . ($failed ? " (failed: {$failed})" : ''));
    }

    private function markTestsPassed(bool $dry): void
    {
        $this->info('--- 2. Все тесты считаются сданными ---');

        // ProductController::index определяет «продукт доступен» через
        // education_course_completions: для каждого linked course должна
        // быть запись (user_id, course_id). Засеваем completion для всех
        // активных партнёров × всех активных курсов.

        $courseIds = DB::table('education_courses')
            ->where('active', true)
            ->pluck('id')
            ->all();

        if (! $courseIds) {
            $this->warn('  Нет активных education_courses — нечего сдавать.');
            return;
        }

        $this->line('  Активных курсов: ' . count($courseIds));

        // Берём webUser ID активных партнёров (completion привязан к user_id).
        $userIds = DB::table('consultant')
            ->whereNull('dateDeleted')
            ->whereNotIn('activity', [3, 5])
            ->whereNotNull('webUser')
            ->pluck('webUser')
            ->all();

        $this->line('  Партнёров с webUser: ' . count($userIds));

        // Уже существующие пары (user_id, course_id) — пропускаем.
        $existing = DB::table('education_course_completions')
            ->whereIn('user_id', $userIds)
            ->whereIn('course_id', $courseIds)
            ->select('user_id', 'course_id')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->user_id . ':' . $r->course_id => true])
            ->toArray();

        $now = now();
        $batch = [];
        foreach ($userIds as $uid) {
            foreach ($courseIds as $cid) {
                if (isset($existing[$uid . ':' . $cid])) continue;
                $batch[] = [
                    'user_id' => $uid,
                    'course_id' => $cid,
                    'score' => 100,
                    'total' => 100,
                    'completed_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        $this->line('  К вставке completion-записей: ' . count($batch));

        if ($dry) return;

        if ($batch) {
            // Chunk по 1000 для безопасности.
            foreach (array_chunk($batch, 1000) as $chunk) {
                DB::table('education_course_completions')->insert($chunk);
            }
        }
        $this->info('  ✓ Вставлено completion-записей: ' . count($batch));
    }
}
