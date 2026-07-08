<template>
  <div class="pa-4">
    <PageHeader title="Качество кода"
      subtitle="Полный аудит прод-кодовой базы (5 подсистем) + статус исправлений. Обновлено 08.07.2026." />

    <!-- Сводка -->
    <v-row class="mb-2" dense>
      <v-col cols="6" sm="3">
        <v-card variant="tonal" color="success" class="pa-3">
          <div class="text-h4 font-weight-bold">{{ fixedCount }}</div>
          <div class="text-body-2">Исправлено в этой сессии</div>
        </v-card>
      </v-col>
      <v-col v-for="s in severityOrder" :key="s" cols="6" sm="3">
        <v-card variant="tonal" :color="sevColor(s)" class="pa-3">
          <div class="text-h4 font-weight-bold" style="font-variant-numeric:tabular-nums">{{ openBySeverity[s] || 0 }}</div>
          <div class="text-body-2">Открыто · {{ sevLabel(s) }}</div>
        </v-card>
      </v-col>
    </v-row>

    <!-- Автоматические инструменты -->
    <v-row class="mb-4" dense>
      <v-col cols="12" md="4">
        <v-card variant="outlined" class="pa-3 h-100">
          <div class="d-flex align-center ga-2 mb-1"><v-icon color="warning">mdi-code-braces</v-icon>
            <span class="text-subtitle-2 font-weight-bold">PHPStan / larastan</span></div>
          <div class="text-body-2 text-medium-emphasis">Baseline заморожен (~238). CI-гейт на деплой активен. Ложные property.notFound на json-castах — не трогать (larastan не читает casts()).</div>
        </v-card>
      </v-col>
      <v-col cols="12" md="4">
        <v-card variant="outlined" class="pa-3 h-100">
          <div class="d-flex align-center ga-2 mb-1"><v-icon color="info">mdi-format-paint</v-icon>
            <span class="text-subtitle-2 font-weight-bold">Pint (стиль)</span></div>
          <div class="text-body-2 text-medium-emphasis"><strong>~306</strong> файлов с отклонениями стиля (косметика). Фикс: <code>vendor/bin/pint</code> отдельным коммитом + <code>pint --test</code> в CI.</div>
        </v-card>
      </v-col>
      <v-col cols="12" md="4">
        <v-card variant="outlined" class="pa-3 h-100">
          <div class="d-flex align-center ga-2 mb-1"><v-icon color="error">mdi-test-tube</v-icon>
            <span class="text-subtitle-2 font-weight-bold">Тесты</span></div>
          <div class="text-body-2 text-medium-emphasis"><strong>61 из 62</strong> падают локально (БД newds_test / пароль). CI не гейтит регрессии денежных путей. Приоритет: поднять тестовую БД.</div>
        </v-card>
      </v-col>
    </v-row>

    <!-- Фильтры -->
    <v-card class="mb-4 pa-3" variant="tonal">
      <div class="d-flex align-center flex-wrap ga-3">
        <v-btn-toggle v-model="view" mandatory density="comfortable" color="primary">
          <v-btn value="open" size="small">Открытые ({{ openCount }})</v-btn>
          <v-btn value="fixed" size="small">Исправленные ({{ fixedCount }})</v-btn>
        </v-btn-toggle>
        <v-select v-model="filterCategory" :items="categories" label="Категория" density="compact"
          variant="outlined" hide-details clearable multiple chips style="min-width:240px;max-width:420px"
          prepend-inner-icon="mdi-filter-variant" />
        <v-text-field v-model="search" placeholder="Поиск" density="compact" variant="outlined"
          hide-details clearable prepend-inner-icon="mdi-magnify" style="min-width:200px" />
        <v-spacer />
        <span class="text-caption text-medium-emphasis">Показано: {{ filtered.length }}</span>
      </div>
    </v-card>

    <!-- Топ-приоритет (только для открытых) -->
    <v-alert v-if="view === 'open' && !filterActive" type="error" variant="tonal" class="mb-4"
      density="comfortable" icon="mdi-alert-octagon">
      <div class="font-weight-bold mb-1">Топ-приоритет (исправить в первую очередь)</div>
      <ul class="ms-4">
        <li v-for="f in topPriority" :key="f.id"><strong>{{ f.title }}</strong> — <span class="text-medium-emphasis">{{ f.file }}</span></li>
      </ul>
    </v-alert>

    <!-- Список по категориям -->
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
                <v-chip :color="f.status === 'fixed' ? 'success' : sevColor(f.severity)" size="small" variant="flat" label>
                  <v-icon v-if="f.status === 'fixed'" start size="14">mdi-check</v-icon>{{ f.status === 'fixed' ? 'Исправлено' : sevLabel(f.severity) }}
                </v-chip>
                <span class="font-weight-medium">{{ f.title }}</span>
                <v-spacer />
                <code class="text-caption text-medium-emphasis">{{ f.file }}</code>
              </div>
            </v-expansion-panel-title>
            <v-expansion-panel-text>
              <div class="mb-2"><div class="text-caption text-medium-emphasis mb-1">{{ f.status === 'fixed' ? 'Что было' : 'Проблема' }}</div>
                <div class="text-body-2">{{ f.problem }}</div></div>
              <div><div class="text-caption text-medium-emphasis mb-1">{{ f.status === 'fixed' ? 'Как исправлено' : 'Рекомендация' }}</div>
                <div class="text-body-2" :class="f.status === 'fixed' ? 'text-success' : 'text-info'">{{ f.recommendation }}</div></div>
            </v-expansion-panel-text>
          </v-expansion-panel>
        </v-expansion-panels>
      </v-card>
    </template>

    <div class="text-caption text-medium-emphasis mt-6">
      Отчёт: ручной аудит 5 подсистем (безопасность, контроллеры, бизнес-логика, frontend, БД) + известные пункты сессии 08.07.2026.
      Денежные пункты помечены — их правка требует подтверждения финансов. «Исправлено» = задеплоено на прод в этой сессии.
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
  'Безопасность': 'mdi-shield-alert', 'Контроллеры / API': 'mdi-api',
  'Бизнес-логика (деньги)': 'mdi-calculator-variant', 'Frontend (Vue)': 'mdi-vuejs',
  'БД и модели': 'mdi-database', 'Деньги · ждут финансов': 'mdi-cash-alert',
  'Импорт транзакций': 'mdi-file-import', 'Инфраструктура': 'mdi-cog-sync',
};
function catIcon(c) { return catIconMap[c] || 'mdi-file-code'; }

// ── ОТКРЫТЫЕ находки (аудит 08.07.2026) ──
const openFindings = [
  // Безопасность
  { id: 'SEC-1', severity: 'high', category: 'Безопасность', title: 'IDOR: внутренние комментарии о партнёрах доступны любому',
    file: 'PartnerCommentsController.php (routes/api.php:261-263)',
    problem: 'GET/POST /partner-comments в блоке auth:sanctum без staff-гейта: любой авторизованный партнёр перебором consultantId читает staff-заметки о любом и пишет комментарии на чужую карточку. index без проверки владельца, store только ставит author_id.',
    recommendation: 'Перенести 3 маршрута в staff-role группу (role:… / permission:) + скоуп по команде.' },
  { id: 'SEC-2', severity: 'medium', category: 'Безопасность', title: 'role принимается как свободная строка (без whitelist)',
    file: 'AdminUserController.php:249, AdminDataController.php:378',
    problem: 'role валидируется как string|max:255 без списка допустимых. Опечатка/произвольное значение молча портит WebUser.role, от которого зависит isStaff()/права → потеря или получение доступа.',
    recommendation: 'Validate против enum допустимых ролей (Rule::in).' },
  { id: 'SEC-3', severity: 'low', category: 'Безопасность', title: 'ChatController: смена статуса/назначения без view-policy',
    file: 'ChatController.php updateStatus/assign/updateSubject/togglePin (~1110)',
    problem: 'Гейт по isStaff(), но без ре-проверки policy view → любой staff любого отдела может менять статус/тему/пин любого тикета (не только своих). Партнёры заблокированы; риск внутристафный.',
    recommendation: 'Добавить проверку policy view/участия в тикете.' },
  { id: 'SEC-4', severity: 'low', category: 'Безопасность', title: 'admin v-html без санитайза (Инструкции/контент-страницы)',
    file: 'Instructions.vue:78, ContentPageView.vue:9, Admin/Documentation.vue:22',
    problem: 'Stored-XSS поверхность: авторство инструкций в staff-группе включает роль education, а инструкции рендерятся всем партнёрам → менее доверенный staffer может внедрить скрипт.',
    recommendation: 'DOMPurify для контента/инструкций или ограничить авторство ролью admin.' },
  { id: 'SEC-5', severity: 'low', category: 'Безопасность', title: 'User.role в $fillable (латентная эскалация)',
    file: 'Models/User.php:48',
    problem: 'Сейчас безопасно (все пути пишут role явно/за admin-гейтом), но любой будущий User::create($request->all()) = мгновенная эскалация прав.',
    recommendation: 'Убрать role из $fillable, ставить явно.' },
  { id: 'SEC-6', severity: 'low', category: 'Безопасность', title: 'AdminUserController::destroy без явного admin-гейта',
    file: 'AdminUserController.php:311',
    problem: 'Soft-delete WebUser достижим широкой staff-группой (в отличие от forceDelete/role-правок с hasAnyRole([admin])); смягчено restrict.*-middleware.',
    recommendation: 'Подтвердить, что restrict.* блокирует DELETE не-админам, или добавить admin-проверку.' },
  { id: 'SEC-7', severity: 'low', category: 'Безопасность', title: 'InSmart shared-secret в ?secret= (URL)',
    file: 'InsmartWebhookController.php:87-98',
    problem: 'Fallback авторизует по query ?secret= — секрет утекает в логи nginx/прокси/referrer (в отличие от HMAC-заголовка). В логах приложения маскируется, но не в upstream.',
    recommendation: 'Оставить только HMAC X-Insmart-Signature, query-fallback убрать.' },
  { id: 'SEC-8', severity: 'low', category: 'Безопасность', title: 'User enumeration (forgot-password / check-duplicates)',
    file: 'AuthController.php:322-352, 91-141',
    problem: 'forgot-password даёт 404 для неизвестного email, check-duplicates подтверждает наличие email/phone. Осознанное решение владельца — остаточный риск.',
    recommendation: 'Принять как риск или вернуть единообразный ответ.' },

  // Контроллеры / API
  { id: 'API-1', severity: 'medium', category: 'Контроллеры / API', title: 'Дублирование staff-ролей вместо User::isStaff()',
    file: 'AdminFinalizeController:93, AdminPoolController:193, AnnouncementController:24, ChatController:54,1919',
    problem: 'explode(",", role)+in_array вместо канона isStaff()/hasAnyRole(). ChatController:1919 регэксп без роли invest → неверный deep-link /chat вместо /manage/chat для инвесторов.',
    recommendation: 'Централизовать на модельные хелперы isStaff().' },
  { id: 'API-2', severity: 'medium', category: 'Контроллеры / API', title: 'Проглоченные исключения в импорте (пустой catch)',
    file: 'ContractImportController.php:719, AdminDataController.php:1193',
    problem: 'catch (\\Throwable) {} прячет построчные сбои импорта от оператора и логов.',
    recommendation: 'Как минимум Log::warning с контекстом.' },
  { id: 'API-3', severity: 'medium', category: 'Контроллеры / API', title: 'Жирные контроллеры (бизнес-логика внутри)',
    file: 'AdminDataController (3445 стр.), ChatController (2975), ProductSalesMatrixController (2031)',
    problem: 'Мутация комиссий/баланса, дедуп контрактов, роутинг чата — в контроллерах, а не в app/Services (против правила «тонкие контроллеры»).',
    recommendation: 'Выносить сервисы постепенно.' },
  { id: 'API-4', severity: 'medium', category: 'Контроллеры / API', title: 'Хардкод legacy status-id в логике контракта',
    file: 'AdminDataController.php:2705,2751 ([1,6,8,9,10], status===1)',
    problem: 'Магические id contractStatus зашиты, тогда как ContractController:113 резолвит статус динамически.',
    recommendation: 'Резолвить по названию или вынести в config/settings.' },
  { id: 'API-5', severity: 'medium', category: 'Контроллеры / API', title: 'Арифметика баланса через интерполяцию в DB::raw',
    file: 'AdminFinanceController.php:898-1012',
    problem: 'DB::raw("… + {$points}") — инъекции нет ((float)-касты), но паттерн хрупкий.',
    recommendation: 'increment()/decrement() или биндинги.' },
  { id: 'API-6', severity: 'low', category: 'Контроллеры / API', title: 'Неконсистентные формы API-ответов',
    file: 'ContractController::statuses/products vs {data,total} vs {message}',
    problem: 'Часть эндпоинтов — голые массивы, часть — {data,total}, записи — {message}. Усложняет SPA-потребителей.',
    recommendation: 'API Resources + стабильный конверт.' },
  { id: 'API-7', severity: 'low', category: 'Контроллеры / API', title: 'Мёртвый маршрут upload-history',
    file: 'routes/api.php:496',
    problem: 'Заглушка /admin/contracts/upload-history → response()->json([]).',
    recommendation: 'Удалить или реализовать.' },

  // Бизнес-логика (деньги)
  { id: 'BIZ-1', severity: 'high', category: 'Бизнес-логика (деньги)', title: 'InSmart: contract/transaction без выравнивания сиквенса',
    file: 'InsmartIntegrationService.php:101,144',
    problem: 'contract/transaction вставляются через LegacyId::next() (MAX+1), но у таблиц есть serial-сиквенс. Ручная генерация НЕ двигает сиквенс → следующий serial-INSERT (bulk-импорт) врежется в занятый id → duplicate _pkey. (Для person/client уже добавлен syncSequence, для contract/transaction здесь — нет.)',
    recommendation: 'Вставлять через insertGetId или вызвать LegacyId::syncSequence(contract/transaction).' },
  { id: 'BIZ-2', severity: 'high', category: 'Бизнес-логика (деньги)', title: 'НДС берётся по now(), а не по дате транзакции',
    file: 'CommissionCalculator.php:259-262, ManualTransactionController.php:582-585',
    problem: 'Ставка vat резолвится по now(). При пересчёте пост-cutoff сделки после смены НДС amountNoVat (база ВСЕХ комиссий и дохода ДС) считается по неверной ставке.',
    recommendation: 'Резолвить vat по tx->date.' },
  { id: 'BIZ-3', severity: 'medium', category: 'Бизнес-логика (деньги)', title: 'InSmart: гонка идемпотентности (нет unique-индекса)',
    file: 'InsmartIntegrationService.php:57-68',
    problem: 'Проверка counterpartyContractId exists() до транзакции, без unique-индекса. Два одновременных вебхука → дубль контракта+транзакции+комиссий.',
    recommendation: 'Partial-unique на counterpartyContractId + обработка конфликта.' },
  { id: 'BIZ-4', severity: 'medium', category: 'Бизнес-логика (деньги)', title: 'Превью ↔ факт: уровень и стартовый %',
    file: 'ManualTransactionController.php:834,844,845',
    problem: 'Превью: уровень = nominalLevel ?? calculationLevel (приоритет номиналу), факт = MAX из двух; стартовый % захардкожен 15 vs SystemSetting в каскаде. Превью и начисление расходятся.',
    recommendation: 'Использовать общий резолвер уровня + ту же настройку startup_percent.' },
  { id: 'BIZ-5', severity: 'medium', category: 'Бизнес-логика (деньги)', title: 'Штрафы: уровень берётся текущий, не за расчётный месяц',
    file: 'MonthlyPenaltyRunner.php:86,113',
    problem: 'mandatoryGP/otrif/percent из текущего consultant.status_and_lvl, а не уровня месяца. Пересчёт прошлого периода после апгрейда партнёра применит штрафы по сегодняшнему уровню.',
    recommendation: 'Резолвить уровень месяца из qualificationLog (как PoolRunner).' },
  { id: 'BIZ-6', severity: 'medium', category: 'Бизнес-логика (деньги)', title: 'Знаменатель «отрыва» несогласован',
    file: 'MonthlyPenaltyRunner.php:352 vs MonthlyFinaliser.php:48-50',
    problem: 'Решение о штрафе (>70%) = branchVolume/Σ веток (без ЛП), а сохранённый gapValuePercentage = branchVolume/totalGroupVolume (с ЛП). Ветка может быть >70% по одному и <70% по показанному.',
    recommendation: 'Считать оба от одной базы.' },
  { id: 'BIZ-7', severity: 'medium', category: 'Бизнес-логика (деньги)', title: 'HISTORICAL_CUTOFF задан дважды по-разному',
    file: 'CommissionCalculator.php:37 vs PoolRunner.php:50',
    problem: "HISTORICAL_CUTOFF='2026-06-01' и HISTORICAL_BEFORE=['year'=>2026,'month'=>6] — изменение одной не тронет другую → рассинхрон движков.",
    recommendation: 'Единый источник (одна константа/настройка).' },
  { id: 'BIZ-8', severity: 'medium', category: 'Бизнес-логика (деньги)', title: 'Каскад комиссий: N+1 по цепочке',
    file: 'CommissionCalculator.php:374,380,383',
    problem: 'На каждый уровень цепочки (до 20) — отдельные запросы consultant×2 + getQualificationLevel. На импорте тысяч транзакций — классический N+1.',
    recommendation: 'Предзагрузить inviter-map и уровни (как в MonthlyPenaltyRunner).' },
  { id: 'BIZ-9', severity: 'medium', category: 'Бизнес-логика (деньги)', title: 'calculateForImport: матч по строке comment, без deletedAt',
    file: 'CommissionCalculator.php:498-505',
    problem: "Выбор транзакций по comment='Импорт #N' без whereNull(deletedAt) и чанкинга. Ручная tx с таким же комментарием попадёт под пересчёт; удалённые гоняются впустую.",
    recommendation: 'Выбирать по явному import-id + фильтр deletedAt.' },
  { id: 'BIZ-10', severity: 'low', category: 'Бизнес-логика (деньги)', title: 'Неизвестный ФК: не пишется netRevenueUSD; computePoints дублируется',
    file: 'CommissionCalculator.php:747-757, 545 + ManualTransactionController.php:802',
    problem: 'В ветке неизвестного ФК USD-остаток устаревает (netRevenueUSD не пишется). computePoints продублирован в 2 классах — риск дрейфа формулы ЛП.',
    recommendation: 'Дописать netRevenueUSD; свести превью на статический CommissionCalculator::computePoints.' },
  { id: 'BIZ-11', severity: 'low', category: 'Бизнес-логика (деньги)', title: 'InSmart: resolveConsultant без фильтра удалённых/терминированных; валюта не конвертируется',
    file: 'InsmartIntegrationService.php:148-152,180',
    problem: 'resolveConsultant без whereNull(dateDeleted) и без гейта терминированных → контракт может уйти на soft-deleted/терминированного. amountRUB=txAmount при currency=USD/EUR остаётся неконвертированным.',
    recommendation: 'Фильтровать удалённых/терминированных; конвертировать по курсу или форсить RUB.' },

  // Frontend
  { id: 'FE-1', severity: 'high', category: 'Frontend (Vue)', title: 'Утечка сокета и слушателя в MainLayout',
    file: 'MainLayout.vue:704,811-813',
    problem: 'onUnmounted чистит только unreadInterval; visibilitychange-listener не снимается, window.__notifSocket не disconnect()-ится → дублирование при logout→login.',
    recommendation: 'Сохранять handler, removeEventListener + disconnect() на unmount.' },
  { id: 'FE-2', severity: 'medium', category: 'Frontend (Vue)', title: 'Незачищенные таймеры (Pool/Mail/SystemStatus/Profile)',
    file: 'Pool.vue:650-682, Mail.vue:694,931, SystemStatus.vue:170, Profile.vue:1315',
    problem: 'applyPollTimer/progressTimer/setInterval/tgPollTimer чистятся только по завершении операции, onUnmounted отсутствует → фоновые запросы после ухода со страницы (пул — каждые 1.5с).',
    recommendation: 'onUnmounted(() => clearInterval/Timeout) в каждой странице.' },
  { id: 'FE-3', severity: 'medium', category: 'Frontend (Vue)', title: 'isStaff без роли invest (Workspace/StaffChat)',
    file: 'Workspace.vue:471, StaffChat.vue:2475, MainLayout.vue:901',
    problem: 'Локальные isStaff/STAFF_ROLES_RE без роли invest, тогда как канон auth.isStaff включает education+invest. Сотрудник только с invest получает партнёрский workspace / неверные права.',
    recommendation: 'Использовать auth.isStaff.' },
  { id: 'FE-4', severity: 'medium', category: 'Frontend (Vue)', title: 'Массовое проглатывание ошибок загрузки',
    file: 'MyPayments.vue:215, Dashboard.vue:487, Currencies.vue:232, Partners.vue:701 (~200 мест)',
    problem: 'catch {} без обратной связи: сбой GET рисует пустое состояние как «нет данных», ошибка DELETE не видна.',
    recommendation: 'Пропускать через useSnackbar().showError (как useCrud).' },
  { id: 'FE-5', severity: 'low', category: 'Frontend (Vue)', title: 'Дублирование палитр/хардкод hex вместо useDesign/токенов',
    file: 'Tasks/TasksHome.vue:327, ProjectBoard.vue:172, EducationCourse.vue:228, SystemStatus.vue:185',
    problem: 'Захардкоженные palette[]/STATUS_HEX и градиенты (#hex) не адаптируются под тему; дублируются в 2 файлах.',
    recommendation: 'Вынести в useDesign; градиенты — в rgb(var(--v-theme-*)).' },
  { id: 'FE-6', severity: 'low', category: 'Frontend (Vue)', title: 'Инлайн-парсинг сортировки + фронт-N+1 в bulk',
    file: 'Transactions.vue:1421, StaffChat.vue:1797-1832',
    problem: 'Своя реализация sortBy→sort_by вместо useTableSort; bulk-операции чата — по запросу на каждый id (await в цикле).',
    recommendation: 'useTableSort; батч-эндпоинт или Promise.all для bulk.' },

  // БД и модели
  { id: 'DB-1', severity: 'high', category: 'БД и модели', title: 'Денежные модели без SoftDeletes / global scope',
    file: 'Models/{Contract,Transaction,Commission,Client,Consultant,QualificationLog}.php',
    problem: 'Ни одна денежная модель не использует SoftDeletes/scope; фильтрация deletedAt/dateDeleted — ручной whereNull в каждом вызове. Один забытый фильтр = удалённые строки в суммах/отчётах.',
    recommendation: 'Трейт-scope alive() (с учётом имени колонки) или global scope.' },
  { id: 'DB-2', severity: 'high', category: 'БД и модели', title: 'Раскол имён soft-delete колонок',
    file: 'deletedAt (Contract/Transaction/Commission) vs dateDeleted (Client/Consultant/WebUser/QualificationLog)',
    problem: 'При join между этими таблицами легко подставить не ту колонку и молча получить удалённые строки.',
    recommendation: 'Централизовать (scope, кодирующий верную колонку) + карта таблица→колонка.' },
  { id: 'DB-3', severity: 'medium', category: 'БД и модели', title: 'Consultant::person() → WebUser (чужое id-пространство)',
    file: 'Models/Consultant.php:64-67',
    problem: 'belongsTo(User, "person") связывает consultant.person (id-пространство person) с WebUser (другое пространство) → вернёт чужой WebUser. Сейчас в коде не используется — латентная ловушка.',
    recommendation: 'Убрать отношение или завести модель Person на таблицу person.' },
  { id: 'DB-4', severity: 'medium', category: 'БД и модели', title: '$guarded=[id] на денежных моделях + нет deletedAt-каста у Commission',
    file: 'Models/{Contract,Transaction,Commission,Client,Consultant}.php',
    problem: '$guarded=[id] открывает mass-assignment всех денежных колонок (сейчас пишут через DB::table(), но любой будущий create($request->all()) пишет суммы). Commission без deletedAt-каста/scope.',
    recommendation: 'Явный $fillable; добавить deletedAt cast+scope Commission.' },
  { id: 'DB-5', severity: 'medium', category: 'БД и модели', title: 'contract.counterpartyContractId без уникального индекса',
    file: 'contract (таблица)',
    problem: 'Идемпотентность импорта/вебхуков держится на app-проверке по number, а Inssmart-номера не уникальны по клиенту (уже была потеря 18481). Повторный postback дублирует контракт.',
    recommendation: 'Partial unique (counterpartyContractId) WHERE NOT NULL.' },
  { id: 'DB-6', severity: 'low', category: 'БД и модели', title: 'Trust-миграция: down() сносит ВСЕ тарифы продукта 102',
    file: 'database/migrations/2026_07_07_000200_configure_investors_trust_tariffs.php:115',
    problem: 'down() делает DELETE FROM dsCommission WHERE product=102 — снесёт и ручные правки после миграции; флаги has_* безусловно в false.',
    recommendation: 'Отслеживать вставленные id (диапазон от MAX(id)); снимать флаги только если их выставила миграция.' },

  // Деньги — ждут финансов
  { id: 'FIN-1', severity: 'high', category: 'Деньги · ждут финансов', title: 'Пул: делитель доли (кумулятивный vs ровно уровень)',
    file: 'PoolCalculator.php:46-51',
    problem: 'Код делит фонд уровня на кумулятивное число партнёров (уровень L и выше), обе спеки — на число ровно этого уровня. Пример спеки (TOP FC=fund/20) не воспроизводится → доли отличаются в разы.',
    recommendation: 'Подтвердить у финансов; привести к спеке (fund / count ровно уровня).' },
  { id: 'FIN-2', severity: 'high', category: 'Деньги · ждут финансов', title: 'Отчёт «Выручка и расходы»: вероятная инверсия метрик',
    file: 'Reports/RevenueExpensesReport.php:24',
    problem: '«Доход» = валовый amountRUB, «Расход» = commissionsAmountRUB (а это Доход ДС в отчёте Комиссии). Выручка компании ушла в «Расход».',
    recommendation: 'Подтвердить определения; поменять местами при необходимости.' },
  { id: 'FIN-3', severity: 'medium', category: 'Деньги · ждут финансов', title: 'Co-founder порог ОП: 100k (код) vs 150k (спека)',
    file: 'MonthlyPenaltyRunner.php:252 / status_levels',
    problem: 'Код/status_levels = 100 000, спека «Расчет вознаграждений» = 150 000. Спеки между собой не согласованы.',
    recommendation: 'Решение финансов, какой порог верен.' },
  { id: 'FIN-4', severity: 'medium', category: 'Деньги · ждут финансов', title: 'Калькулятор объёмов: последний курс + игнор pointsMethod',
    file: 'CalculatorController.php:219,240',
    problem: 'Берёт последний курс вместо курса предыдущего месяца; ЛП жёстко amountNoVat×%ДС/10000, игнорируя program.pointsMethod → расходится с каскадом для нестандартных программ.',
    recommendation: 'Курс предыдущего месяца; учитывать pointsMethod.' },

  // Импорт
  { id: 'IMP-1', severity: 'medium', category: 'Импорт транзакций', title: 'БКС ПИФ: возможный swap amount ↔ commission',
    file: 'SheetProfiles.php (БКС ПИФ)',
    problem: "amount='Выручка MF', commission='Сумма взноса' — похоже на перепутанные (взнос обычно = сумма контракта, выручка MF = комиссия). Проверить по реальному листу.",
    recommendation: 'Подтвердить у бизнеса и поправить маппинг.' },
  { id: 'IMP-2', severity: 'medium', category: 'Импорт транзакций', title: 'Axevil / Woodville: колонка даты без заголовка → дата=now()',
    file: 'SheetProfiles.php (Axevil, Woodville)',
    problem: 'В листах дата в неозаглавленной колонке → профиль её не мапит → транзакции получают дату импорта вместо реальной.',
    recommendation: 'Добавить заголовок в лист или позиционный маппинг даты.' },
  { id: 'IMP-3', severity: 'low', category: 'Импорт транзакций', title: 'GoogleSheetsReader::normalizeRow: «База для начисления комиссии» → ds_percent',
    file: 'GoogleSheetsReader.php:129-139',
    problem: 'В превью-пути колонка со словом «комисс» уходит в ds_percent (робо «База для начисления комиссии»). Импорт (alignRow) не затронут, но превью может показывать неверные колонки.',
    recommendation: 'Уточнить приоритет ключей нормализации или отказаться от normalizeRow в пользу профиля.' },

  // Инфраструктура
  { id: 'INFRA-1', severity: 'high', category: 'Инфраструктура', title: 'Тестовая БД не поднимается — CI не гейтит регрессии',
    file: 'phpunit.xml / .env.testing (newds_test)',
    problem: '61 из 62 тестов падают на подключении к БД. Автотесты не защищают денежные пути.',
    recommendation: 'Поднять newds_test (или SQLite для не-PG тестов), включить в CI.' },
  { id: 'INFRA-2', severity: 'low', category: 'Инфраструктура', title: 'Гонка авто-деплоя (git pull vs webhook, битый build)',
    file: 'deploy webhook (prod)',
    problem: 'При push webhook и ручной pull конкурируют за ref-lock; параллельный npm build ловит rollup «invalid resolved id», оставляет старый CSS.',
    recommendation: 'Сериализовать деплой (lock): pull → дождаться HEAD → build.' },
  { id: 'INFRA-3', severity: 'low', category: 'Инфраструктура', title: 'Стиль: ~306 файлов с отклонениями Pint',
    file: 'app/** (Pint)',
    problem: 'Косметика (отступы/пробелы), зашумляет диффы.',
    recommendation: 'Прогнать vendor/bin/pint отдельным коммитом + pint --test в CI.' },
];

// ── ИСПРАВЛЕНО в этой сессии (08.07.2026) ──
const fixedFindings = [
  { id: 'FX-1', category: 'Бизнес-логика (деньги)', title: 'Брокер+: своя комиссия слетала в 100% после фиксации',
    problem: 'calculateForTransaction не учитывал dsCommissionAbsolute и падал на тариф/100%.',
    recommendation: 'Калькулятор выводит %ДС из dsCommissionAbsolute. Пересчёт июня: Доход ДС 30,4М→144К.' },
  { id: 'FX-2', category: 'Бизнес-логика (деньги)', title: 'ЛП база: Аксвил считал с НДС, Медлайф без НДС',
    problem: 'amount_x_dsPercent брал amountRub (с НДС), default — amountNoVat.',
    recommendation: 'amount_x_dsPercent → amountNoVat (ЛП от Дохода ДС без НДС для всех).' },
  { id: 'FX-3', category: 'Бизнес-логика (деньги)', title: 'Неизвестный ФК: Доход ДС/прибыль не считались',
    problem: 'writeZeroForUnknownConsultant не писал commissionsAmountRUB/profit.',
    recommendation: 'Пишем Доход ДС без НДС; прибыль = Доход ДС без НДС, комиссия 0.' },
  { id: 'FX-4', category: 'Бизнес-логика (деньги)', title: 'Цепочка комиссий: проходные наставники скрывались',
    problem: 'Каскад создавал строку только при марже>0 → одноуровневые наставники (маржа 0) пропадали из «Цепочки выплат».',
    recommendation: 'Строка создаётся для каждого наставника (маржа 0 → комиссия 0, ГП учтён). Деньги не изменились.' },
  { id: 'FX-5', category: 'БД и модели', title: 'Рекуррентные duplicate_pkey (лаг сиквенсов)',
    problem: 'insertGetId contract/person/client врезался в существующий id после restore.',
    recommendation: 'LegacyId::syncSequence() перед вставкой в storeContract/createClient + выравнивание на проде.' },
  { id: 'FX-6', category: 'Безопасность', title: 'Ban-list исключённых при регистрации',
    problem: 'register() не проверял контакты исключённого партнёра.',
    recommendation: 'Жёсткий блок по email/телефону activity=Excluded + warning-лог тёзки.' },
  { id: 'FX-7', category: 'Импорт транзакций', title: 'Робо=Райт: поставщик Тинькофф + кривой тариф/баллы',
    problem: 'Профиль → Тинькофф; программы робо на amount_div_100 + flat 0,5% → минус прибыль.',
    recommendation: 'Поставщик RG.HT; программы 1653/1654/1656 → per-property МФ 0,5%/Апфронт 2% + верные баллы.' },
  { id: 'FX-8', category: 'Импорт транзакций', title: 'IB MF не импортировался (нет строки заголовков)',
    problem: 'Лист без шапки → alignRow не мапил → «0/187 ош.».',
    recommendation: 'Профиль объявляет headerless (позиционные заголовки).' },
  { id: 'FX-9', category: 'Импорт транзакций', title: 'Год КВ не проставлялся при импорте',
    problem: 'ImportTransactionsJob ронял колонку «Год», не писал score.',
    recommendation: 'Год тащится в score.' },
  { id: 'FX-10', category: 'Контроллеры / API', title: 'Дубль номера контракта: ошибка без деталей + дубли транзакций',
    problem: 'Сообщение о дубле без ФИО/продукта; ручной ввод не проверял дубль транзакции.',
    recommendation: 'Ошибка с ФИО+продуктом; анти-дубль по контракт+дата+сумма+валюта.' },
  { id: 'FX-11', category: 'Контроллеры / API', title: 'Статус «Закрыто» требовал прогноз активации',
    problem: 'noForecastStatuses=[1,6,10] без статусов 8 «Закрыто»/9 «Возврат».',
    recommendation: 'Добавлены 8,9 — терминальные статусы прогноз не требуют.' },
  { id: 'FX-12', category: 'Frontend (Vue)', title: 'Обратные слэши в путях API → битый URL',
    problem: "api.get('\\admin\\...') — \\b = backspace.",
    recommendation: 'Прямые слэши.' },
  { id: 'FX-13', category: 'Контроллеры / API', title: 'Мёртвые заглушки отчётов + чистка мёртвых инструментов',
    problem: 'reports()/reportAvailability() «В разработке»; 17 завершённых команд + разовые скрипты.',
    recommendation: 'Заглушки и мёртвые команды/скрипты удалены.' },
  { id: 'FX-14', category: 'БД и модели', title: 'InSmart июнь: перепутанные ФИО клиентов',
    problem: 'Дедуп-по-номеру перезаписал ФИО чужими; 29/45 июньских.',
    recommendation: 'Восстановлено по карте ext→ФИО (repair-june-client-names).' },
];

const view = ref('open');
const filterCategory = ref([]);
const search = ref('');

const categories = ['Безопасность', 'Контроллеры / API', 'Бизнес-логика (деньги)', 'Frontend (Vue)', 'БД и модели', 'Деньги · ждут финансов', 'Импорт транзакций', 'Инфраструктура'];

const allOpen = openFindings.map(f => ({ ...f, status: 'open' }));
const allFixed = fixedFindings.map(f => ({ ...f, status: 'fixed', severity: 'low' }));

const openCount = allOpen.length;
const fixedCount = allFixed.length;

const openBySeverity = computed(() => {
  const c = {};
  for (const f of allOpen) c[f.severity] = (c[f.severity] || 0) + 1;
  return c;
});

const filterActive = computed(() => filterCategory.value.length || (search.value || '').trim());
const sevRank = { critical: 0, high: 1, medium: 2, low: 3 };

const filtered = computed(() => {
  const src = view.value === 'open' ? allOpen : allFixed;
  const q = (search.value || '').toLowerCase().trim();
  return src.filter(f => {
    if (filterCategory.value.length && !filterCategory.value.includes(f.category)) return false;
    if (q && !(`${f.title} ${f.problem} ${f.recommendation} ${f.file || ''} ${f.id}`.toLowerCase().includes(q))) return false;
    return true;
  });
});

const grouped = computed(() => {
  const g = {};
  for (const f of filtered.value) (g[f.category] ||= []).push(f);
  for (const cat of Object.keys(g)) g[cat].sort((a, b) => sevRank[a.severity] - sevRank[b.severity]);
  return g;
});
const groupedCategories = computed(() => categories.filter(c => grouped.value[c]?.length));

const topPriority = computed(() => allOpen.filter(f => f.severity === 'high' || f.severity === 'critical').slice(0, 8));
</script>
