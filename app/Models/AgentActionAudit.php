<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AgentActionAudit extends Model
{
    use HasFactory;

    protected $table = 'agent_action_audits';

    protected $fillable = [
        'user_id',
        'action',
        'target_type',
        'target_id',
        'target_label',
        'confirmation_strength',
        'confirmation_phrase',
        'confirmation_message',
        'confirmation_source',
        'runtime_pending_action_id',
        'session_id',
        'request_payload',
        'result_payload',
        'metadata',
        'executed_at',
    ];

    protected $casts = [
        'request_payload' => 'array',
        'result_payload' => 'array',
        'metadata' => 'array',
        'executed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
