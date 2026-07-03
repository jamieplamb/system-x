import { describe, it, expect } from 'vitest';
// Importing the barrel self-registers all 11 core renderers into the singleton.
import { registry } from '../../resources/js/system-x/renderers.js';
// The manifest the PHP pairing test reads -- imported as data, so this asserts
// against the exact same source of truth.
import manifest from '../../resources/js/system-x/registered-types.json';

// Closes the last trust-based link in the pairing chain:
//   real JS renderers -> (this test) -> registered-types.json -> (PHP pairing test) -> PHP builders
// Without this, a renderer added to renderers.js without a matching manifest entry
// (or a stale manifest entry with no real renderer) would slip through every test.

describe('registered-types.json manifest', () => {
    it('matches the renderers actually registered in the singleton', () => {
        expect([...manifest].sort()).toEqual([...registry.types()].sort());
    });
});
