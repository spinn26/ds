<?php

namespace App\Models;

use App\Notifications\ResetPasswordNotification;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

/**
 * Пользователь платформы. Таблица — `WebUser` (регистр важен: Postgres
 * регистрозависим, а строчный `webUser` — мёртвый legacy-двойник Directual).
 *
 * Колонки перечислены явно: без схемы под рукой larastan не знает полей
 * Eloquent-модели и считает любое обращение к ним обращением к
 * несуществующему свойству. На этом уже ломался деплой — `UserResource`
 * читает lastName/firstName/email при подмене пользователя.
 *
 * @property int $id
 * @property bool|null $test
 * @property string|null $comment
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $userpic
 * @property string|null $nicTG
 * @property string|null $emailOld
 * @property string|null $role
 * @property string|null $password
 * @property string|null $lastName
 * @property string|null $firstName
 * @property string|null $patronymic
 * @property string|null $gender
 * @property \Illuminate\Support\Carbon|null $birthDate
 * @property int|null $taxResidency
 * @property int|null $city
 * @property string|null $dateCreated
 * @property \Illuminate\Support\Carbon|null $dateDeleted
 * @property string|null $dateLastActivity
 * @property \Illuminate\Support\Carbon|null $dateChanged
 * @property bool|null $boughtProRost
 * @property int|null $getCourseRegistrationWebHookData
 * @property string|null $getCourseUserId
 * @property string|null $getCourseUserIdarray
 * @property int|null $getCourseOrderWebHookData
 * @property string|null $urlData
 * @property string|null $headers
 * @property int|null $webUser
 * @property int|null $status
 * @property int|null $client
 * @property int|null $consultant_id
 * @property bool|null $agreement
 * @property bool|null $isAuthorization
 * @property bool|null $isBlocked
 * @property string|null $avatar
 * @property string|null $workField
 * @property string|null $salesExperience
 * @property string|null $financeExperience
 * @property string|null $hasPotentialClients
 * @property string|null $potentialClientsCount
 * @property string|null $currentIncome
 * @property string|null $weeklyHours
 * @property string|null $incomeFactors
 * @property \Illuminate\Support\Carbon|null $questionnaireCompletedAt
 * @property string|null $position
 * @property string|null $two_factor_secret
 * @property bool $two_factor_enabled
 * @property string|null $two_factor_confirmed_at
 * @property string|null $telegram_chat_id
 * @property string|null $telegram_user_id
 * @property string|null $telegram_username
 * @property string|null $telegram_linked_at
 * @property string|null $last_seen_at
 * @property bool $chat_department_lead
 *
 * @property-read string $name ФИО одной строкой (getNameAttribute)
 */
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, LogsActivity;

    /**
     * Переопределяем штатное `Notifications\Auth\ResetPassword` —
     * нам нужна ссылка на SPA (`/reset-password?token=…`), а не на
     * web-роут `/password/reset/{token}`, которого в проекте нет.
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            // nicTG/birthDate/city тоже пишем в лог: их молчаливое затирание
            // через профиль было невозможно расследовать (кейс WebUser 1092).
            ->logOnly(['firstName', 'lastName', 'email', 'phone', 'nicTG', 'birthDate', 'city', 'role'])
            ->logOnlyDirty()
            ->setDescriptionForEvent(fn (string $eventName) => "User {$eventName}");
    }

    protected $table = 'WebUser';
    public $timestamps = false;
    protected $rememberTokenName = null; // WebUser has no remember_token column

    protected $fillable = [
        'firstName',
        'lastName',
        'patronymic',
        'email',
        'password',
        'phone',
        'role',
        'gender',
        'birthDate',
        'workField',
        'salesExperience',
        'financeExperience',
        'hasPotentialClients',
        'potentialClientsCount',
        'currentIncome',
        'weeklyHours',
        'incomeFactors',
        'questionnaireCompletedAt',
        'position',
        'nicTG',
        'taxResidency',
        'city',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'birthDate' => 'datetime',
            'dateDeleted' => 'datetime',
            'dateChanged' => 'datetime',
            'questionnaireCompletedAt' => 'datetime',
            'test' => 'boolean',
            'boughtProRost' => 'boolean',
            'agreement' => 'boolean',
            'isAuthorization' => 'boolean',
            'isBlocked' => 'boolean',
        ];
    }

    public function getNameAttribute(): string
    {
        return trim("{$this->firstName} {$this->lastName}");
    }

    /** All roles this user has, lowercased, trimmed. */
    public function getRolesArray(): array
    {
        return array_filter(array_map('trim', explode(',', strtolower((string) $this->role))));
    }

    public function hasAnyRole(array $roles): bool
    {
        $userRoles = $this->getRolesArray();
        foreach ($roles as $role) {
            if (in_array(strtolower($role), $userRoles, true)) {
                return true;
            }
        }
        return false;
    }

    /** Strictly the admin role. Backoffice is staff, not admin — it must never
     *  pass gates that guard privilege escalation (e.g. permission_groups CRUD). */
    public function isAdmin(): bool
    {
        return $this->hasAnyRole(['admin']);
    }

    /** Any staff role — sees company-wide data. */
    public function isStaff(): bool
    {
        return $this->hasAnyRole(['admin', 'backoffice', 'support', 'finance', 'head', 'calculations', 'corrections', 'education', 'invest', 'content']);
    }

    /** Linked consultant record (null for pure staff accounts). */
    public function consultantRecord(): HasOne
    {
        return $this->hasOne(Consultant::class, 'webUser');
    }

    /**
     * Validate password: supports bcrypt + legacy MD5 migration.
     * Returns false if the password hash is null/empty (expired via users:expire-md5 or unset).
     * MD5 support is DEPRECATED — remove once users:md5-report shows zero remaining.
     */
    public function validatePassword(string $password): bool
    {
        if ($this->password === null || $this->password === '') {
            return false;
        }

        // Legacy MD5 (32 hex chars) — проверяем ПЕРВЫМ. Laravel 11
        // BcryptHasher::check() бросает RuntimeException на не-bcrypt
        // хэше, поэтому вызывать его на MD5-строке нельзя.
        if (strlen($this->password) === 32 && ctype_xdigit($this->password)) {
            // hash_equals, а не === : сравнение хэшей должно быть
            // constant-time, иначе по времени ответа утекает префикс.
            if (hash_equals($this->password, md5($password))) {
                $this->password = Hash::make($password);
                $this->saveQuietly();
                // Только id: e-mail в логе — лишние ПДн, которые потом
                // разъезжаются по ротациям, Sentry и бэкапам логов.
                \Log::info("MD5 password migrated to bcrypt for user {$this->id}");
                return true;
            }
            return false;
        }

        return Hash::check($password, $this->password);
    }
}
