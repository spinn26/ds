<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Разовая чистка протухших пометок «скрыть отчёты».
 *
 * С 2026-08-17 явная пометка видимости главнее закрытия периода
 * (PeriodVisibilityService). До этого действовало обратное правило, и под ним
 * накопились записи, которые админ ставил ВРЕМЕННО — на период пересчёта, — а
 * снимать не стал: закрытие месяца их и так перекрывало. Без чистки смена
 * правила молча спрятала бы у партнёров закрытые апрель/май 2026.
 *
 * Чистим узко: только is_visible=false, только у закрытых (и не переоткрытых)
 * периодов и только пометки СТАРШЕ 2026-07-01 — то есть заведомо оставленные
 * под старым правилом. Свежие пометки (напр. скрытый на время сверки июль)
 * не трогаем: это осознанное решение владельца, оно и должно сработать.
 */
return new class extends Migration
{
    private const BOUNDARY = '2026-07-01 00:00:00';

    public function up(): void
    {
        if (! Schema::hasTable('period_visibility') || ! Schema::hasTable('period_closures')) {
            return;
        }

        DB::table('period_visibility')
            ->whereIn('id', $this->staleIds())
            ->update([
                'is_visible' => true,
                'changed_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * Откат намеренно пустой: миграция снимает ВРЕМЕННЫЕ пометки, которые под
     * старым правилом всё равно ни на что не влияли (закрытый период
     * показывался партнёрам). Автоматически «вернуть скрытие» нельзя, не
     * отличив эти месяцы от тех, что админ открыл сам, — а угадывать здесь
     * значит спрятать от партнёров закрытые отчёты. Нужно скрыть конкретный
     * месяц — кнопка «Сделать недоступным» на /admin/periods.
     */
    public function down(): void
    {
        //
    }

    /** @return array<int,int> id пометок «скрыто», протухших под старым правилом. */
    private function staleIds(): array
    {
        return DB::table('period_visibility as v')
            ->join('period_closures as c', function ($j) {
                $j->on('c.year', '=', 'v.year')->on('c.month', '=', 'v.month');
            })
            ->whereNull('c.reopened_at')
            ->where('v.is_visible', false)
            ->where('v.changed_at', '<', self::BOUNDARY)
            ->pluck('v.id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

};
