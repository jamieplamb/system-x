<?php

namespace SystemX\Core\State;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property array<string, mixed> $bag
 * @property int $schema_version
 */
class WindowState extends Model
{
    protected $table = 'system_x_window_states';

    protected $fillable = [
        'principal_type',
        'principal_id',
        'window_id',
        'bag',
        'schema_version',
    ];

    // Plain 'array' cast (D6): the store normalises miss/empty to [] in load()
    // before any caller sees it, so the {}-vs-[] / json_decode('')->null trap
    // never reaches HelloApp. AsArrayObject is only worth it once the bag is
    // broadcast-adjacent or a driver assumes a map -- neither is true in 4a.
    protected $casts = [
        'bag' => 'array',
        'schema_version' => 'integer',
    ];

    // Chunked TTL delete for the GC sweep (D5). A single big DELETE can long-lock
    // the table, so we delete in bounded batches until none remain. This is a
    // GLOBAL `WHERE updated_at < ?` (no principal predicate) served by the
    // single-column window_state_gc index on updated_at, so the WHERE is index-only
    // rather than a full scan. Returns the total rows deleted.
    public static function pruneOlderThan(CarbonInterface $cutoff, int $chunk = 1000): int
    {
        $deleted = 0;

        do {
            $batch = static::query()
                ->where('updated_at', '<', $cutoff)
                ->limit($chunk)
                ->delete();

            $deleted += $batch;
        } while ($batch > 0);

        return $deleted;
    }
}
