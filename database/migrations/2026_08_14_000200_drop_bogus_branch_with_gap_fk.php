<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Снятие ошибочного внешнего ключа qualificationLog.branchWithGap.
 *
 * Колонка хранит id КОНСУЛЬТАНТА — ветку первой линии, попавшую под отрыв.
 * Так её пишет MonthlyPenaltyRunner и так же читают оба потребителя:
 * DashboardService и FinanceReportService резолвят имя через
 * `consultant.where('id', $branchId)`.
 *
 * А внешний ключ, доставшийся от Directual, ссылается на саму
 * "qualificationLog"(id):
 *
 *     FOREIGN KEY ("branchWithGap") REFERENCES "qualificationLog"(id) DEFERRABLE
 *
 * На проде это до сих пор проходило ПО СОВПАДЕНИЮ: id консультантов не
 * превышают ~2 100, а id журнала идут от 1 до 54 566, поэтому почти любой
 * id консультанта существует и как id записи журнала. Проверка молча
 * выполнялась, сохраняя бессмысленную ссылку. Но стоит финализации выбрать
 * ветку с id, которого в журнале нет, — и penalty-строка не запишется, а
 * вместе с ней откатится вся транзакция обработки партнёра.
 *
 * Новый FK на consultant НЕ ставим: из 1 789 исторических строк с
 * заполненным branchWithGap лишь 93 совпадают с существующими id
 * консультантов — остальные унаследованы от Directual и ссылались на журнал.
 * Такое ограничение не прошло бы валидацию на существующих данных.
 */
return new class extends Migration
{
    private const CONSTRAINT = 'qualificationLog_branchWithGap_fkey';

    public function up(): void
    {
        if (! $this->constraintExists()) {
            return;
        }

        DB::statement(
            'ALTER TABLE "qualificationLog" DROP CONSTRAINT "' . self::CONSTRAINT . '"'
        );
    }

    public function down(): void
    {
        if ($this->constraintExists()) {
            return;
        }

        // Возвращаем ровно то, что было: ссылку на сам журнал, DEFERRABLE.
        DB::statement(
            'ALTER TABLE "qualificationLog" ADD CONSTRAINT "' . self::CONSTRAINT . '"'
            . ' FOREIGN KEY ("branchWithGap") REFERENCES "qualificationLog"(id) DEFERRABLE'
        );
    }

    private function constraintExists(): bool
    {
        return DB::selectOne(
            'SELECT 1 FROM pg_constraint WHERE conname = ?',
            [self::CONSTRAINT]
        ) !== null;
    }
};
