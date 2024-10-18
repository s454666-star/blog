<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    // 如果您移除了 'email_verified' 並添加了 'email_verified_at'
    protected $dates = [
        'email_verified_at',
        'last_login',
        'birthdate',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'username',
        'password',
        'name',
        'email',
        'phone',
        'address',
        'gender',
        'birthdate',
        'nationality',
        'role',
        'status',
        'email_verified_at',
        'last_login',
        'remember_token', // 確保這裡包含 remember_token
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        // 如果有其他敏感欄位
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_login' => 'datetime',
        'birthdate' => 'date',
    ];
}
