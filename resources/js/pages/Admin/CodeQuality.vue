<template>
  <div class="pa-4">
    <PageHeader title="Качество кода"
      subtitle="Полный аудит системы: статический анализ + ручное код-ревью по подсистемам. Отчёт от 08.07.2026." />

    <!-- Сводка по важности -->
    <v-row class="mb-2" dense>
      <v-col v-for="s in severityOrder" :key="s" cols="6" sm="3">
        <v-card variant="tonal" :color="sevColor(s)" class="pa-3">
          <div class="text-h4 font-weight-bold" style="font-variant-numeric:tabular-nums">
            {{ countBySeverity[s] || 0 }}
          </div>
          <div class="text-body-2">{{ sevLabel(s) }}</div>
        </v-card>
      </v-col>
    </v-row>

    <!-- Автоматические инструменты -->
    <v-row class="mb-4" dense>
      <v-col cols="12" md="4">
        <v-card variant="outlined" class="pa-3 h-100">
          <div class="d-flex align-center ga-2 mb-1">
            <v-icon color="warning">mdi-code-braces</v-icon>
            <span class="text-subtitle-2 font-weight-bold">PHPStan / larastan</span>
          </div>
          <div class="text-body-2 text-medium-emphasis">
            ~40 замечаний сверх baseline (level 5). Большинство мелкие:
            <strong>13</strong> лишних nullsafe, <strong>7</strong> ложных property.notFound
            (larastan не читает casts() на json-колонках — не трогать).
            Реальные: unset($F) в PartnerSalesMatrix, isset.offset, in_array-тип.
          </div>
        </v-card>
      </v-col>
      <v-col cols="12" md="4">
        <v-card variant="outlined" class="pa-3 h-100">
          <div class="d-flex align-center ga-2 mb-1">
            <v-icon color="info">mdi-format-paint</v-icon>
            <span class="text-subtitle-2 font-weight-bold">Pint (стиль)</span>
          </div>
          <div class="text-body-2 text-medium-emphasis">
            <strong>306</strong> файлов с отклонениями от стиля (отступы, пробелы,
            blank_line_before_statement). Не ошибки — косметика. Фикс:
            <code>vendor/bin/pint</code> одним прогоном (отдельным коммитом).
          </div>
        </v-card>
      </v-col>
      <v-col cols="12" md="4">
        <v-card variant="outlined" class="pa-3 h-100">
          <div class="d-flex align-center ga-2 mb-1">
            <v-icon color="error">mdi-test-tube</v-icon>
            <span class="text-subtitle-2 font-weight-bold">Тесты</span>
          </div>
          <div class="text-body-2 text-medium-emphasis">
            <strong>61 из 62</strong> падают локально — не из-за кода, а из-за
            подключения к БД (newds_test / пароль postgres). CI не может гейтить
            регрессии. Приоритет: починить тестовую БД (см. находку INFRA-1).
          </div>
        </v-card>
      </v-col>
    </v-row>

    <!-- Фильтры -->
    <v-card class="mb-4 pa-3" variant="tonal">
      <div class="d-flex align-center flex-wrap ga-3">
        <v-select v-model="filterCategory" :items="categories" label="Категория"
          density="compact" variant="outlined" hide-details clearable multiple chips
          style="min-width:260px;max-width:420px" prepend-inner-icon="mdi-filter-variant" />
        <v-select v-model="filterSeverity" :items="severitySelect" label="Важность"
          density="compact" variant="outlined" hide-details clearable multiple chips
          style="min-width:220px" />
        <v-text-field v-model="search" placeholder="Поиск по тексту/файлу"
          density="compact" variant="outlined" hide-details clearable
          prepend-inner-icon="mdi-magnify" style="min-width:220px" />
        <v-spacer />
        <span class="text-caption text-medium-emphasis">Показано: {{ filtered.length }} из {{ findings.length }}</span>
      </div>
    </v-card>

    <!-- Приоритетный блок -->
    <v-alert v-if="!filterActive" type="error" variant="tonal" class="mb-4" density="comfortable"
      icon="mdi-alert-octagon">
      <div class="font-weight-bold mb-1">Топ-приоритет (исправить в первую очередь)</div>
      <ul class="ms-4">
        <li v-for="f in topPriority" :key="f.id">
          <strong>{{ f.title }}</strong> — <span class="text-medium-emphasis">{{ f.file }}</span>
        </li>
      </ul>
    </v-alert>

    <!-- Список находок по категориям -->
    <template v-for="cat in groupedCategories" :key="cat">
      <div class="d-flex align-center ga-2 mt-4 mb-2">
        <v-icon :icon="catIcon(cat)" size="20" />
        <span class="text-h6">{{ cat }}</span>
        <v-chip size="x-small" variant="tonal">{{ grouped[cat].length }}</v-chip>
      </div>
      <v-card variant="outlined" class="mb-2">
        <v-expansion-panels multiple variant="accordion">
          <v-expansion-panel v-for="f in grouped[cat]" :key="f.id">
            <v-expansion-panel-title>
              <div class="d-flex align-center ga-3 flex-wrap" style="width:100%">
                <v-chip :color="sevColor(f.severity)" size="small" variant="flat" label>
                  {{ sevLabel(f.severity) }}
                </v-chip>
                <span class="font-weight-medium">{{ f.title }}</span>
                <v-chip v-if="f.verified" size="x-small" color="success" variant="tonal">
                  <v-icon start size="12">mdi-check</v-icon>подтверждено
                </v-chip>
                <v-spacer />
                <code class="text-caption text-medium-emphasis">{{ f.file }}</code>
              </div>
            </v-expansion-panel-title>
            <v-expansion-panel-text>
              <div class="mb-2">
                <div class="text-caption text-medium-emphasis mb-1">Проблема</div>
                <div class="text-body-2">{{ f.problem }}</div>
              </div>
              <div>
                <div class="text-caption text-medium-emphasis mb-1">Рекомендация</div>
                <div class="text-body-2 text-success">{{ f.recommendation }}</div>
              </div>
            </v-expansion-panel-text>
          </v-expansion-panel>
        </v-expansion-panels>
      </v-card>
    </template>

    <div class="text-caption text-medium-emphasis mt-6">
      Отчёт сформирован ручным аудитом (5 подсистем) + PHPStan/Pint 08.07.2026.
      Находки помечены «подтверждено», если воспроизведены на коде/данных.
      Не финансовая инструкция — приоритеты согласуйте перед правками денежных путей.
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import PageHeader from '../../components/PageHeader.vue';

const severityOrder = ['critical', 'high', 'medium', 'low'];
const sevLabelMap = { critical: 'Критично', high: 'Высокая', medium: 'Средняя', low: 'Низкая' };
const sevColorMap = { critical: 'error', high: 'deep-orange', medium: 'warning', low: 'info' };
function sevLabel(s) { return sevLabelMap[s] || s; }
function sevColor(s) { return sevColorMap[s] || 'grey'; }

const catIconMap = {
  'Безопасность': 'mdi-shield-alert',
  'Контроллеры / API': 'mdi-api',
  'Бизнес-логика': 'mdi-calculator-variant',
  'Frontend (Vue)': 'mdi-vuejs',
  'БД и модели': 'mdi-database',
  'Инфраструктура': 'mdi-cog-sync',
};
function catIcon(c) { return catIconMap[c] || 'mdi-file-code'; }

// Курированный отчёт код-ревью 08.07.2026 (5 подсистем + статический анализ).
const findings = ref([
  // ── Безопасность ──
  { id: 'SEC-1', severity: 'high', category: 'Безопасность', verified: true,
    title: 'IDOR: внутренние комментарии о партнёрах доступны любому',
    file: 'PartnerCommentsController.php:13,32 (routes/api.php:261-263)',
    problem: 'GET /partner-comments/{consultantId} и POST /partner-comments висят в блоке auth:sanctum БЕЗ проверки роли/владельца. Любой авторизованный партнёр может перебором consultantId читать staff-заметки о любом партнёре и писать комментарии на чужую карточку.',
    recommendation: 'Обернуть маршруты в staff-middleware (role: … или ConsultantPolicy), index/store скоупить по команде/staff-статусу вызывающего.' },
  { id: 'SEC-2', severity: 'low', category: 'Безопасность', verified: true,
    title: 'Shared-secret InSmart принимается через ?secret= в URL',
    file: 'InsmartWebhookController.php:87-98',
    problem: 'Секрет в query-строке утекает в access-логи nginx/прокси/referrer. Прочитавший логи может подделать paid-вебхук (создание контрактов/транзакций).',
    recommendation: 'Оставить только HMAC X-Insmart-Signature; query-fallback убрать, когда InSmart сможет слать заголовок, либо перенести в тело POST.' },
  { id: 'SEC-3', severity: 'low', category: 'Безопасность', verified: false,
    title: 'User enumeration (forgot-password / check-duplicates)',
    file: 'AuthController.php:272-276, 99-138',
    problem: 'forgot-password отдаёт 404 «Пользователь не найден», check-duplicates подтверждает существование email/phone. Позволяет валидировать наличие аккаунта (throttle 5–10/мин). Осознанное решение владельца, но остаётся утечкой.',
    recommendation: 'Принять как риск или вернуть единообразный ответ без раскрытия существования.' },
  { id: 'SEC-4', severity: 'low', category: 'Безопасность', verified: false,
    title: 'Legacy MD5-пароли всё ещё принимаются',
    file: 'User.php:138-146',
    problem: 'Для не-мигрированных аккаунтов принимается слабый MD5 (строгое === , авто-апгрейд на bcrypt при входе). Пока фолбэк жив — вектор для старых хэшей.',
    recommendation: 'Завершить массовую bcrypt-миграцию (ExpireMd5Passwords) и удалить фолбэк.' },
  { id: 'SEC-5', severity: 'low', category: 'Безопасность', verified: false,
    title: 'Нет config/cors.php — дефолт allowed_origins = *',
    file: 'config/cors.php (отсутствует)',
    problem: 'Низкий риск: auth — Bearer-токен в localStorage (не cookie), supports_credentials=false, ambient-CSRF нет. Но происхождение не закреплено.',
    recommendation: 'Добавить явный config/cors.php с фиксированным списком origin.' },

  // ── Контроллеры / API ──
  { id: 'API-1', severity: 'high', category: 'Контроллеры / API', verified: true,
    title: 'Правка транзакции — без DB::transaction и с проглоченной ошибкой',
    file: 'TransactionImportController.php:691-703',
    problem: 'update() обновляет transaction, затем soft-удаляет commission и зовёт calculateForTransaction в try/catch, который глушит исключение. Если пересчёт упадёт — старые комиссии уже удалены, новые не созданы, а ответ «Транзакция обновлена».',
    recommendation: 'Обернуть update+delete+recalc в DB::transaction(); не подавлять ошибку молча — возвращать её пользователю.' },
  { id: 'API-2', severity: 'high', category: 'Контроллеры / API', verified: false,
    title: 'Слабая авторизация на «Прочие начисления» (баллы = деньги)',
    file: 'AdminFinanceController storeCharge/updateCharge/deleteCharge (routes/api.php:549-551)',
    problem: 'Эндпоинты напрямую мутируют personalVolume/groupVolumeCumulative, но гейтятся широкой staff-группой (support/backoffice/education/invest…) без role:admin,calculations, в отличие от соседних финансовых операций.',
    recommendation: 'Ограничить до role:admin,calculations (или permission:finance,edit) для паритета.' },
  { id: 'API-3', severity: 'medium', category: 'Контроллеры / API', verified: false,
    title: 'Ручной ввод: расчёт после коммита → риск дубля транзакции',
    file: 'ManualTransactionController.php fixDrafts',
    problem: 'insertGetId(transaction) в DB::transaction, но calculateForTransaction и удаление черновика — ПОСЛЕ коммита. При ошибке калькулятора транзакция уже в БД, черновик остался → повторный «fix» создаёт дубль.',
    recommendation: 'Втянуть расчёт в ту же транзакцию либо помечать/откатывать осиротевшую транзакцию при ошибке. (Частично прикрыто новым анти-дублем по контракт+дата+сумма.)' },
  { id: 'API-4', severity: 'medium', category: 'Контроллеры / API', verified: false,
    title: 'Дублирование staff-ролей вместо User::isStaff()',
    file: 'WorkspaceController.php:22, ChatController.php:19,1919',
    problem: 'Инлайн-массивы/регэкспы ролей без роли invest/education расходятся с каноном User::isStaff(). Ошибка → неверный workspace или ссылка /manage/chat vs /chat для части сотрудников.',
    recommendation: 'Заменить на $user->isStaff(); источник ролей держать в одном месте (модель/конфиг).' },
  { id: 'API-5', severity: 'medium', category: 'Контроллеры / API', verified: false,
    title: '/admin/partners без гранулярного permission-middleware',
    file: 'routes/api.php:461-469',
    problem: 'store/update/delete/status-override партнёров без permission:, тогда как clients/contracts/requisites/transfers гейтятся гранулярно.',
    recommendation: 'Добавить permission:partners,* для консистентности авторизации записи.' },
  { id: 'API-6', severity: 'low', category: 'Контроллеры / API', verified: false,
    title: 'accrual_date пишется без валидации',
    file: 'AdminFinanceController.php:862-870, 920-925',
    problem: 'accrual_date берётся из request без правила в validate() — можно записать битую дату начисления.',
    recommendation: "Добавить 'accrual_date' => 'nullable|date'." },
  { id: 'API-7', severity: 'low', category: 'Контроллеры / API', verified: false,
    title: 'Арифметика баланса через интерполяцию в DB::raw',
    file: 'AdminFinanceController.php:898-966, 1011',
    problem: 'DB::raw("… + {$points}") — значения приведены к (float), инъекции нет, но паттерн хрупкий (локаль/NaN/INF теоретически ломают SQL).',
    recommendation: 'Использовать increment()/decrement() или биндинг-выражения.' },

  // ── Бизнес-логика ──
  { id: 'BIZ-1', severity: 'high', category: 'Бизнес-логика', verified: true,
    title: 'Две несогласованные стратегии генерации id денежных таблиц',
    file: 'InsmartIntegrationService.php:101,144 vs ManualTransactionController.php:517',
    problem: 'InSmart пишет contract/transaction через LegacyId::next() (max+1), а ManualTx/импорт — через serial-sequence + setval. max+1 не двигает sequence → после restore или параллельно с sequence-insert возможен duplicate _pkey на денежных таблицах.',
    recommendation: 'Привести InSmart к setval+insertGetId (как ManualTx) либо гарантировать один способ на таблицу.' },
  { id: 'BIZ-2', severity: 'high', category: 'Бизнес-логика', verified: true,
    title: 'Гонка идемпотентности InSmart → дубль контракта + двойная комиссия',
    file: 'InsmartIntegrationService.php:58-68',
    problem: 'Идемпотентность по counterpartyContractId проверяется exists() ВНЕ транзакции, без уникального индекса и без блокировки. Два ретрая/параллельных вебхука проходят проверку оба → дубль контракта и двойное начисление.',
    recommendation: 'Partial-unique индекс на contract.counterpartyContractId (WHERE deletedAt IS NULL) + ловить нарушение.' },
  { id: 'BIZ-3', severity: 'medium', category: 'Бизнес-логика', verified: false,
    title: 'Импорт контрактов: insertGetId без setval',
    file: 'ContractImportController.php:428',
    problem: 'Тот же класс лаг-сиквенса, что уже чинили в ManualTx: после restore каждая строка импорта падает duplicate pkey (глушится в per-row catch → «все ошибки»).',
    recommendation: 'Добавить setval(GREATEST(max,1)) перед вставкой.' },
  { id: 'BIZ-4', severity: 'medium', category: 'Бизнес-логика', verified: false,
    title: 'InSmart может создать контракт на терминированного партнёра',
    file: 'InsmartIntegrationService.php:175-184',
    problem: 'resolveConsultant не проверяет activity: если partnerId — терминированный/исключённый ФК (3/5), контракт создаётся на него. Реассайн срабатывает только по событию смены статуса и повторно не выстрелит → инвариант «терминированные не держат контракты» нарушен.',
    recommendation: 'В resolveConsultant проверять activity и делать fallback на upline / Неизвестного (536).' },
  { id: 'BIZ-5', severity: 'medium', category: 'Бизнес-логика', verified: false,
    title: 'N+1 при массовом расчёте комиссий (импорт)',
    file: 'CommissionCalculator.php calculateForImport',
    problem: 'calculateForTransaction в цикле: на каждую строку своя DB::transaction+lockForUpdate, отдельные запросы vat/currencyRate/program + пересчёт балансов. На ~1267 строк — тысячи round-trip.',
    recommendation: 'Кэшировать vat/usd-rate на прогон, батчить пересчёт балансов после цикла, предзагружать цепочку inviter рекурсивным CTE.' },
  { id: 'BIZ-6', severity: 'medium', category: 'Бизнес-логика', verified: true,
    title: 'Номер InSmart-контракта обрезается до 8 символов',
    file: 'InsmartIntegrationService.php:100',
    problem: 'number = strtoupper(substr(externalId, 0, 8)) повышает коллизии номеров. InSmart-номера и так не уникальны по клиенту — дедуп-по-номеру уже приводил к потере контракта 18481.',
    recommendation: 'Хранить полный externalId в number либо не обрезать.' },
  { id: 'BIZ-7', severity: 'low', category: 'Бизнес-логика', verified: false,
    title: 'ЛП периода не сбрасывается при откате активного периода',
    file: 'PartnerStatusService.php:394-412',
    problem: 'Комментарий обещает «обнуляем ЛП периода», но personalVolume не сбрасывается; продление читает денормализованный ЛП → партнёр с реальным ЛП<500 может не терминироваться. Смягчено отключённым кроном (calc-by-button).',
    recommendation: 'Сбрасывать ЛП явно или считать ЛП периода запросом на месте.' },
  { id: 'BIZ-8', severity: 'low', category: 'Бизнес-логика', verified: false,
    title: 'Деление на (1+НДС) без guard',
    file: 'CommissionCalculator.php:264',
    problem: 'amountRub / (1 + vatPercent/100) — при vatPercent = −100 деление на ноль. Практически недостижимо, но защита копеечная.',
    recommendation: 'Добавить guard/max на знаменатель.' },
  { id: 'BIZ-9', severity: 'low', category: 'Бизнес-логика', verified: true,
    title: 'PHPStan: unset($F) с неопределённой переменной',
    file: 'PartnerSalesMatrixController.php:414',
    problem: 'В ветке, где внутренний цикл по структуре не выполнился, $F не определена, а unset($F) вызывается → E_WARNING на пустой структуре.',
    recommendation: 'Инициализировать $F до цикла или unset только при определённости.' },

  // ── Frontend (Vue) ──
  { id: 'FE-1', severity: 'high', category: 'Frontend (Vue)', verified: true,
    title: 'Обратные слэши в пути API → битый URL',
    file: 'BankChanges.vue:86, MyPayments.vue:211',
    problem: "api.get('\\admin\\bank-change-requests') — \\b это JS-escape backspace, запрос уходит на битый URL (тихий 404, catch → items=[]). В MyPayments \\m теряет ведущий слэш (работает лишь по удаче baseURL).",
    recommendation: 'Использовать прямые слэши: /admin/bank-change-requests, /my-payments.' },
  { id: 'FE-2', severity: 'medium', category: 'Frontend (Vue)', verified: false,
    title: 'Утечка сокета и слушателя в MainLayout',
    file: 'MainLayout.vue:704,740,811',
    problem: 'onUnmounted чистит только unreadInterval; window.__notifSocket не disconnect()-ится, анонимный visibilitychange-listener не снимается → сокет и слушатель дублируются при перемонтировании layout (logout→login).',
    recommendation: 'Сохранять ссылку на handler, disconnect() сокета и removeEventListener на unmount.' },
  { id: 'FE-3', severity: 'medium', category: 'Frontend (Vue)', verified: false,
    title: 'Проглоченные ошибки загрузки без уведомления',
    file: 'Dashboard.vue:487, Partners.vue:701, Currencies.vue:232, Contests.vue:78, Structure.vue:420 и др.',
    problem: 'Множество data-load с catch {} без обратной связи: при сбое GET таблица показывает пустое состояние как «нет данных», DELETE-ошибка не видна пользователю.',
    recommendation: 'Пропускать через useSnackbar().showError (как уже делает useCrud).' },
  { id: 'FE-4', severity: 'medium', category: 'Frontend (Vue)', verified: false,
    title: 'Дублирование палитр статусов вместо useDesign',
    file: 'Tasks/TasksHome.vue:327-340, Tasks/ProjectBoard.vue:172-191',
    problem: 'Захардкоженные palette[]/STATUS_HEX/statusHex() в обход useDesign (getStatusColor/getPriorityColor уже есть).',
    recommendation: 'Вынести цвета статусов/приоритетов задач в useDesign и импортировать в обоих.' },
  { id: 'FE-5', severity: 'low', category: 'Frontend (Vue)', verified: false,
    title: 'Хардкод hex статусов вместо токенов темы',
    file: 'Chat/Analytics.vue:376-410, Chat/StaffChat.vue:2949+, Education.vue:249-256',
    problem: 'Захардкоженные #059669/#dc2626/#c27803/#f97316 вместо Vuetify success/error/warning — не адаптируются под тему.',
    recommendation: 'rgb(var(--v-theme-success/error/warning)); градиенты — в CSS-переменные.' },
  { id: 'FE-6', severity: 'low', category: 'Frontend (Vue)', verified: false,
    title: 'Инлайн-парсинг сортировки вместо useTableSort',
    file: 'Transactions.vue:1421-1446',
    problem: 'Своя реализация sortBy→sort_by/sort_dir вместо композабла useTableSort, применяемого везде. Параметры совпадают, но логика дублируется и может разойтись.',
    recommendation: 'Перейти на useTableSort.' },
  { id: 'FE-7', severity: 'low', category: 'Frontend (Vue)', verified: false,
    title: 'Незачищенный setInterval в SystemStatus',
    file: 'SystemStatus.vue:173',
    problem: 'setInterval(load, 60000) без clearInterval на unmount → фоновые запросы после ухода со страницы.',
    recommendation: 'Сохранить id и clearInterval в onUnmounted.' },

  // ── БД и модели ──
  { id: 'DB-1', severity: 'high', category: 'БД и модели', verified: false,
    title: 'Денежные модели без SoftDeletes / global scope',
    file: 'Models/{Contract,Transaction,Commission,Client,Consultant,QualificationLog}.php',
    problem: 'Ни одна soft-delete модель не использует SoftDeletes/global scope; фильтрация deletedAt/dateDeleted держится на ручном whereNull в каждом запросе — уже приводило к утечкам в отчёты/деньги.',
    recommendation: 'Добавить глобальный scope или SoftDeletes с переопределённым DELETED_AT.' },
  { id: 'DB-2', severity: 'high', category: 'БД и модели', verified: true,
    title: 'Миграция без down() (необратима)',
    file: 'database/migrations/2026_05_29_145813_create_health_tables.php',
    problem: 'Единственная миграция без down() — нарушает обязательное up+down, схема необратима.',
    recommendation: 'Добавить down() с Schema::dropIfExists().' },
  { id: 'DB-3', severity: 'medium', category: 'БД и модели', verified: false,
    title: 'Consultant::person() указывает на WebUser (чужое id-пространство)',
    file: 'Models/Consultant.php:64',
    problem: 'Связь person() мапит consultant.person на User::class (таблица WebUser), но person и WebUser — разные id-пространства → отдаёт чужую запись.',
    recommendation: 'Линк на логин вести через колонку webUser; person — на модель person-таблицы.' },
  { id: 'DB-4', severity: 'medium', category: 'БД и модели', verified: false,
    title: 'Денежные модели с $guarded=[id] (mass-assignment открыт)',
    file: 'Models/{Contract,Transaction,Commission,Client}.php',
    problem: '$guarded=[id] открывает массовое присвоение всех колонок, включая ammount/amountRUB/percent; при fill(request()->all()) риск порчи сумм.',
    recommendation: 'Перейти на явный $fillable.' },
  { id: 'DB-5', severity: 'medium', category: 'БД и модели', verified: true,
    title: 'Рассинхрон имён soft-delete колонок',
    file: 'contract/transaction/commission (deletedAt) vs client/consultant/WebUser (dateDeleted)',
    problem: 'Разные имена колонки мягкого удаления по таблицам — легко отфильтровать не ту (потеря/утечка данных).',
    recommendation: 'Зафиксировать канон per-table и прятать за scope, а не инлайнить whereNull.' },
  { id: 'DB-6', severity: 'low', category: 'БД и модели', verified: false,
    title: 'Реквизиты: soft-delete через опциональный scope',
    file: 'Models/{BankRequisite,Requisite}.php',
    problem: 'Мягкое удаление спрятано в опциональный ->alive(); любой запрос без него вернёт удалённые реквизиты.',
    recommendation: 'Сделать глобальным scope.' },
  { id: 'DB-7', severity: 'low', category: 'БД и модели', verified: false,
    title: 'Необратимый DELETE в миграции без backup-таблицы',
    file: 'database/migrations/2026_05_05_000110_purge_empty_client_orphans.php',
    problem: 'up() делает DELETE (client + clientsIndicators) без выгрузки; down() только логирует.',
    recommendation: 'Выгружать удаляемые строки в backup-таблицу внутри up().' },

  // ── Инфраструктура ──
  { id: 'INFRA-1', severity: 'high', category: 'Инфраструктура', verified: true,
    title: 'Тестовая БД не поднимается — CI не гейтит регрессии',
    file: 'phpunit.xml / .env.testing (newds_test)',
    problem: '61 из 62 тестов падают на подключении к БД (newds_test / пароль postgres). Автотесты фактически не защищают денежные пути.',
    recommendation: 'Поднять newds_test с корректными кредами (или SQLite для не-PG-специфичных), включить прогон в pre-push/CI.' },
  { id: 'INFRA-2', severity: 'low', category: 'Инфраструктура', verified: true,
    title: '306 файлов с отклонениями стиля (Pint)',
    file: 'app/** (306 файлов)',
    problem: 'Косметика: отступы/пробелы/blank_line_before_statement. Не ошибки, но зашумляют диффы.',
    recommendation: 'Прогнать vendor/bin/pint отдельным коммитом, затем держать pint --test в CI.' },
  { id: 'INFRA-3', severity: 'low', category: 'Инфраструктура', verified: false,
    title: 'Гонка авто-деплоя: git pull vs webhook, битый build',
    file: 'deploy webhook (prod)',
    problem: 'При push webhook и ручной pull конкурируют за ref-lock; параллельный npm build ловит rollup «invalid resolved id» и оставляет старый CSS.',
    recommendation: 'Сериализовать деплой (lock-файл): pull → дождаться HEAD → build; не запускать build при недопуленном репо.' },
])

const filterCategory = ref([]);
const filterSeverity = ref([]);
const search = ref('');

const categories = ['Безопасность', 'Контроллеры / API', 'Бизнес-логика', 'Frontend (Vue)', 'БД и модели', 'Инфраструктура'];
const severitySelect = severityOrder.map(s => ({ title: sevLabel(s), value: s }));

const filterActive = computed(() =>
  filterCategory.value.length || filterSeverity.value.length || (search.value || '').trim());

const countBySeverity = computed(() => {
  const c = {};
  for (const f of findings.value) c[f.severity] = (c[f.severity] || 0) + 1;
  return c;
});

const sevRank = { critical: 0, high: 1, medium: 2, low: 3 };
const filtered = computed(() => {
  const q = (search.value || '').toLowerCase().trim();
  return findings.value.filter(f => {
    if (filterCategory.value.length && !filterCategory.value.includes(f.category)) return false;
    if (filterSeverity.value.length && !filterSeverity.value.includes(f.severity)) return false;
    if (q && !(`${f.title} ${f.problem} ${f.recommendation} ${f.file} ${f.id}`.toLowerCase().includes(q))) return false;
    return true;
  });
});

const grouped = computed(() => {
  const g = {};
  for (const f of filtered.value) (g[f.category] ||= []).push(f);
  for (const cat of Object.keys(g)) {
    g[cat].sort((a, b) => sevRank[a.severity] - sevRank[b.severity]);
  }
  return g;
});
const groupedCategories = computed(() => categories.filter(c => grouped.value[c]?.length));

const topPriority = computed(() =>
  findings.value.filter(f => f.severity === 'high' || f.severity === 'critical').slice(0, 8));
</script>
