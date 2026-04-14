<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

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

    public function isAdmin(): bool
    {
        $roles = explode(',', $this->role ?? '');
        return in_array('admin', $roles) || in_array('backoffice', $roles);
    }

    /**
     * Validate password: supports bcrypt + legacy MD5 migration.
     * MD5 support is DEPRECATED — will be removed after full migration.
     */
    public function validatePassword(string $password): bool
    {
        if (Hash::check($password, $this->password)) {
            return true;
        }

        // Legacy MD5 migration — rehash to bcrypt on successful login
        if ($this->password && strlen($this->password) === 32 && $this->password === md5($password)) {
            $this->password = Hash::make($password);
            $this->saveQuietly();
            \Log::info("MD5 password migrated to bcrypt for user {$this->id} ({$this->email})");
            return true;
        }

        return false;
    }
}
