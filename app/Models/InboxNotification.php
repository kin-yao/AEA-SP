<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One in-app notification for one person. Shown by the bell. */
class InboxNotification extends Model
{
    protected $fillable = ['user_id', 'title', 'lines', 'url', 'label', 'read_at'];

    protected $casts = [
        'lines' => 'array',
        'read_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeUnread(Builder $q): Builder
    {
        return $q->whereNull('read_at');
    }

    /** Only links that stay inside this site are kept, so a notification can never point elsewhere. */
    public static function safeUrl(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH) ?: null;
        $query = parse_url($url, PHP_URL_QUERY);
        $host = parse_url($url, PHP_URL_HOST);
        $appHost = parse_url(config('app.url'), PHP_URL_HOST);

        if ($host && $host !== $appHost && $host !== request()->getHost()) {
            return null;
        }

        return $path ? $path.($query ? '?'.$query : '') : null;
    }
}
