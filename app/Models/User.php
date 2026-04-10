<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;

class User extends Authenticatable implements FilamentUser, HasName
{
    use HasFactory, Notifiable;

    protected $table = 'WebUser';
    public $timestamps = false;

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

    public function getFilamentName(): string
    {
        return trim("{$this->firstName} {$this->lastName}");
    }

    public function canAccessPanel(Panel $panel): bool
    {
        $roles = explode(',', $this->role ?? '');

        return in_array('admin', $roles) || in_array('backoffice', $roles);
    }

    /**
     * Validate password: supports both legacy MD5 and bcrypt.
     * On successful MD5 login, rehashes to bcrypt.
     */
    public function validatePassword(string $password): bool
    {
        // Try bcrypt first
        if (Hash::check($password, $this->password)) {
            return true;
        }

        // Try legacy MD5
        if ($this->password === md5($password)) {
            // Rehash to bcrypt for future logins
            $this->password = Hash::make($password);
            $this->saveQuietly();

            return true;
        }

        return false;
    }
}
