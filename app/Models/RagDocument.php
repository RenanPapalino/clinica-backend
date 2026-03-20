<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class RagDocument extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'source_system',
        'external_id',
        'file_name',
        'file_type',
        'business_context',
        'context_key',
        'status',
        'current_version',
        'chunks_count',
        'checksum',
        'external_updated_at',
        'last_indexed_at',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'external_updated_at' => 'datetime',
        'last_indexed_at' => 'datetime',
    ];

    public function chunks()
    {
        return $this->hasMany(RagChunk::class);
    }

    public function activeChunks()
    {
        return $this->hasMany(RagChunk::class)->where('is_active', true);
    }

    public function scopeAtivos($query)
    {
        return $query->where('status', 'active');
    }
}
