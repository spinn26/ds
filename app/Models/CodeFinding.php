<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Находка аудита кода — строка реестра на странице «Качество кода».
 *
 * До 01.09.2026 реестр лежал массивами в CodeQuality.vue, и закрытие
 * находки стоило релиза на прод. Теперь источник истины — таблица
 * code_findings, стартовый снимок — database/data/code-findings.json.
 */
class CodeFinding extends Model
{
    protected $table = 'code_findings';

    protected $guarded = ['id'];

    /** Порядок вывода: сначала тяжёлые, внутри — по sort_order. */
    public const SEVERITY_ORDER = ['critical', 'high', 'medium', 'low'];

    public const STATUSES = ['open', 'fixed'];

    protected function casts(): array
    {
        return [
            'closed_at' => 'datetime',
            'sort_order' => 'integer',
            'closed_by' => 'integer',
        ];
    }

    /** Вид для фронта — те же ключи, что были в массивах CodeQuality.vue. */
    public function toRegistryArray(): array
    {
        return [
            'id' => $this->code,
            'rowId' => $this->id,
            'severity' => $this->severity,
            'category' => $this->category,
            'title' => $this->title,
            'file' => $this->file,
            'problem' => $this->problem,
            'recommendation' => $this->recommendation,
            'status' => $this->status,
            'sortOrder' => $this->sort_order,
            'closedAt' => $this->closed_at,
        ];
    }
}
