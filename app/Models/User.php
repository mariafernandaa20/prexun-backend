<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'suspendido',
        'rfc',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'suspendido' => 'boolean',
        ];
    }
    public function campuses()
    {
        return $this->belongsToMany(Campus::class, 'campus_user');
    }

    public function userCampuses()
    {
        return $this->hasMany(UserCampus::class);
    }

    public function isSuperAdmin()
    {
        return $this->role === 'super_admin';
    }

    public function grupos()
    {
        return $this->belongsToMany(Grupo::class, 'teacher_groups');
    }

    public function isTeacher()
    {
        return $this->role === 'teacher';
    }

    public function chatMessages()
    {
        return $this->hasMany(ChatMessage::class);
    }
}
