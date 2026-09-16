<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Contract extends Model
{
    use LogsActivity;

    protected $table = 'contract';
    public $timestamps = false;

    protected $guarded = ['id'];

    public const STATUS_ACTIVATED = 1;

    /**
     * Статусы, в которых прогноз активации не нужен и обнуляется:
     * 1 Активирован, 6 Закрыто нереализовано, 8 Закрыто, 9 Возврат, 10 Лапсирован.
     * Одно место на все пути смены статуса — ручную правку и синхронизацию с
     * таблицей. Фронт держит копию в ContractManager.vue (NO_FORECAST_STATUSES).
     */
    public const NO_FORECAST_STATUSES = [1, 6, 8, 9, 10];

    /**
     * Причина правки для ленты «История изменений». Не колонка БД — живёт
     * только на время запроса. Ставится там, где контракт меняет не человек
     * руками, а процесс: синхронизация с таблицей Парус/Акцент проставляет
     * сюда своё пояснение, чтобы в истории было видно основание правки, а не
     * безликое «Contract updated».
     */
    public ?string $activityReason = null;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'number', 'counterpartyContractId',
                'client', 'consultant', 'product', 'program', 'status',
                'country', 'currency', 'ammount',
                'createDate', 'openDate', 'closeDate',
                'riskProfile', 'setup', 'type', 'comment',
            ])
            ->logOnlyDirty()
            ->setDescriptionForEvent(fn (string $eventName) => "Contract {$eventName}");
    }

    /** Spatie зовёт этот хук перед записью в activity_log. */
    public function tapActivity(\Spatie\Activitylog\Models\Activity $activity, string $eventName): void
    {
        if ($this->activityReason !== null) {
            $activity->description = $this->activityReason;
        }
    }

    protected function casts(): array
    {
        return [
            'openDate' => 'datetime',
            'closeDate' => 'datetime',
            'createDate' => 'datetime',
            'createdAt' => 'datetime',
            'deletedAt' => 'datetime',
            'clearOpenDate' => 'boolean',
        ];
    }

    public function clientRelation(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client');
    }

    public function consultantRelation(): BelongsTo
    {
        return $this->belongsTo(Consultant::class, 'consultant');
    }

    public function programRelation(): BelongsTo
    {
        return $this->belongsTo(Program::class, 'program');
    }

    public function productRelation(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product');
    }

    public function statusRelation(): BelongsTo
    {
        return $this->belongsTo(ContractStatus::class, 'status');
    }

    public function currencyRelation(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'contract');
    }
}
