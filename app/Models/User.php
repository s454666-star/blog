<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    /**
     * 定義可填充的欄位 (Mass Assignable)
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
        'email_verified',
        'last_login',
    ];

    /**
     * 隱藏的屬性 (如不想在 API 返回中顯示)
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * 自動轉換為日期格式的欄位
     */
    protected $casts = [
        'email_verified' => 'boolean',
        'birthdate' => 'date',
        'last_login' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * 密碼加密處理
     */
    public function setPasswordAttribute($password)
    {
        $this->attributes['password'] = bcrypt($password);
    }
}
