<?php

namespace SystemX\Core\Audit;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class AuditActivity extends Model
{
    public $timestamps = false; // append-only: created_at set on create, no updated_at

    protected $table = 'system_x_audit_activity';

    protected $guarded = [];

    protected $casts = [
        'value' => 'array',
        'payload' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $row): void {
            $row->created_at ??= Carbon::now();
        });
    }

    // Chunked TTL delete for the GC sweep, mirroring WindowState::pruneOlderThan. Append-only
    // audit tables grow unbounded, so a single big DELETE could long-lock the table -- delete in
    // bounded batches until none remain. GLOBAL `WHERE created_at < ?` served by the single-column
    // sx_audit_activity_gc index. Returns the total rows deleted.
    public static function pruneOlderThan(CarbonInterface $cutoff, int $chunk = 1000): int
    {
        $deleted = 0;

        do {
            $batch = static::query()
                ->where('created_at', '<', $cutoff)
                ->limit($chunk)
                ->delete();

            $deleted += $batch;
        } while ($batch > 0);

        return $deleted;
    }
}
