<?php

namespace App\Models;

class PersonalAccessToken extends \Laravel\Sanctum\PersonalAccessToken
{
    protected $table = 'personal_access_tokens';
}
