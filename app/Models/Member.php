<?php

    namespace App\Models;

    use Illuminate\Foundation\Auth\User as Authenticatable;
    use Illuminate\Notifications\Notifiable;

    class Member extends Authenticatable
    {
        use Notifiable;

        protected $fillable = [
            'username',
            'password',
            'name',
            'phone',
            'email',
            'email_verified',
            'email_verification_token',
            'address',
            'status',
        ];

        protected $hidden = [
            'password',
            'remember_token',
        ];
    }
