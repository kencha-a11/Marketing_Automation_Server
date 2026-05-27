<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GcashRegistration extends Model
{
    protected $fillable = ['email', 'gcash_ref'];
}