<?php

namespace App\Services;

use App\Models\Consultant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Текущий результат партнёра по метрике акции.
 *
 * Отдельный сервис, потому что цифру просят двое: рабочий стол (панель в hero)
 * и страница новости (блок «Ваш прогресс»). Считать её в двух местах нельзя —
 * разойдутся на глазах у партнёра, который открыл обе вкладки.
 *
 * Метрика пока одна — личные продажи месяца (ЛП). Берём её оттуда же, откуда
 * её показывает «Мои показатели»: последняя строка qualificationLog, с
 * откатом на денормализацию consultant.personalVolume.
 */
class PartnerPromoProgress
{
    public function current(?User $user): ?float
    {
        if (! $user) {
            return null;
        }

        $consultant = Consultant::forUser($user->id);
        if (! $consultant) {
            return null;
        }

        return $this->forConsultant($consultant);
    }

    public function forConsultant(Consultant $consultant): float
    {
        $personalVolume = DB::table('qualificationLog')
            ->where('consultant', $consultant->id)
            ->whereNull('dateDeleted')
            ->orderByDesc('date')
            ->value('personalVolume');

        return round((float) ($personalVolume ?? $consultant->personalVolume ?? 0), 2);
    }
}
