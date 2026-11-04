<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer who left their details with an agent, so the business can call
 * or message them back. One per phone number (or email) per owner: a second
 * call from the same number updates it.
 */
class AgentContact extends Model
{
    protected $fillable = [
        'user_id',
        'agent_id',
        'agent_conversation_id',
        'name',
        'phone',
        'email',
        'note',
        'last_contact_at',
    ];

    protected $casts = [
        'last_contact_at' => 'datetime',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class)->withTrashed();
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AgentConversation::class, 'agent_conversation_id');
    }
}
