<?php

namespace App\Models\Import;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportRowEdit extends Model
{
    use HasFactory;

    protected $fillable = [
        'import_id',
        'row_number',
        'data',
    ];

    protected $casts = [
        'data' => 'array',
    ];

    public function import(): BelongsTo
    {
        return $this->belongsTo(Import::class);
    }
}
