<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Монитор инварианта client.person: ФИО связанной person должно совпадать с
 * client.personName. У client нет своих контактов — почта/телефон/ДР/город
 * берутся ТОЛЬКО из person, поэтому «валидный, но ЧУЖОЙ» указатель тихо
 * подставляет клиенту чужие данные (инцидент 2026-07-09, выровнено 6886).
 *
 * Приложение свои пути записи держит консистентными (storeClient создаёт свою
 * person, updateClient правит связанную in-place), рассинхрон приходит извне
 * (переномерация person при миграциях). Эта команда ловит его рано: считает
 * рассинхрон и пишет WARNING в лог (→ Sentry-брейдкрамб/алерт). --fix
 * делегирует выравнивание команде clients:realign-person.
 */
class CheckClientPersonDrift extends Command
{
    protected $signature = 'clients:check-person-drift
        {--fix : запустить clients:realign-person для однозначных совпадений}';

    protected $description = 'Проверить инвариант client.person = ФИО (контакты клиентов не чужие)';

    /** Живые клиенты, где ФИО связанной person != personName. */
    private const DRIFT_SQL = <<<'SQL'
        SELECT count(*) AS drift
        FROM client cl
        JOIN person p ON p.id = cl.person
        WHERE cl."dateDeleted" IS NULL
          AND cl."personName" IS NOT NULL
          AND btrim(lower(p."lastName" || ' ' || p."firstName" || ' ' || coalesce(p.patronymic,'')))
            <> btrim(lower(cl."personName"))
        SQL;

    public function handle(): int
    {
        $drift = (int) (DB::selectOne(self::DRIFT_SQL)->drift ?? 0);
        // Клиенты вообще без person (контакты недоступны) — отдельная категория.
        $noPerson = (int) DB::table('client')
            ->whereNull('dateDeleted')
            ->whereNull('person')
            ->count();

        if ($drift === 0) {
            $this->info("client.person OK: рассинхрона ФИО нет (без person: {$noPerson}).");
            return self::SUCCESS;
        }

        $this->warn("⚠ Рассинхрон client.person: {$drift} клиентов с чужим ФИО person (без person: {$noPerson}).");
        Log::warning('client.person drift detected', ['drift' => $drift, 'no_person' => $noPerson]);

        if ($this->option('fix')) {
            $this->line('Запускаю clients:realign-person…');
            $this->call('clients:realign-person');
        } else {
            $this->line('Запусти `clients:realign-person` для выравнивания однозначных совпадений.');
        }

        return self::SUCCESS;
    }
}
