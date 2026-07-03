<?php

namespace SystemX\Core\State;

use Illuminate\Support\Facades\Log;

class DatabaseStateStore implements StateStore
{
    // The bag-FORMAT version this code understands (D4). Bumped when the bag shape
    // changes; a persisted bag stamped with a different version is migrate-or-discard
    // on load. Kept STRICTLY distinct from any future optimistic-lock version.
    public const SCHEMA_VERSION = 1;

    public function load(StateKey $key): StateBag
    {
        $row = WindowState::query()
            ->where('principal_type', $key->principalType)
            ->where('principal_id', $key->principalId)
            ->where('window_id', $key->windowId)
            ->first();

        // Miss -> a fresh default bag. Callers never null-check.
        if ($row === null) {
            return new StateBag([], self::SCHEMA_VERSION);
        }

        // migrate-or-discard (D4): on a version mismatch, DISCARD the stale bag and
        // log it so a redeploy that reshapes the bag is observable, never silent.
        // This single branch is the exact hook 4b swaps for an app-supplied migrate().
        if ($row->schema_version !== self::SCHEMA_VERSION) {
            Log::info('system-x: discarding window state with mismatched schema version', [
                'principal_type' => $key->principalType,
                'principal_id' => $key->principalId,
                'window_id' => $key->windowId,
                'stored_version' => $row->schema_version,
                'current_version' => self::SCHEMA_VERSION,
            ]);

            return new StateBag([], self::SCHEMA_VERSION);
        }

        return new StateBag($row->bag ?? [], $row->schema_version);
    }

    public function save(StateKey $key, StateBag $bag): void
    {
        // updateOrCreate against the composite unique: the key columns are plain
        // strings in the MATCH array, the casted bag + schema_version in the VALUES
        // array only (dodges the updateOrCreate-with-casts quirk). The unique index
        // also guards the create-on-first-event race -- a second insert collides on
        // the constraint rather than duplicating.
        WindowState::query()->updateOrCreate(
            [
                'principal_type' => $key->principalType,
                'principal_id' => $key->principalId,
                'window_id' => $key->windowId,
            ],
            [
                'bag' => $bag->toArray(),
                'schema_version' => self::SCHEMA_VERSION,
            ],
        );
    }

    public function forget(StateKey $key): void
    {
        WindowState::query()
            ->where('principal_type', $key->principalType)
            ->where('principal_id', $key->principalId)
            ->where('window_id', $key->windowId)
            ->delete();
    }
}
