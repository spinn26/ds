<?php

namespace Tests\Feature\Characterization;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Индексы на самоссылки qualificationLog.
 *
 * ⚠ Это не «индекс ради скорости», а условие работоспособности финализации.
 * У таблицы два внешних ключа НА СЕБЯ — qualificationLogPrevious и
 * firstLineBranches. Без индексов по ним Postgres на каждое удаление строки
 * проверяет ссылки полным проходом по таблице, и массовое удаление снимков
 * (около 1600 партнёров за раз) упирается в statement timeout.
 *
 * Именно так 19.08.2026 падала кнопка «Пересчитать штрафы», и так же
 * оборвался прогон 17.08, из-за которого НГП у 147 партнёров остался
 * июньским. Тест сторожит, чтобы индексы не потерялись при переносе схемы.
 */
class QualificationLogIndexesTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<array{0: string, 1: string}> */
    public static function selfReferences(): array
    {
        return [
            'предыдущая запись' => ['qualificationLogPrevious', 'qualificationlog_prev_idx'],
            'ветки первой линии' => ['firstLineBranches', 'qualificationlog_firstline_idx'],
        ];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('selfReferences')]
    public function a_self_reference_is_indexed(string $column, string $index): void
    {
        $exists = DB::table('pg_indexes')
            ->where('tablename', 'qualificationLog')
            ->where('indexname', $index)
            ->exists();

        $this->assertTrue($exists,
            "Нет индекса {$index} по самоссылке {$column}: массовое удаление снимков "
            . 'упрётся в таймаут, и финализация месяца перестанет применяться.');
    }

    /**
     * Удаление пачки снимков не должно упираться в проверку ссылок. Здесь
     * данных мало и таймаут не воспроизвести, поэтому проверяем факт: запрос
     * того же вида отрабатывает и ничего не роняет.
     */
    #[Test]
    public function a_bulk_snapshot_delete_works(): void
    {
        DB::table('consultant')->insert([
            'id' => 3700001, 'personName' => 'Индексный Партнёр',
            'activity' => 1, 'dateCreated' => '2026-01-01 00:00:00',
        ]);
        DB::table('qualificationLog')->insert([
            'id' => 3700100, 'consultant' => 3700001,
            'date' => '2026-07-31 23:59:59',
            'personalVolume' => 1, 'groupVolume' => 1, 'groupVolumeCumulative' => 1,
            'createdAt' => now(),
        ]);

        $deleted = DB::table('qualificationLog')
            ->whereIn('consultant', [3700001])
            ->where('date', '2026-07-31 23:59:59')
            ->delete();

        $this->assertSame(1, $deleted);
    }
}
