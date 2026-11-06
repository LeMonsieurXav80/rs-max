<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentProposal extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const SENDING = 'sending';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    public const UNCERTAIN = 'uncertain';

    protected $fillable = ['social_account_id', 'inbox_item_id', 'persona_id', 'target_key', 'target', 'author', 'conversation',
        'reply_text', 'status', 'error', 'decided_by', 'decided_at', 'sent_at'];

    protected function casts(): array
    {
        return ['target' => 'array', 'conversation' => 'array', 'decided_at' => 'datetime', 'sent_at' => 'datetime'];
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    public function inboxItem(): BelongsTo
    {
        return $this->belongsTo(InboxItem::class);
    }
}
