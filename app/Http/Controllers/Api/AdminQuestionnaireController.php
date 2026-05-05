<?php

namespace App\Http\Controllers\Api;

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
        $page = max(1, (int) $request->input('page', 1));
        $per = min(100, max(10, (int) $request->input('per', 25)));

        $q = $this->baseQuery($search, $only);
        $total = (clone $q)->count();
        $rows = $q
            ->orderByDesc('questionnaireCompletedAt')
            ->orderBy('lastName')
            ->forPage($page, $per)
            ->get();

        return response()->json([
            'data' => $rows->map(fn ($r) => $this->mapRow($r))->values(),
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

        return response()->json($this->mapRow($row));
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

        $dbRows = $this->baseQuery($search, $only)
            ->orderByDesc('questionnaireCompletedAt')
            ->orderBy('lastName')
            ->get();

        $headers = ['ФИО', 'E-mail', 'Телефон', 'Город', 'Заполнено'];
        foreach (self::FIELDS as $label) $headers[] = $label;

        $rows = $dbRows->map(function ($r) {
            $line = [
                trim(($r->lastName ?? '') . ' ' . ($r->firstName ?? '') . ' ' . ($r->patronymic ?? '')),
                $r->email ?? '',
                $r->phone ?? '',
                $r->city ?? '',
                $r->questionnaireCompletedAt ?? '',
            ];
            foreach (array_keys(self::FIELDS) as $f) {
                $line[] = $r->{$f} ?? '';
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

    private function baseQuery(string $search, bool $onlyCompleted)
    {
        $q = DB::table('WebUser')
            ->where('role', 'like', '%consultant%')
            ->whereNull('dateDeleted');

        if ($onlyCompleted) {
            $q->whereNotNull('questionnaireCompletedAt');
        }
        if ($search !== '') {
            $q->where(function ($w) use ($search) {
                $w->where('lastName', 'ilike', "%{$search}%")
                  ->orWhere('firstName', 'ilike', "%{$search}%")
                  ->orWhere('email', 'ilike', "%{$search}%")
                  ->orWhere('phone', 'ilike', "%{$search}%");
            });
        }
        return $q;
    }

    private function mapRow($r): array
    {
        return [
            'id' => $r->id,
            'name' => trim(($r->lastName ?? '') . ' ' . ($r->firstName ?? '') . ' ' . ($r->patronymic ?? '')),
            'email' => $r->email,
            'phone' => $r->phone,
            'city' => $r->city,
            'completed_at' => $r->questionnaireCompletedAt,
            'fields' => array_combine(
                array_keys(self::FIELDS),
                array_map(fn ($k) => $r->{$k} ?? null, array_keys(self::FIELDS))
            ),
        ];
    }
}
