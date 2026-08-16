<?php

namespace App\Services;

use App\Enums\PartnerActivity;
use Illuminate\Support\Facades\DB;

/**
 * Лента изменений партнёра (/admin/partners/{id}/change-log).
 *
 * Вынесено из AdminDataController (метод занимал 136 строк). Код перенесён
 * дословно: состав событий, порядок и подписи полей не меняются.
 *
 * Сливаются два журнала: spatie activity_log (правки модели) и audit_log
 * (partner_update и смены статуса через сервис). Различия, которые легко
 * потерять при правках:
 *   - обход идёт по ОБЪЕДИНЕНИЮ старых и новых ключей, а сравнение значений
 *     строковое: 1 и "1" изменением не считаются;
 *   - пустые записи отсекаются — activity без изменений и комментария,
 *     старые partner_update без diff.
 */
class PartnerChangeLogService
{
    public function forPartner(int $id): array
    {
        // --- 1. Spatie activity_log (Consultant) ---
        $spatieRows = DB::table('activity_log')
            ->where('subject_type', \App\Models\Consultant::class)
            ->where('subject_id', $id)
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        // --- 2. audit_log (partner_update + статус-смены через сервис) ---
        $auditRows = DB::table('audit_log')
            ->where('entity', 'consultant')
            ->where('entity_id', (string) $id)
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        // Авторы — собираем все WebUser id одним запросом, без N+1.
        $causerIds = $spatieRows->pluck('causer_id')->filter()
            ->merge($auditRows->pluck('user_id')->filter())
            ->unique();
        $causers = $causerIds->isNotEmpty()
            ? DB::table('WebUser')->whereIn('id', $causerIds)
                ->select(['id', 'firstName', 'lastName', 'patronymic'])->get()->keyBy('id')
            : collect();
        $authorOf = function ($uid) use ($causers) {
            if (! $uid) return 'Система';
            $u = $causers[$uid] ?? null;
            if (! $u) return "Пользователь #{$uid}";
            $name = trim("{$u->lastName} {$u->firstName} {$u->patronymic}");
            return $name !== '' ? $name : "Пользователь #{$uid}";
        };

        // Лейблы полей (англ. → русский) для UI. Что не покрыто — показываем как есть.
        $fieldLabels = [
            'firstName' => 'Имя', 'lastName' => 'Фамилия', 'patronymic' => 'Отчество',
            'email' => 'Email', 'phone' => 'Телефон', 'nicTG' => 'Telegram',
            'gender' => 'Пол', 'birthDate' => 'Дата рождения', 'role' => 'Роль(и)',
            'isBlocked' => 'Блокировка', 'password' => 'Пароль',
            'participantCode' => 'Реф. код', 'inviter' => 'Пригласивший',
            'activity' => 'Статус активности', 'status' => 'Квалификация',
            'active' => 'Активен', 'acceptance' => 'Согласие',
            'webUser' => 'WebUser',
            'activationDeadline' => 'Дедлайн активации',
            'yearPeriodEnd' => 'Конец годового периода',
            'terminationCount' => 'Кол-во терминаций',
            'reinstatement_count' => 'Самовосстановлений',
            'reinstate_blocked' => 'Запрет самовосстановления',
            'dateActivity' => 'Дата активации',
            'dateDeactivity' => 'Дата деактивации',
            'dateDeleted' => 'Дата удаления (soft)',
            'status_and_lvl' => 'Статус + уровень',
            'qualificationLocked' => 'Квалификация заблок.',
            'personName' => 'ФИО',
        ];
        $activityLabel = function ($v) {
            if ($v === null || $v === '') return null;
            $enum = PartnerActivity::tryFrom((int) $v);
            return $enum ? $enum->label() : (string) $v;
        };
        $renderValue = function ($field, $val) use ($activityLabel) {
            if ($val === null || $val === '') return null;
            if ($field === 'activity') return $activityLabel($val);
            if (is_bool($val)) return $val ? 'да' : 'нет';
            return (string) $val;
        };

        $entries = [];

        foreach ($spatieRows as $r) {
            $props = json_decode($r->properties ?: '{}', true);
            $newAttrs = $props['attributes'] ?? [];
            $oldAttrs = $props['old'] ?? [];
            $changes = [];
            $keys = array_unique(array_merge(array_keys($newAttrs), array_keys($oldAttrs)));
            foreach ($keys as $k) {
                $oldV = $oldAttrs[$k] ?? null;
                $newV = $newAttrs[$k] ?? null;
                if ((string) $oldV === (string) $newV) continue;
                $changes[] = [
                    'field' => $k,
                    'fieldLabel' => $fieldLabels[$k] ?? $k,
                    'from' => $renderValue($k, $oldV),
                    'to' => $renderValue($k, $newV),
                ];
            }
            // Override-логи проходят с пустыми атрибутами (logged через activity()->log).
            // Покажем их как отдельные события с комментарием.
            $action = $r->event ?: ($r->description ?: 'change');
            if (empty($changes) && empty($props['comment'])) {
                continue;
            }
            $entries[] = [
                'id' => 'a' . $r->id,
                'source' => 'activity',
                'createdAt' => $r->created_at,
                'author' => $authorOf($r->causer_id),
                'action' => $action,
                'comment' => $props['comment'] ?? null,
                'changes' => $changes,
            ];
        }

        foreach ($auditRows as $r) {
            $payload = json_decode($r->payload ?: '{}', true);
            $diff = $payload['diff'] ?? [];
            // Пропускаем старые partner_update-записи без diff'а — они
            // содержали только список названий полей и ничего не дают UI.
            if ($r->action === 'partner_update' && empty($diff)) continue;
            $changes = [];
            foreach ($diff as $field => $pair) {
                $changes[] = [
                    'field' => $field,
                    'fieldLabel' => $fieldLabels[$field] ?? $field,
                    'from' => $renderValue($field, $pair['from'] ?? null),
                    'to' => $renderValue($field, $pair['to'] ?? null),
                ];
            }
            $entries[] = [
                'id' => 'u' . $r->id,
                'source' => 'audit',
                'createdAt' => $r->created_at,
                'author' => $authorOf($r->user_id) ?: ($r->user_email ?: 'Система'),
                'action' => $r->action,
                'comment' => $payload['comment'] ?? null,
                'changes' => $changes,
            ];
        }

        // Сортировка по дате убыв., обрезаем до 100 — больше в UI не нужно.
        usort($entries, fn ($a, $b) => strcmp((string) $b['createdAt'], (string) $a['createdAt']));
        $entries = array_slice($entries, 0, 100);

        return $entries;
    }
}
