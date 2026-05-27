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

        $query = DB::table('product')->where('active', true);

        // Партнёр видит только published; staff-preview проходит через
        // этот же endpoint но с ?includeDrafts=1 (роут защищён ролью).
        if (! $request->boolean('includeDrafts') || ! $user->hasAnyRole(['admin', 'backoffice', 'head'])) {
            if (Schema::hasColumn('product', 'publish_status')) {
                $query->where('publish_status', 'published');
            }
        }

        if ($request->filled('search')) {
            $query->where('name', 'ilike', '%' . $request->search . '%');
        }

        // Preload productType → productCategory mapping
        $typeToCategory = DB::table('productType')
            ->whereNotNull('productTypeCategory')
            ->pluck('productTypeCategory', 'id');

        $allCategories = DB::table('productCategory')
            ->get()
            ->keyBy('id');

        $hasAccess = $this->checkAccess($consultant)['hasAccess'] ?? false;

        // Партнёрская витрина: убираем продукты-архив (priority IS NULL)
        // — у них visibleToResident=false выставлен, но дополнительно
        // фильтруем по priority на случай ручных правок в админке.
        // Сортировка ASC: priority 1 → 2 → 3, имя по алфавиту внутри.
        if (Schema::hasColumn('product', 'priority')) {
            $query->whereNotNull('priority');
            $productRows = $query
                ->orderByRaw('COALESCE(priority, 99) ASC')
                ->orderBy('name')
                ->get();
        } else {
            $productRows = $query->orderBy('name')->get();
        }

        // Gate products by education-course completion:
        // if a product has an active linked course, it's only "available" when
        // the user has a completion record for EVERY such course.
        // Products with no linked course fall back to the legacy gate ($hasAccess).
        $coursesByProduct = collect();
        $completedCourseIds = [];
        if (Schema::hasTable('education_courses') && $productRows->isNotEmpty()) {
            $coursesByProduct = DB::table('education_courses')
                ->where('active', true)
                ->whereNotNull('product_id')
                ->whereIn('product_id', $productRows->pluck('id'))
                ->get()
                ->groupBy('product_id');

            if (Schema::hasTable('education_course_completions') && $coursesByProduct->isNotEmpty()) {
                $completedCourseIds = DB::table('education_course_completions')
                    ->where('user_id', $user->id)
                    ->whereIn('course_id', $coursesByProduct->flatten(1)->pluck('id'))
                    ->pluck('course_id')
                    ->all();
            }
        }
        $completedSet = array_flip($completedCourseIds);

        // Currencies per-product: продукт сам не хранит валюту, валюты
        // находятся в привязанных программах. Собираем distinct по product_id.
        $productCurrencies = DB::table('program')
            ->whereIn('product', $productRows->pluck('id'))
            ->whereNotNull('currency')
            ->orderBy('product')
            ->get(['product', 'currency'])
            ->groupBy('product');

        // Programs per-product: для модалки «Программы продукта» на витрине.
        // Только активные и не удалённые, в порядке имени.
        $productPrograms = DB::table('program')
            ->whereIn('product', $productRows->pluck('id'))
            ->whereNull('dateDeleted')
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'product', 'name', 'formLink', 'providerName', 'categoryName', 'currency'])
            ->groupBy('product');

        $currencyMap = DB::table('currency')
            ->get(['id', 'nameRu', 'nameEn', 'symbol'])
            ->keyBy('id');

        $products = $productRows->map(function ($p) use ($consultant, $typeToCategory, $allCategories, $hasAccess, $coursesByProduct, $completedSet, $productCurrencies, $currencyMap, $productPrograms) {
            $testPassed = $consultant
                ? $this->isTestPassedForProduct($consultant, $p->id)
                : false;

            // Resolve category via productType
            $categoryId = $p->productType ? ($typeToCategory[$p->productType] ?? null) : null;
            $cat = $categoryId ? ($allCategories[$categoryId] ?? null) : null;

            $linkedCourses = $coursesByProduct[$p->id] ?? collect();
            if ($linkedCourses->isNotEmpty()) {
                $allPassed = $linkedCourses->every(fn ($c) => isset($completedSet[$c->id]));
                $available = $allPassed;
            } else {
                $available = $hasAccess;
            }

            // InSmart — исключение по спеке: доступен сразу после акцепта
            // документов, обучение не требуется. Если акцепт есть → available.
            if (mb_stripos((string) $p->name, 'insmart') !== false) {
                $available = $consultant && (bool) $consultant->acceptance;
            }

            // Currencies attached to this product (distinct by program)
            $currencyIds = ($productCurrencies[$p->id] ?? collect())->pluck('currency')->unique()->values();
            $currencies = $currencyIds->map(function ($cid) use ($currencyMap) {
                $c = $currencyMap[$cid] ?? null;
                if (! $c) return null;
                return ['id' => $c->id, 'nameRu' => $c->nameRu, 'nameEn' => $c->nameEn, 'symbol' => $c->symbol];
            })->filter()->values();

            // Programs списком — фронт показывает их в модалке при клике
            // «Открыть продукт». Если массив пуст — fallback на product.url.
            $programs = ($productPrograms[$p->id] ?? collect())->map(function ($pr) use ($currencyMap) {
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

            return [
                'id' => $p->id,
                'name' => $p->name,
                'description' => $p->description ?? null,
                'typeName' => $p->typeName ?? null,
                'active' => (bool) $p->active,
                'accessible' => $available,
                'available' => $available,
                'url' => $p->openProductUrl ?? null,
                'imageUrl' => $p->imageUrl ?? null,
                'heroImage' => $p->hero_image ?? null,
                'publishStatus' => $p->publish_status ?? 'published',
                'educationUrl' => $p->educationUrl ?? null,
                'instructionUrl' => $p->instructionUrl ?? null,
                'testPassed' => $testPassed,
                'category' => $cat ? [
                    'id' => $cat->id,
                    'name' => $cat->productCategoryName,
                ] : null,
                'currencies' => $currencies,
                'programs' => $programs,
                'requiredCourses' => $linkedCourses->map(fn ($c) => [
                    'id' => $c->id,
                    'title' => $c->title,
                    'completed' => isset($completedSet[$c->id]),
                ])->values(),
            ];
        });

        // Audit-driven catalog products (products_catalog + programs_catalog).
        // They sit alongside legacy products without disturbing them — every
        // catalog product gets its id shifted by CATALOG_ID_OFFSET so it can
        // never collide with a legacy product.id.  Most rich fields stay
        // null because the audit catalog doesn't carry images/descriptions
        // yet — that's fine, the partner cards still render.
        $catalogProducts = $this->mapCatalogProducts($request, $hasAccess);

        $allProducts = $products->concat($catalogProducts);

        // Categories from productCategory table
        $categories = DB::table('productCategory')
            ->orderBy('productCategoryName')
            ->get()
            ->map(fn ($c) => ['id' => $c->id, 'name' => $c->productCategoryName]);

        return response()->json([
            'products' => $allProducts,
            'categories' => $categories,
            'accessCheck' => $accessCheck,
        ]);
    }

    /**
     * Offset added to products_catalog.id when surfacing through this
     * partner endpoint. Keeps the namespace clean of any collision with
     * legacy product.id values (max ~94 today, room to spare).
     */
    private const CATALOG_ID_OFFSET = 1_000_000;

    /**
     * Build partner-shaped product cards from products_catalog / programs_catalog.
     * No legacy table joins — just the audit-driven catalog.
     */
    private function mapCatalogProducts(Request $request, bool $hasAccess)
    {
        $q = DB::table('products_catalog as p')
            ->leftJoin('programs_catalog as g', 'g.product_id', '=', 'p.id')
            ->where('p.active', true)
            ->groupBy('p.id', 'p.name', 'p.type', 'p.created_at')
            ->select([
                'p.id', 'p.name', 'p.type', 'p.created_at',
                DB::raw('COUNT(g.id) AS programs_count'),
                DB::raw('COUNT(g.id) FILTER (WHERE g.active=true) AS programs_active'),
            ]);

        if ($search = trim((string) $request->input('search', ''))) {
            $q->where('p.name', 'ilike', "%{$search}%");
        }

        $products = $q->orderBy('p.name')->get();
        if ($products->isEmpty()) {
            return collect();
        }

        $programs = DB::table('programs_catalog')
            ->whereIn('product_id', $products->pluck('id'))
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'product_id', 'name', 'vendor', 'currency', 'category', 'has_red'])
            ->groupBy('product_id');

        return $products->map(function ($p) use ($programs, $hasAccess) {
            $progList = ($programs[$p->id] ?? collect())->map(fn ($pr) => [
                'id'             => self::CATALOG_ID_OFFSET + (int) $pr->id,
                'name'           => $pr->name,
                'formLink'       => null,
                'providerName'   => $pr->vendor,
                'categoryName'   => $pr->category,
                'currencySymbol' => $pr->currency,
            ])->values();

            // Distinct currencies pulled directly from the catalog rows
            // (string-typed; no FK into currency table).
            $currencies = $progList->pluck('currencySymbol')->filter()->unique()->values()
                ->map(fn ($s) => ['id' => null, 'nameRu' => $s, 'nameEn' => $s, 'symbol' => $s]);

            return [
                'id'              => self::CATALOG_ID_OFFSET + (int) $p->id,
                'name'            => $p->name,
                'description'     => null,
                'typeName'        => $p->type,
                'active'          => true,
                'accessible'      => $hasAccess,
                'available'       => $hasAccess,
                'url'             => null,
                'imageUrl'        => null,
                'heroImage'       => null,
                'publishStatus'   => 'published',
                'educationUrl'    => null,
                'instructionUrl'  => null,
                'testPassed'      => false,
                'category'        => $p->type ? ['id' => null, 'name' => $p->type] : null,
                'currencies'      => $currencies,
                'programs'        => $progList,
                'requiredCourses' => collect(),
                'source'          => 'catalog',
            ];
        });
    }

    /**
     * Партнёр принимает обязательные документы перед покупкой продукта.
     * Проставляется consultant.acceptance = true; с этого момента
     * checkAccess() возвращает documentsAccepted = true.
     */
    public function acceptDocuments(Request $request): JsonResponse
    {
        $consultant = Consultant::where('webUser', $request->user()->id)->first();
        if (! $consultant) {
            return response()->json(['message' => 'Консультант не найден'], 404);
        }

        $consultant->acceptance = true;
        $consultant->save();

        return response()->json(['message' => 'Документы приняты', 'documentsAccepted' => true]);
    }

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

        // Auto-verify ТОЛЬКО если: тип = ИП (12 цифр) И ФИО совпало.
        // ООО (10 цифр) всегда уходит на ручную проверку — нужен
        // отдельный регламент сверки бенефициара.
        $autoVerify = $isIndividual && ! empty($fioCheck['match']);

        $manualReason = null;
        if (! $isIndividual) {
            $manualReason = 'ИНН юр. лица (ООО) — требуется ручная проверка бенефициара.';
        } elseif (! ($fioCheck['match'] ?? false)) {
            $manualReason = sprintf(
                'ФИО из ЕГРИП («%s») не совпадает с профилем («%s»).',
                $fioCheck['actual'] ?? '—',
                $fioCheck['expected'] ?? '—',
            );
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
            'message' => $autoVerify
                ? 'Реквизиты автоматически верифицированы по данным ФНС.'
                : 'Реквизиты сохранены. ' . $manualReason . ' Ожидают проверки финменеджером.',
            'verified' => $autoVerify,
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
