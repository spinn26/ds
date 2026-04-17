<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['firstName', 'lastName', 'email', 'phone', 'role'])
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

    public function isAdmin(): bool
    {
        return $this->hasAnyRole(['admin', 'backoffice']);
    }

    /** Any staff role — sees company-wide data. */
    public function isStaff(): bool
    {
        return $this->hasAnyRole(['admin', 'backoffice', 'support', 'finance', 'head', 'calculations', 'corrections']);
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

        if (Hash::check($password, $this->password)) {
            return true;
        }

        // Legacy MD5 migration — rehash to bcrypt on successful login
        if (strlen($this->password) === 32 && $this->password === md5($password)) {
            $this->password = Hash::make($password);
            $this->saveQuietly();
            \Log::info("MD5 password migrated to bcrypt for user {$this->id} ({$this->email})");
            return true;
        }

        return false;
    }
}
