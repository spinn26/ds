<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Consultant;
use App\Models\Requisite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $consultant = Consultant::where('webUser', $user->id)->first();

        $accessCheck = $this->checkAccess($consultant);
        $hasAccess = $accessCheck['hasAccess'] ?? false;

        // Единый источник партнёрской витрины — products_catalog. Legacy
        // `product` остаётся как FK-anchor для contract/dsCommission/etc.,
        // но в UI не светится. Связь с legacy для каждого catalog-продукта
        // — через products_catalog.legacy_product_id (заполняется миграцией
        // 2026_05_28_000010_merge_legacy_into_products_catalog).
        if (! Schema::hasTable('products_catalog')) {
            return response()->json(['products' => [], 'categories' => [], 'accessCheck' => $accessCheck]);
        }

        $query = DB::table('products_catalog')->where('active', true);

        // Фильтр зонтика-видимости (migration 2026_05_28_000030). Оператор
        // может одним кликом скрыть всю продуктовую линейку с витрины,
        // не отключая каждую программу. Schema-guard на случай локальных
        // сред без миграции.
        if (Schema::hasColumn('products_catalog', 'visible_to_resident')) {
            $query->where('visible_to_resident', true);
        }

        // Staff-preview через ?includeDrafts=1: показываем и active=false
        // (на legacy это были drafts) и игнорируем visible_to_resident —
        // staff'у в preview нужно видеть ВСЁ, чтобы проверить.
        $includeDrafts = $request->boolean('includeDrafts')
            && $user->hasAnyRole(['admin', 'backoffice', 'head']);
        if ($includeDrafts) {
            $query = DB::table('products_catalog');
        }

        if ($request->filled('search')) {
            $query->where('name', 'ilike', '%' . $request->search . '%');
        }

        $productRows = $query->orderBy('name')->get();
        if ($productRows->isEmpty()) {
            return response()->json(['products' => [], 'categories' => [], 'accessCheck' => $accessCheck]);
        }

        // Map: catalog.type → стабильный id. Используем для фильтра
        // «Категория» в витрине: партнёрская Products.vue фильтрует
        // categoryOptions через usedIds.filter(Boolean), а без id у
        // product.category селект «Категория» оставался пустым
        // («Отсутствуют данные»). Список категорий формируется один
        // раз — на основе ВСЕХ активных типов в каталоге, а не только
        // отфильтрованных search'ем, чтобы фильтр был стабильным.
        $allTypes = DB::table('products_catalog')
            ->where('active', true)
            ->whereNotNull('type')
            ->where('type', '!=', '')
            ->distinct()
            ->orderBy('type')
            ->pluck('type');
        $typeToId = [];
        $i = 0;
        foreach ($allTypes as $t) {
            $i++;
            $typeToId[$t] = $i;
        }

        // Legacy IDs тех catalog-строк, что слинкованы с legacy `product` —
        // по ним резолвим программы, education_courses и testPassed.
        $legacyIds = $productRows->pluck('legacy_product_id')->filter()->values();

        // Education courses → legacy product.id (FK пока на legacy).
        $coursesByLegacy = collect();
        $completedSet = [];
        if (Schema::hasTable('education_courses') && $legacyIds->isNotEmpty()) {
            $coursesByLegacy = DB::table('education_courses')
                ->where('active', true)
                ->whereNotNull('product_id')
                ->whereIn('product_id', $legacyIds)
                ->get()
                ->groupBy('product_id');

            if (Schema::hasTable('education_course_completions') && $coursesByLegacy->isNotEmpty()) {
                $completedCourseIds = DB::table('education_course_completions')
                    ->where('user_id', $user->id)
                    ->whereIn('course_id', $coursesByLegacy->flatten(1)->pluck('id'))
                    ->pluck('course_id')
                    ->all();
                $completedSet = array_flip($completedCourseIds);
            }
        }

        // Программы / валюты для legacy-слинкованных: тянем из legacy `program`
        // (FK = product.id). Для catalog-only — из programs_catalog.
        $legacyPrograms = $legacyIds->isNotEmpty()
            ? DB::table('program')
                ->whereIn('product', $legacyIds)
                ->whereNull('dateDeleted')
                ->where('active', true)
                ->orderBy('name')
                ->get(['id', 'product', 'name', 'formLink', 'providerName', 'categoryName', 'currency'])
                ->groupBy('product')
            : collect();

        // Программы для витрины — приоритет programs_catalog (там админ
        // правит, в т.ч. formLink и visible_to_resident). Фильтр по
        // visible_to_resident учитывает только переключатель «Виден
        // партнёру», не общую активность — visible_to_calculator не
        // влияет на витрину.
        $catalogProgsQuery = DB::table('programs_catalog')
            ->whereIn('product_id', $productRows->pluck('id'))
            ->where('active', true)
            ->orderBy('name');
        if (Schema::hasColumn('programs_catalog', 'visible_to_resident')) {
            $catalogProgsQuery->where('visible_to_resident', true);
        }
        $catalogProgs = $catalogProgsQuery
            ->get(['id', 'product_id', 'name', 'form_link', 'vendor', 'category', 'currency'])
            ->groupBy('product_id');

        $currencyMap = DB::table('currency')
            ->get(['id', 'nameRu', 'nameEn', 'symbol'])
            ->keyBy('id');

        $products = $productRows->map(function ($p) use ($consultant, $hasAccess, $includeDrafts, $coursesByLegacy, $completedSet, $legacyPrograms, $catalogProgs, $currencyMap, $typeToId) {
            $legacyId = $p->legacy_product_id ? (int) $p->legacy_product_id : null;

            $testPassed = ($consultant && $legacyId)
                ? $this->isTestPassedForProduct($consultant, $legacyId)
                : false;

            $linkedCourses = $legacyId ? ($coursesByLegacy[$legacyId] ?? collect()) : collect();
            // Партнёр в статусе ФК (2) / Резидент (3) НЕ проходит курсы —
            // витрина открыта по активности. Это покрывает и текущих, и тех,
            // кто станет партнёром позже. education_exempt оставлен как
            // grandfather-флаг (например, для не-партнёрских кейсов).
            // Клиент (status=1) — ещё не партнёр, для него гейт по курсам жив.
            $isPartnerStatus = $consultant && in_array((int) ($consultant->status ?? 0), [2, 3], true);
            $exempt = $consultant && ((bool) ($consultant->education_exempt ?? false) || $isPartnerStatus);
            if ($exempt) {
                // Текущие партнёры (education_exempt / статус ФК-Резидент):
                // витрина открыта ВСЯ — без курсов и без проверки активности,
                // продукт можно открыть сразу.
                $available = true;
            } elseif ($linkedCourses->isNotEmpty()) {
                $available = $linkedCourses->every(fn ($c) => isset($completedSet[$c->id]));
            } else {
                $available = $hasAccess;
            }

            // InSmart — доступен сразу после акцепта документов, без обучения.
            if (mb_stripos((string) $p->name, 'insmart') !== false) {
                $available = $consultant && (bool) $consultant->acceptance;
            }

            // Staff-preview (?includeDrafts) — витрина «как у партнёра», но ВСЁ
            // открыто: реквизиты/документы/курсы/активность игнорируются (QA).
            if ($includeDrafts) {
                $available = true;
            }

            // Программы: приоритет programs_catalog (там админ правит,
            // включая formLink и visible_to_resident — см. AdminProductCatalogController).
            // Legacy `program` остаётся fallback'ом только для тех
            // продуктов, у которых в каталоге программ ещё нет вовсе
            // (например, импорт не покрыл).
            $catProgs = $catalogProgs[$p->id] ?? collect();
            if ($catProgs->isNotEmpty()) {
                $programs = $catProgs->map(fn ($pr) => [
                    'id' => self::CATALOG_ID_OFFSET + (int) $pr->id,
                    'name' => $pr->name,
                    'formLink' => $pr->form_link ?? null,
                    'providerName' => $pr->vendor,
                    'categoryName' => $pr->category,
                    'currencySymbol' => $pr->currency,
                ])->values();
                $currencies = $catProgs->pluck('currency')->filter()->unique()->values()
                    ->map(fn ($s) => ['id' => null, 'nameRu' => $s, 'nameEn' => $s, 'symbol' => $s]);
            } elseif ($legacyId && isset($legacyPrograms[$legacyId])) {
                $programs = $legacyPrograms[$legacyId]->map(function ($pr) use ($currencyMap) {
                    $cur = $pr->currency ? ($currencyMap[$pr->currency] ?? null) : null;
                    return [
                        'id' => $pr->id,
                        'name' => $pr->name,
                        'formLink' => $pr->formLink ?? null,
                        'providerName' => $pr->providerName ?? null,
                        'categoryName' => $pr->categoryName ?? null,
                        'currencySymbol' => $cur->symbol ?? null,
                    ];
                })->values();
                $currencies = $legacyPrograms[$legacyId]
                    ->pluck('currency')->filter()->unique()->values()
                    ->map(function ($cid) use ($currencyMap) {
                        $c = $currencyMap[$cid] ?? null;
                        return $c ? ['id' => $c->id, 'nameRu' => $c->nameRu, 'nameEn' => $c->nameEn, 'symbol' => $c->symbol] : null;
                    })->filter()->values();
            } else {
                $programs = collect();
                $currencies = collect();
            }

            // ID для фронта: предпочитаем legacy.product_id (там сидят FK
            // soldProducts / education_courses), для catalog-only — оффсет.
            $apiId = $legacyId ?? (self::CATALOG_ID_OFFSET + (int) $p->id);

            return [
                'id' => $apiId,
                'name' => $p->name,
                'description' => $p->description ?? null,
                'typeName' => $p->type ?? null,
                'active' => (bool) $p->active,
                'accessible' => $available,
                'available' => $available,
                'url' => $p->open_product_url ?? null,
                'imageUrl' => $p->image_url ?? null,
                'heroImage' => $p->hero_image ?? null,
                'publishStatus' => $p->active ? 'published' : 'draft',
                'educationUrl' => null,
                'instructionUrl' => null,
                'testPassed' => $testPassed,
                'category' => $p->type ? ['id' => $typeToId[$p->type] ?? null, 'name' => $p->type] : null,
                'currencies' => $currencies,
                'programs' => $programs,
                'requiredCourses' => $linkedCourses->map(fn ($c) => [
                    'id' => $c->id,
                    'title' => $c->title,
                    'completed' => isset($completedSet[$c->id]),
                ])->values(),
            ];
        });

        // Categories list для фильтра витрины — используем тот же $typeToId,
        // что и в product.category, чтобы id-ы совпадали и фильтр
        // «Категория» реально работал (раньше у product.category.id=null,
        // а у categories[].id=1..N — селект показывал «Отсутствуют данные»).
        $categories = collect($typeToId)
            ->map(fn ($id, $name) => ['id' => $id, 'name' => $name])
            ->values();

        return response()->json([
            'products' => $products,
            'categories' => $categories,
            'accessCheck' => $accessCheck,
        ]);
    }

    /**
     * Offset added to products_catalog.id when the catalog row is not
     * linked to a legacy product. Keeps the namespace clean — legacy ids
     * stay <100k, catalog-only ids start at CATALOG_ID_OFFSET.
     */
    private const CATALOG_ID_OFFSET = 1_000_000;

    /**
     * Партнёрская проверка ИНН через DaData (для формы блокирующего
     * окна в /products). В отличие от admin-варианта не требует
     * существующего requisite — идёт по введённому ИНН + сверяет ФИО
     * с профилем текущего пользователя.
     */
    public function checkInn(Request $request): JsonResponse
    {
        $data = $request->validate(['inn' => 'required|string|max:20']);

        $dadata = app(\App\Services\DadataService::class);
        $result = $dadata->findByInn($data['inn']);

        if (! empty($result['found'])) {
            $user = $request->user();
            $result['fioCheck'] = $dadata->compareFio(
                $result['fio'],
                $user->lastName ?? null,
                $user->firstName ?? null,
                $user->patronymic ?? null,
            );
        }

        return response()->json($result);
    }

    /**
     * Партнёрский setup реквизитов (ИНН + банк) с витрины.
     *
     * Per spec ✅Реквизиты §1.3 «Автоматическая сверка с ФНС (API)»:
     *   — ИНН: строго 10 цифр (ООО/ЮЛ) или 12 цифр (ИП).
     *   — Сверка ФИО из ЕГРИП с ФИО в профиле побуквенно, без учёта
     *     регистра. Расхождение → ручная проверка.
     *   — Ввод ИНН ЮЛ (ООО, 10 цифр) → ручная проверка (нужен бенефициар).
     *   — Auto-verify ТОЛЬКО для ИП (12 цифр) при совпавшем ФИО.
     *
     * Решение принимает сервер: фронт-флаг fioMatched игнорируется
     * (это анти-fraud — пользователь не может выставить себе verified=true).
     */
    public function setupRequisites(Request $request): JsonResponse
    {
        $data = $request->validate([
            'inn' => 'required|string|max:20',
            'bankName' => 'required|string|max:200',
            'bankBik' => 'required|string|max:20',
            'accountNumber' => 'required|string|max:40',
            // fioMatched от клиента принимаем «для совместимости», но
            // НЕ используем — серверная сверка через DadataService ниже.
            'fioMatched' => 'nullable|boolean',
        ]);

        $consultant = Consultant::where('webUser', $request->user()->id)->first();
        if (! $consultant) {
            return response()->json(['message' => 'Консультант не найден'], 404);
        }

        // После верификации реквизиты редактировать нельзя (анти-fraud, как в
        // ProfileController). Иначе verified-партнёр мог бы сменить банковские
        // реквизиты через попап витрины в обход блокировки в профиле.
        $verifiedExisting = \App\Models\Requisite::where('consultant', $consultant->id)
            ->whereNull('deletedAt')->where('verified', true)->exists();
        if ($verifiedExisting) {
            return response()->json([
                'message' => 'Реквизиты подтверждены и не могут быть изменены. Для изменения обратитесь в поддержку.',
            ], 422);
        }

        $innClean = preg_replace('/\D/', '', $data['inn']);
        if (strlen($innClean) !== 10 && strlen($innClean) !== 12) {
            return response()->json([
                'message' => 'ИНН должен быть 10 цифр (для ООО) или 12 цифр (для ИП).',
            ], 422);
        }

        // Серверная сверка с ФНС через DaData (ЕГРИП/ЕГРЮЛ).
        $dadata = app(\App\Services\DadataService::class);
        $fns = $dadata->findByInn($innClean);
        if (empty($fns['found'])) {
            return response()->json([
                'message' => $fns['error'] ?? 'Не удалось найти ИНН в ЕГРИП/ЕГРЮЛ.',
            ], 422);
        }
        if (($fns['status'] ?? null) === 'LIQUIDATED') {
            return response()->json([
                'message' => 'По данным ФНС, этот ИНН ликвидирован. Используйте действующий ИНН.',
            ], 422);
        }

        $isIndividual = ($fns['type'] ?? null) === 'INDIVIDUAL';
        $fioCheck = $isIndividual
            ? $dadata->compareFio(
                $fns['fio'] ?? null,
                $request->user()->lastName ?? null,
                $request->user()->firstName ?? null,
                $request->user()->patronymic ?? null,
            )
            : ['match' => false];

        // Решение от 2026-05-27: auto-verify ПОЛНОСТЬЮ отключён.
        // DaData возвращает только статус «ИП» (тип записи в ЕГРИП), но
        // НЕ режим налогообложения (УСН/ОСН/Патент/НПД) — он хранится в
        // другом реестре ФНС, к которому у нас нет интеграции. Партнёр
        // обязан быть ИП на УСН, поэтому пока проверка УСН недоступна —
        // ВСЕ реквизиты уходят на ручную верификацию финменеджеру.
        $autoVerify = false;

        if (! $isIndividual) {
            $manualReason = 'ИНН юр. лица (ООО) — требуется ручная проверка бенефициара.';
        } elseif (! ($fioCheck['match'] ?? false)) {
            $manualReason = sprintf(
                'ФИО из ЕГРИП («%s») не совпадает с профилем («%s»). Требуется ручная проверка.',
                $fioCheck['actual'] ?? '—',
                $fioCheck['expected'] ?? '—',
            );
        } else {
            $manualReason = 'Подтверждение режима УСН возможно только вручную (нет API ФНС по налоговому режиму).';
        }

        $requisite = \Illuminate\Support\Facades\DB::transaction(function () use ($consultant, $innClean, $data, $autoVerify, $fns) {
            $req = \App\Models\Requisite::where('consultant', $consultant->id)
                ->whereNull('deletedAt')
                ->first();

            if (! $req) {
                $req = new \App\Models\Requisite();
                $req->consultant = $consultant->id;
            }
            $req->inn = $innClean;
            // Сохраняем подтверждённые ФНС данные — оператору не нужно
            // вытаскивать из DaData повторно при ручной проверке.
            if (! empty($fns['name'])) {
                $req->individualEntrepreneur = mb_substr($fns['name'], 0, 255);
            }
            if (! empty($fns['ogrn'])) $req->ogrn = $fns['ogrn'];
            if (! empty($fns['address'])) $req->address = mb_substr($fns['address'], 0, 500);
            if (! empty($fns['registrationDate'])) $req->registrationDate = $fns['registrationDate'];

            $req->verified = $autoVerify;
            // status — FK на status_requisites: 1=backoffice, 2=consultant, 3=verified.
            $req->status = $autoVerify ? 3 : 1;
            // dateChange — каноничная «дата поступления на проверку» (раньше
            // этот путь её не писал → SLA-таймер не стартовал). Сбрасываем
            // метку уведомления, чтобы новый цикл проверки взвёлся заново.
            $req->dateChange = now();
            $req->overdue_notified_at = null;
            $req->save();

            // Реальная таблица — bankrequisites (lowercase). Laravel
            // квотирует имя как есть, поэтому camelCase ломает Postgres.
            //
            // Получатель платежа (beneficiary) ЖЁСТКО проставляется бэком
            // = данные из ЕГРИП/ЕГРЮЛ по введённому ИНН. У нас запрещены
            // выплаты на третьих лиц — партнёр не может через DevTools
            // или прямой API-запрос указать чужие beneficiaryName/Inn.
            // Финменеджер при ручной проверке всё равно увидит, что
            // beneficiary == владелец ИНН.
            \Illuminate\Support\Facades\DB::table('bankrequisites')->updateOrInsert(
                ['requisites' => $req->id, 'deletedAt' => null],
                [
                    'bankName' => $data['bankName'],
                    'bankBik' => $data['bankBik'],
                    'accountNumber' => $data['accountNumber'],
                    'beneficiaryName' => $fns['name'] ?? null,
                    'beneficiaryInn' => $fns['inn'] ?? $innClean,
                    'verified' => $autoVerify,
                ],
            );

            if ($autoVerify) {
                $consultant->statusRequisites = 3;
                $consultant->save();
            }

            return $req;
        });

        // Если не auto-verify — создаём тикет финменеджеру с причиной.
        if (! $autoVerify && \Schema::hasTable('tickets')) {
            \Illuminate\Support\Facades\DB::table('tickets')->insert([
                'subject' => 'Проверка реквизитов партнёра: ' . ($manualReason ?? 'ручная сверка'),
                'context_info' => "Партнёр {$consultant->personName} (ID {$consultant->id}) ввёл ИНН {$innClean}.\n"
                    . "ФНС: {$fns['name']} (ОГРН {$fns['ogrn']})\n"
                    . "Причина: {$manualReason}",
                'category' => 'accruals',
                'status' => 'open',
                'priority' => 'high',
                'created_by' => $request->user()->id,
                'consultant_id' => $consultant->id,
                'context_type' => 'requisites',
                'context_id' => (string) $requisite->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return response()->json([
            // Auto-verify отключён — единый ответ для всех кейсов.
            'message' => 'Реквизиты сохранены. Ожидайте проверки документов финменеджером.',
            'verified' => false,
            'pending' => true,
            'manualReason' => $manualReason,
            'requisiteId' => $requisite->id,
            'fns' => [
                'name' => $fns['name'] ?? null,
                'type' => $fns['type'] ?? null,
                'status' => $fns['status'] ?? null,
            ],
        ]);
    }

    private function checkAccess(?Consultant $consultant): array
    {
        if (! $consultant) {
            return [
                'hasAccess' => false,
                'testsPassed' => false,
                'requisitesVerified' => false,
                'documentsAccepted' => false,
            ];
        }

        // Любому активному ФК открываем продукты — тестов/реквизитов/акцепта не требуем.
        // testsPassed/requisitesVerified/documentsAccepted считаем справочно — фронт
        // их может показывать в подсказках, но гейт теперь только по активности.
        $isActive = (bool) $consultant->active;

        $testsPassed = ! empty($consultant->soldProducts);

        $requisitesVerified = ((int) $consultant->statusRequisites) === 3;
        $requisitesSubmitted = $requisitesVerified;
        if (Schema::hasTable('requisites')) {
            // ЛЮБАЯ не-удалённая запись = «партнёр уже заполнил».
            // Даже если verified=false и ждёт ручной проверки финменеджера —
            // не показываем блокирующий диалог повторно. Иначе при каждом
            // входе после ввода чужого/невалидного ИНН (когда ФИО не совпало
            // с профилем и auto-verify=false) платформа просит вводить заново
            // и партнёр впадает в петлю.
            $requisitesSubmitted = $requisitesSubmitted || Requisite::where('consultant', $consultant->id)
                ->whereNull('deletedAt')
                ->exists();
            if (! $requisitesVerified) {
                $requisitesVerified = Requisite::where('consultant', $consultant->id)
                    ->whereNull('deletedAt')
                    ->where('verified', true)
                    ->exists();
            }
        }

        $documentsAccepted = (bool) $consultant->acceptance;

        return [
            'hasAccess' => $isActive,
            'testsPassed' => $testsPassed,
            'requisitesVerified' => $requisitesVerified,
            'requisitesSubmitted' => $requisitesSubmitted,
            'documentsAccepted' => $documentsAccepted,
            'needsRequisites' => false,
            'needsAcceptance' => false,
        ];
    }

    private function isTestPassedForProduct(Consultant $consultant, int $productId): bool
    {
        $soldProducts = $consultant->soldProducts ?? '';
        if (empty($soldProducts)) {
            return false;
        }

        $passedIds = array_map('intval', explode(',', (string) $soldProducts));

        return in_array($productId, $passedIds);
    }
}
