<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RagChunk extends Model
{
    use HasFactory;

    protected $fillable = [
        'rag_document_id',
        'version',
        'chunk_index',
        'content',
        'content_hash',
        'metadata',
        'is_active',
    ];

    protected $casts = [
        'metadata' => 'array',
        'is_active' => 'boolean',
    ];

    public function document()
    {
        return $this->belongsTo(RagDocument::class, 'rag_document_id');
    }
}
