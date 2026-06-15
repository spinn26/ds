<?php

namespace App\Http\Controllers\Api;

use App\Enums\PartnerActivity;
use App\Http\Controllers\Controller;
use App\Services\XlsxExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Управление первичной анкетой партнёра (бэкграунд / опыт).
 *
 * Анкета хранится прямо в WebUser (10 полей, см.
 * 2026_04_17_000010_add_questionnaire_fields_to_webuser.php). Куратор
 * обучения использует её для оценки бэкграунда новичков и
 * формирования групп. Здесь — список / просмотр / CSV-выгрузка.
 */
class AdminQuestionnaireController extends Controller
{
    /** Поля анкеты в порядке для отображения / CSV. */
    private const FIELDS = [
        'workField'             => 'Сфера работы',
        'salesExperience'       => 'Опыт в продажах',
        'financeExperience'     => 'Опыт в финансах',
        'hasPotentialClients'   => 'Потенциальные клиенты',
        'potentialClientsCount' => 'Кол-во клиентов',
        'currentIncome'         => 'Текущий доход',
        'weeklyHours'           => 'Часов в неделю',
        'incomeFactors'         => 'От чего зависит доход',
    ];

    /** Перевод коротких enum-значений → читаемые строки для экспорта. */
    private const ENUM_LABELS = [
        'salesExperience'       => ['none' => 'Нет', '<1' => 'До 1 года', '1-3' => '1–3 года', '3+' => 'Более 3 лет'],
        'hasPotentialClients'   => ['yes' => 'Да', 'partly' => 'Частично', 'no' => 'Нет'],
        'potentialClientsCount' => ['<10' => 'До 10', '10-30' => '10–30', '30-100' => '30–100', '100+' => 'Более 100'],
        'weeklyHours'           => ['<10' => 'До 10 ч', '10-20' => '10–20 ч', '20-40' => '20–40 ч', 'full-time' => 'Полный день'],
    ];

    /**
     * GET /admin/partners/questionnaires
     *
     * Список заполненных анкет с пагинацией и поиском по ФИО / e-mail.
     * `only_completed=1` — отбрасывает партнёров без даты заполнения.
     */
    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->input('search', ''));
        $only = (bool) $request->input('only_completed', true);
        $status = $request->filled('status') ? (int) $request->input('status') : null;
        $from = $request->filled('date_from') ? (string) $request->input('date_from') : null;
        $to = $request->filled('date_to') ? (string) $request->input('date_to') : null;
        $page = max(1, (int) $request->input('page', 1));
        $per = min(100, max(10, (int) $request->input('per', 25)));

        $q = $this->baseQuery($search, $only, $status, $from, $to);
        $total = (clone $q)->count();
        $rows = $q
            ->orderByDesc('w.questionnaireCompletedAt')
            ->orderBy('w.lastName')
            ->forPage($page, $per)
            ->get();

        $cityIds = $rows->pluck('city')->filter()->unique()->values()->all();
        $cities = $cityIds
            ? DB::table('city')->whereIn('id', $cityIds)->pluck('cityNameRu', 'id')
            : collect();

        return response()->json([
            'data' => $rows->map(fn ($r) => $this->mapRow($r, $cities))->values(),
            'total' => $total,
        ]);
    }

    /**
     * GET /admin/partners/{id}/questionnaire
     *
     * Полная анкета одного партнёра — для модального окна на странице
     * «Анкеты партнёров».
     */
    public function show(int $id): JsonResponse
    {
        $row = DB::table('WebUser')
            ->where('id', $id)
            ->whereNull('dateDeleted')
            ->first();

        if (! $row) {
            return response()->json(['message' => 'Партнёр не найден'], 404);
        }

        $cities = $row->city
            ? DB::table('city')->where('id', $row->city)->pluck('cityNameRu', 'id')
            : collect();

        // Статус берём из consultant по тому же FK, что и в списке.
        $row->partnerActivity = DB::table('consultant')
            ->where('webUser', $id)
            ->whereNull('dateDeleted')
            ->value('activity');

        return response()->json($this->mapRow($row, $cities));
    }

    /**
     * GET /admin/partners/questionnaires/export
     *
     * Стилизованный XLSX. Куратор открывает его в Excel —
     * шапка с фиксацией, autofilter, авто-ширины, дата отформатирована.
     */
    public function export(Request $request, XlsxExportService $xlsx): StreamedResponse
    {
        $search = trim((string) $request->input('search', ''));
        $only = (bool) $request->input('only_completed', true);
        $status = $request->filled('status') ? (int) $request->input('status') : null;
        $from = $request->filled('date_from') ? (string) $request->input('date_from') : null;
        $to = $request->filled('date_to') ? (string) $request->input('date_to') : null;

        $dbRows = $this->baseQuery($search, $only, $status, $from, $to)
            ->orderByDesc('w.questionnaireCompletedAt')
            ->orderBy('w.lastName')
            ->get();

        $headers = ['ФИО', 'E-mail', 'Телефон', 'Город', 'Заполнено', 'Статус'];
        foreach (self::FIELDS as $label) $headers[] = $label;

        $cityIds = $dbRows->pluck('city')->filter()->unique()->values()->all();
        $cities = $cityIds
            ? DB::table('city')->whereIn('id', $cityIds)->pluck('cityNameRu', 'id')
            : collect();

        $rows = $dbRows->map(function ($r) use ($cities) {
            $cityName = $r->city ? ($cities[$r->city] ?? $r->city) : '';
            $line = [
                trim(($r->lastName ?? '') . ' ' . ($r->firstName ?? '') . ' ' . ($r->patronymic ?? '')),
                $r->email ?? '',
                $r->phone ?? '',
                $cityName,
                $r->questionnaireCompletedAt ?? '',
                $this->statusLabel($r->partnerActivity ?? null),
            ];
            foreach (array_keys(self::FIELDS) as $f) {
                $val = $r->{$f} ?? '';
                $line[] = self::ENUM_LABELS[$f][$val] ?? $val;
            }
            return $line;
        })->all();

        return $xlsx->stream(
            'partner-questionnaires-' . now()->format('Y-m-d'),
            'Анкеты партнёров',
            $headers,
            $rows,
            ['dateColumns' => [5]]
        );
    }

    /**
     * Базовый запрос со всеми фильтрами.
     *
     * @param  string    $search    поиск по ФИО / e-mail / телефону
     * @param  bool      $only      только с заполненной анкетой
     * @param  int|null  $status    PartnerActivity (4 = Зарегистрирован) — фильтр по статусу
     * @param  string|null $from    дата заполнения анкеты от (Y-m-d, включительно)
     * @param  string|null $to      дата заполнения анкеты до (Y-m-d, включительно)
     */
    private function baseQuery(string $search, bool $onlyCompleted, ?int $status = null, ?string $from = null, ?string $to = null)
    {
        // Статус (activity) живёт в consultant, анкета — в WebUser; связь
        // consultant."webUser" = WebUser.id (чистая 1:1, дублей нет). LEFT JOIN,
        // чтобы вернуть статус для отображения и фильтровать по нему.
        $q = DB::table('WebUser as w')
            ->leftJoin('consultant as c', function ($j) {
                $j->on('c.webUser', '=', 'w.id')->whereNull('c.dateDeleted');
            })
            ->where('w.role', 'like', '%consultant%')
            ->whereNull('w.dateDeleted')
            ->select('w.*', 'c.activity as partnerActivity');

        if ($onlyCompleted) {
            $q->whereNotNull('w.questionnaireCompletedAt');
        }
        if ($status !== null) {
            $q->where('c.activity', $status);
        }
        if ($from !== null) {
            $q->whereDate('w.questionnaireCompletedAt', '>=', $from);
        }
        if ($to !== null) {
            $q->whereDate('w.questionnaireCompletedAt', '<=', $to);
        }
        if ($search !== '') {
            $q->where(function ($w) use ($search) {
                $w->where('w.lastName', 'ilike', "%{$search}%")
                  ->orWhere('w.firstName', 'ilike', "%{$search}%")
                  ->orWhere('w.email', 'ilike', "%{$search}%")
                  ->orWhere('w.phone', 'ilike', "%{$search}%");
            });
        }
        return $q;
    }

    /** Читаемая метка статуса партнёра по значению activity. */
    private function statusLabel($activity): string
    {
        if ($activity === null || $activity === '') return '';
        return PartnerActivity::tryFrom((int) $activity)?->label() ?? '';
    }

    private function mapRow($r, $cities = null): array
    {
        return [
            'id' => $r->id,
            'name' => trim(($r->lastName ?? '') . ' ' . ($r->firstName ?? '') . ' ' . ($r->patronymic ?? '')),
            'email' => $r->email,
            'phone' => $r->phone,
            'city' => $r->city ? ($cities[$r->city] ?? $r->city) : null,
            'completed_at' => $r->questionnaireCompletedAt,
            'status' => $this->statusLabel($r->partnerActivity ?? null),
            'fields' => array_combine(
                array_keys(self::FIELDS),
                array_map(fn ($k) => $r->{$k} ?? null, array_keys(self::FIELDS))
            ),
        ];
    }
}
