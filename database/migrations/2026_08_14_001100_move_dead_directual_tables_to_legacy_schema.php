<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Мёртвый слой Directual → схема `legacy`.
 *
 * 93 таблицы не упоминаются нигде в коде платформы (app/, resources/js,
 * routes/, database/, config/, socket-server/) — это остатки движка Directual:
 * telegram-бот (T*), конкурсы, staging-выписки провайдеров (bkc, unilife,
 * investorsTrust, roboadvisor, …), n8n/GetCourse-интеграции, *Trigger-очереди
 * и старый генератор месячных отчётов.
 *
 * Мы их НЕ удаляем — данные нужны как история до консолидации (июнь 2026).
 * Перенос в отдельную схему делает ровно одно: убирает их из `search_path`
 * (в config/database.php он равен 'public'), поэтому таблицы перестают
 * попадаться в автодополнении, в `\dt`, в Schema::hasTable() и в глазах
 * человека, который ищет «где лежат актуальные данные».
 *
 * Что при этом НЕ ломается:
 *   — внешние ключи работают между схемами, ничего не пересоздаём;
 *   — сиквенсы остаются в public и продолжают обслуживать свои колонки;
 *   — pg_dump/pg_restore берут обе схемы.
 *
 * down() возвращает таблицы обратно в public.
 *
 * ⚠ cache_locks намеренно НЕ трогаем: имя выглядит служебным и в коде не
 * встречается, но это рабочая таблица драйвера кэша Laravel.
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $tables = [
        'CryptoTransaction',
        'CryptoWallet',
        'GlobalVariables',
        'ImportInformation',
        'NearTransaction',
        'ResetPasswordRequest',
        'SmsLog',
        'SocialUser',
        'SystemMessage',
        'TChat',
        'TKeyboard',
        'TMessageIn',
        'TMessageOut',
        'TUser',
        'WebFlowAccess',
        'WebUserSession',
        'ZapierHook',
        'anderida',
        'assetsHistory',
        'backofficeregistration',
        'bkc',
        'calculationConsultantRaiting',
        'calculationContestTrigger',
        'calculationsConstant',
        'cbrResponse',
        'changeConsultantStructureTrigger',
        'changeContractDsCommisionTrigger',
        'clientGoalsTrigger',
        'clientsCounterHistory',
        'comissionByLevel',
        'commissionsReport',
        'consultantLevel',
        'consultantMotivationGroupLevel',
        'consultantStatusChangeMailing',
        'consultantStructure',
        'consultantsCounterHistory',
        'consultantsWithMissingLogs',
        'createImportTransaction',
        'cronMonthly',
        'cronPartnerCompressionDaily',
        'currencyRatesChangesTrigger',
        'exportLogConsultant',
        'exportLogContract',
        'exportLogQualificationLog',
        'exportLogTransactions',
        'firstBalances',
        'getCourseOrderWebHookData',
        'getCourseTransactionsFromGoogleSpreadsheetsWebHookData',
        'getcourseCreateResidentPromocodeDebit',
        'getcourseExportTransactionsData',
        'getcourseTransactionExportDataFromGoogleSpreadsheet',
        'hansardYearsCalcProperty',
        'ibCounter',
        'importTransactionLog',
        'importtransactionfromn8n',
        'insmartProduct',
        'insmartVender',
        'investorsTrust',
        'lastN8nSyncTimestam',
        'logExportClient',
        'logExportConsultant',
        'logExportContract',
        'logExportTransaction',
        'massTransactionRecalculationTrigger',
        'monthlyReportAvailabilityIndicator',
        'monthlyReports',
        'motivationGroupLevel',
        'nocomission',
        'objectForRequestScenario',
        'partnerMonthlyPaymentsReportMailing',
        'partnerMonthlyPaymentsReportTrigger',
        'poolTrigger',
        'privateEquity',
        'profitability',
        'qualificationCalculationsTrigger',
        'qualificationSavingTrigger',
        'roboadvisor',
        'statusImportTransaction',
        'test_connection_between_cons_and_team',
        'transactionDeleting',
        'transactionRecalculation',
        'triggerCron',
        'typeMailConsultantStatus',
        'type_criterion',
        'unactualBalances',
        'unactualQlogs',
        'unilife',
        'user_status_log',
        'usergroups',
        'vatChangesTrigger',
        'volumeCalculatorHistoryCleaner',
        'webHookInsmartError',
        'woodville',
    ];

    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS legacy');
        $this->move('public', 'legacy');
    }

    public function down(): void
    {
        $this->move('legacy', 'public');
    }

    private function move(string $from, string $to): void
    {
        foreach ($this->tables as $table) {
            $exists = DB::selectOne(
                'select 1 as x from pg_class c join pg_namespace n on n.oid = c.relnamespace
                 where n.nspname = ? and c.relname = ? and c.relkind = \'r\'',
                [$from, $table]
            );
            if (! $exists) {
                continue;
            }
            DB::statement(sprintf('ALTER TABLE %s."%s" SET SCHEMA %s', $from, $table, $to));
        }
    }
};
