<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Import extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['path', 'checksum', 'byte_offset'];

    protected function casts(): array
    {
        return ['completed_at' => 'datetime', 'byte_offset' => 'integer'];
    }

    public function errors(): HasMany
    {
        return $this->hasMany(ImportError::class);
    }
}
