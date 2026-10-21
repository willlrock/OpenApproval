import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { copyFileSync, existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const base = join(root, 'storage/framework/testing');
mkdirSync(base, { recursive: true });

function fixture(t) {
    const directory = mkdtempSync(join(base, 'local-install-'));
    assert.ok(directory.startsWith(`${base}/`) || directory.startsWith(`${base}\\`));
    t.after(() => rmSync(directory, { recursive: true, force: true }));
    mkdirSync(join(directory, 'scripts'));
    mkdirSync(join(directory, 'database'));
    copyFileSync(join(root, 'scripts/init-local-env.mjs'), join(directory, 'scripts/init-local-env.mjs'));
    copyFileSync(join(root, '.env.example'), join(directory, '.env.example'));
    return { directory, run: () => spawnSync(process.execPath, [join(directory, 'scripts/init-local-env.mjs')], { encoding: 'utf8' }) };
}

test('fresh local setup uses its own SQLite database and leaves integrations unconfigured', (t) => {
    const { directory, run } = fixture(t);
    const result = run();
    assert.equal(result.status, 0, result.stderr);
    const env = readFileSync(join(directory, '.env'), 'utf8');
    assert.match(env, /^DB_CONNECTION=sqlite$/m);
    assert.match(env, /^APP_ENV=local$/m);
    assert.match(env, /^TELEGRAM_TOKEN=$/m);
    assert.match(env, /^TELEGRAM_CHANNEL_INVITE_URL=$/m);
    assert.ok(env.includes(join(directory, 'database/database.sqlite').replaceAll('\\', '/')));
    assert.ok(existsSync(join(directory, 'database/database.sqlite')));
});

test('a repeated setup refuses to change existing credentials or database contents', (t) => {
    const { directory, run } = fixture(t);
    writeFileSync(join(directory, '.env'), 'APP_KEY=existing-local-fixture\n');
    writeFileSync(join(directory, 'database/database.sqlite'), 'existing-database-fixture');
    assert.notEqual(run().status, 0);
    assert.equal(readFileSync(join(directory, '.env'), 'utf8'), 'APP_KEY=existing-local-fixture\n');
    assert.equal(readFileSync(join(directory, 'database/database.sqlite'), 'utf8'), 'existing-database-fixture');
});
