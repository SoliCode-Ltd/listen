<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What an agent was taught from: its business's website (read at setup and
 * again when asked), notes the owner typed, or a file. content is the text
 * the agent answers from.
 */
class AgentSource extends Model
{
    public const WEBSITE = 'website';

    public const TEXT = 'text';

    public const FILE = 'file';

    public const SHEET = 'sheet';

    protected $fillable = [
        'agent_id',
        'kind',
        'title',
        'url',
        'path',
        'content',
        'chars',
        'status',
        'error',
        'read_at',
    ];

    protected $casts = [
        'chars' => 'integer',
        'read_at' => 'datetime',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
