<?php

namespace App\Models;

use App\Models\Concerns\BelongsToFirm;
use Illuminate\Database\Eloquent\Model;

class Counter extends Model
{
    use BelongsToFirm;

    protected $fillable = ['key', 'value'];

    protected function casts(): array
    {
        return ['value' => 'integer'];
    }
}
