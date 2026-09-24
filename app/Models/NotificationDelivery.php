<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationDelivery extends Model
{
    protected $fillable = ['notification_event_id', 'template_id', 'recipient_user_id', 'channel', 'address_snapshot', 'attempt_no', 'provider', 'attempted_at', 'delivery_status', 'provider_response', 'read_at'];

    protected function casts(): array
    {
        return ['attempted_at' => 'datetime', 'read_at' => 'datetime'];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(NotificationEvent::class, 'notification_event_id');
    }
}
