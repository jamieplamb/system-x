<?php

namespace SystemX\Core\Audit;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

// old_value/new_value are JSON TEXT (json_encode at the AuditRecorder boundary), NOT an
// Eloquent 'array' cast -- a property delta can be a SCALAR (count 0->1) the array cast would
// mis-wrap. The recorder owns encode; readers json_decode.
class AuditChange extends Model
{
    public $timestamps = false;

    protected $table = 'system_x_audit_changes';

    protected $guarded = [];

    protected $casts = ['created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(function (self $row): void {
            $row->created_at ??= Carbon::now();
        });
    }

    // Chunked TTL delete, mirroring WindowState::pruneOlderThan -- append-only, served by the
    // single-column sx_audit_changes_gc index. Returns the total rows deleted.
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
