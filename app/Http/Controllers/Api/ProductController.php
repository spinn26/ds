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
        $productRows = $query->orderBy('name')->get();

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

        $currencyMap = DB::table('currency')
            ->get(['id', 'nameRu', 'nameEn', 'symbol'])
            ->keyBy('id');

        $products = $productRows->map(function ($p) use ($consultant, $typeToCategory, $allCategories, $hasAccess, $coursesByProduct, $completedSet, $productCurrencies, $currencyMap) {
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
                'requiredCourses' => $linkedCourses->map(fn ($c) => [
                    'id' => $c->id,
                    'title' => $c->title,
                    'completed' => isset($completedSet[$c->id]),
                ])->values(),
            ];
        });

        // Categories from productCategory table
        $categories = DB::table('productCategory')
            ->orderBy('productCategoryName')
            ->get()
            ->map(fn ($c) => ['id' => $c->id, 'name' => $c->productCategoryName]);

        return response()->json([
            'products' => $products,
            'categories' => $categories,
            'accessCheck' => $accessCheck,
        ]);
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
     * Если ФИО ИП совпало с профилем — авто-верификация;
     * иначе создаётся тикет финменеджеру.
     */
    public function setupRequisites(Request $request): JsonResponse
    {
        $data = $request->validate([
            'inn' => 'required|string|max:20',
            'bankName' => 'required|string|max:200',
            'bankBik' => 'required|string|max:20',
            'accountNumber' => 'required|string|max:40',
            'fioMatched' => 'nullable|boolean',
        ]);

        $consultant = Consultant::where('webUser', $request->user()->id)->first();
        if (! $consultant) {
            return response()->json(['message' => 'Консультант не найден'], 404);
        }

        $innClean = preg_replace('/\D/', '', $data['inn']);
        $autoVerify = (bool) ($data['fioMatched'] ?? false);

        $requisite = \Illuminate\Support\Facades\DB::transaction(function () use ($consultant, $innClean, $data, $autoVerify) {
            $req = \App\Models\Requisite::where('consultant', $consultant->id)
                ->whereNull('deletedAt')
                ->first();

            if (! $req) {
                $req = new \App\Models\Requisite();
                $req->consultant = $consultant->id;
            }
            $req->inn = $innClean;
            $req->verified = $autoVerify;
            $req->statusName = $autoVerify ? 'verified' : 'pending';
            $req->save();

            \Illuminate\Support\Facades\DB::table('bankRequisites')->updateOrInsert(
                ['requisites' => $req->id, 'deletedAt' => null],
                [
                    'bankName' => $data['bankName'],
                    'bankBik' => $data['bankBik'],
                    'accountNumber' => $data['accountNumber'],
                    'verified' => $autoVerify,
                ],
            );

            if ($autoVerify) {
                $consultant->statusRequisites = 3;
                $consultant->save();
            }

            return $req;
        });

        // Если ФИО не совпало — создать тикет финменеджеру (если есть таблица)
        if (! $autoVerify && \Schema::hasTable('tickets')) {
            \Illuminate\Support\Facades\DB::table('tickets')->insert([
                'title' => 'Проверка реквизитов партнёра: ФИО не совпадает с ИНН',
                'description' => "Партнёр {$consultant->personName} (ID {$consultant->id}) ввёл ИНН {$innClean}, "
                    . 'который отличается по ФИО от профиля. Требуется ручная проверка.',
                'category' => 'accruals',
                'status' => 'new',
                'created_by' => $request->user()->id,
                'assigned_to' => null,
                'priority' => 'high',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return response()->json([
            'message' => $autoVerify
                ? 'Реквизиты автоматически верифицированы'
                : 'Реквизиты сохранены. Ожидают проверки финменеджером.',
            'verified' => $autoVerify,
            'requisiteId' => $requisite->id,
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
        if (! $requisitesVerified && Schema::hasTable('requisites')) {
            $requisitesVerified = Requisite::where('consultant', $consultant->id)
                ->whereNull('deletedAt')
                ->where('verified', true)
                ->exists();
        }

        $documentsAccepted = (bool) $consultant->acceptance;

        return [
            'hasAccess' => $isActive,
            'testsPassed' => $testsPassed,
            'requisitesVerified' => $requisitesVerified,
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
