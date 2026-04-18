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
        return LogOptions::defaults()
            ->logOnly([
                'personName', 'activity', 'status', 'active', 'acceptance',
                'participantCode', 'inviter', 'webUser', 'person',
                'activationDeadline', 'yearPeriodEnd', 'terminationCount',
            ])
            ->logOnlyDirty()
            ->setDescriptionForEvent(fn (string $eventName) => "Consultant {$eventName}");
    }

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'acceptance' => 'boolean',
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
        ];
    }

    // --- Relationships ---

    public function person(): BelongsTo
    {
        return $this->belongsTo(User::class, 'person');
    }

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
        return ($this->terminationCount ?? 0) >= PartnerActivity::MAX_TERMINATIONS;
    }
}
