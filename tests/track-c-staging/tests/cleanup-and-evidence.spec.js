const { test, expect } = require('@playwright/test');
const fs = require('fs');
const { PREFIX, manifestPath, recordObservation } = require('../support/staging');

test.describe('Audit cleanup and evidence integrity', () => {
  test('fixture manifest exists, is prefixed, and records cleanup policy', async () => {
    expect(fs.existsSync(manifestPath)).toBeTruthy();
    const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));
    expect(manifest.prefix).toBe(PREFIX);
    for (const fixture of manifest.fixtures || []) {
      expect(fixture.name.startsWith(PREFIX)).toBeTruthy();
      expect(['archive', 'delete', null].includes(fixture.cleanup)).toBeTruthy();
    }
    recordObservation({ track: 'cleanup', check: 'manifest_integrity', fixtureCount: (manifest.fixtures || []).length, policy: 'only audit-prefixed fixtures may be deleted or archived' });
  });

  test('cleanup executor is disabled unless explicitly enabled for staging', async () => {
    const enabled = process.env.CREDOQ_ENABLE_CLEANUP === 'true';
    expect(enabled).toBe(false);
    recordObservation({ track: 'cleanup', check: 'destructive_cleanup_guard', enabled, result: enabled ? 'manual staging cleanup requested' : 'dry-run only' });
  });
});
