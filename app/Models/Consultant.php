<?php

namespace App\Models;

use App\Enums\PartnerActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Consultant extends Model
{
    use LogsActivity;

    protected $table = 'consultant';
    public $timestamps = false;

    protected $guarded = ['id'];

    public function getActivitylogOptions(): LogOptions
    {
        // Per ./.claude/specs/✅Статусы партнеров.md Part 3: manual overrides
        // of activity / date* columns must leave an audit trail (who, when,
        // old→new, comment). These are exactly the columns the edit modal
        // can change — adding dateActivity / dateDeactivity / dateDeleted /
        // status_and_lvl / qualificationLocked brings us in line with the spec.
        return LogOptions::defaults()
            ->logOnly([
                'personName', 'activity', 'status', 'active', 'acceptance',
                'participantCode', 'inviter', 'webUser',
                'activationDeadline', 'yearPeriodEnd', 'terminationCount',
                'reinstatement_count', 'reinstate_blocked',
                'dateActivity', 'dateDeactivity', 'dateDeleted',
                'status_and_lvl', 'qualificationLocked',
            ])
            ->logOnlyDirty()
            ->setDescriptionForEvent(fn (string $eventName) => "Consultant {$eventName}");
    }

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'acceptance' => 'boolean',
            'education_exempt' => 'boolean',
            'isStudent' => 'boolean',
            'fieldForReport' => 'boolean',
            'activity' => PartnerActivity::class,
            'dateCreated' => 'datetime',
            'dateChanged' => 'datetime',
            'dateDeleted' => 'datetime',
            'dateActivity' => 'datetime',
            'dateDeactivity' => 'datetime',
            'qualificationLocked' => 'datetime',
            'activationDeadline' => 'datetime',
            'yearPeriodEnd' => 'datetime',
            'terminationCount' => 'integer',
            'reinstatement_count' => 'integer',
            'reinstate_blocked' => 'boolean',
            'reinstate_mentor_pending' => 'boolean',
            'last_reinstate_at' => 'datetime',
        ];
    }

    // --- Relationships ---

    public function statusRelation(): BelongsTo
    {
        return $this->belongsTo(Status::class, 'status');
    }

    public function activityStatus(): BelongsTo
    {
        return $this->belongsTo(ActivityStatus::class, 'activity');
    }

    public function countryRelation(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'country');
    }

    public function inviterRelation(): BelongsTo
    {
        return $this->belongsTo(self::class, 'inviter');
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class, 'consultant');
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class, 'consultant');
    }

    // --- Scopes ---

    public function scopeByActivity(Builder $query, PartnerActivity $activity): Builder
    {
        return $query->where('activity', $activity->value);
    }

    public function scopeRegistered(Builder $query): Builder
    {
        return $query->byActivity(PartnerActivity::Registered);
    }

    public function scopeActivePartners(Builder $query): Builder
    {
        return $query->byActivity(PartnerActivity::Active);
    }

    public function scopeTerminated(Builder $query): Builder
    {
        return $query->byActivity(PartnerActivity::Terminated);
    }

    public function scopeExcluded(Builder $query): Builder
    {
        return $query->byActivity(PartnerActivity::Excluded);
    }

    // --- Helpers ---

    public function activityLabel(): string
    {
        // Null activity = just-registered row that never got the enum assigned.
        // Treat it as Registered rather than 'Неизвестен' — matches the domain:
        // every new consultant is in the registered stage until activation.
        return $this->activity?->label() ?? PartnerActivity::Registered->label();
    }

    public function canInvite(): bool
    {
        return $this->activity === PartnerActivity::Active;
    }

    public function canBeTerminated(): bool
    {
        return in_array($this->activity, [PartnerActivity::Registered, PartnerActivity::Active]);
    }

    public function hasReachedMaxTerminations(): bool
    {
        return ($this->terminationCount ?? 0) >= PartnerActivity::maxTerminations();
    }

    /** Сколько самовосстановлений осталось партнёру (не меньше нуля). */
    public function reinstatementsLeft(): int
    {
        return max(0, PartnerActivity::selfReinstateLimit() - (int) ($this->reinstatement_count ?? 0));
    }

    /**
     * Может ли партнёр восстановиться САМ прямо сейчас.
     *
     * Возвращает причину отказа (или null, если можно) — её показывает окно
     * восстановления при входе, поэтому формулировки партнёрские.
     * Из «Исключён» самовосстановления нет: этот статус ставится либо вручную
     * за нарушение, либо терминацией с исчерпанными попытками.
     */
    public function selfReinstateBlockReason(): ?string
    {
        if (! PartnerActivity::selfReinstateEnabled()) {
            return 'Самовосстановление временно недоступно. Обратитесь в поддержку.';
        }
        if ($this->activity === PartnerActivity::Excluded) {
            return 'Статус «Исключён» снимается только через поддержку.';
        }
        if ($this->activity !== PartnerActivity::Terminated) {
            return 'Восстановление доступно только из статуса «Терминирован».';
        }
        if ($this->reinstate_blocked) {
            return 'Восстановление по вашему аккаунту приостановлено. Обратитесь в поддержку.';
        }
        if ($this->reinstatementsLeft() < 1) {
            return 'Лимит восстановлений исчерпан. Обратитесь в поддержку.';
        }

        return null;
    }

    public function canSelfReinstate(): bool
    {
        return $this->selfReinstateBlockReason() === null;
    }
}
