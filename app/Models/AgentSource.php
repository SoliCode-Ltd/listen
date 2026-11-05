<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * What an agent was taught from: its business's website (read at setup and
 * again when asked), notes the owner typed, a file, or one of its links (the
 * newest posts of an account or a page, read again every few hours:
 * AgentLinkReads), or one page of its website (AgentSite: every page, looked
 * up during calls). content is the text the agent answers from (for a link,
 * what was read, as JSON).
 */
class AgentSource extends Model
{
    public const WEBSITE = 'website';

    public const TEXT = 'text';

    public const FILE = 'file';

    public const SHEET = 'sheet';

    public const LINK = 'link';

    /** One page of the business's website, read in the background for the agent to look up during calls (AgentSite). */
    public const PAGE = 'page';

    /** A file waiting to be read, being read, read, or that could not be read. */
    public const PENDING = 'pending';

    public const READING = 'reading';

    public const READY = 'ready';

    public const FAILED = 'failed';

    /** Files the owner uploads live on the private disk, never where a browser can reach them. */
    public const DISK = 'local';

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

    protected static function booted(): void
    {
        // A removed file leaves nothing behind on the disk.
        static::deleted(function (AgentSource $source): void {
            if ($source->path && in_array($source->kind, [self::FILE, self::SHEET], true)) {
                Storage::disk(self::DISK)->delete($source->path);
            }
        });
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /** An uploaded file (a document, a photo or a spreadsheet), not the website or typed notes. */
    public function isUpload(): bool
    {
        return in_array($this->kind, [self::FILE, self::SHEET], true);
    }

    /** The file's type, from its name: pdf, docx, xlsx, csv, txt, md, jpg, png or webp. */
    public function extension(): string
    {
        return strtolower(pathinfo((string) ($this->path ?: $this->title), PATHINFO_EXTENSION));
    }
}
