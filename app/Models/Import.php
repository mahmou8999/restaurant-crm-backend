<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Import extends Model
{
    protected $fillable = ['user_id', 'file_name', 'total_rows', 'imported_rows', 'failed_rows', 'status'];
}
