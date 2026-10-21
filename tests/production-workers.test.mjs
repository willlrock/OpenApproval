import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const deadlineConfig = read('config/deadlines.php');
const deadlineQueue = deadlineConfig.match(/'queue'\s*=>\s*env\('DEADLINES_QUEUE',\s*'([^']+)'\)/)?.[1];
assert.ok(deadlineQueue, 'Resolve the application deadline queue before checking workers');

function programs(path) {
    return [...read(path).matchAll(/^\[program:([^\]]+)\]\r?\n([\s\S]*?)(?=^\[|$(?![\s\S]))/gm)].map(
        ([, name, body]) => ({ name, command: body.match(/^command=(.+)$/m)?.[1].trim(), body }),
    );
}

for (const path of ['ops/supervisor.conf', 'docker/supervisord.conf']) {
    test(`${path} processes every application queue and runs the scheduler`, () => {
        const processes = programs(path);
        const workers = processes.filter(({ command }) => command?.includes('artisan queue:work'));
        const consumed = new Set(
            workers.flatMap(({ command }) => command.match(/--queue=([^\s]+)/)?.[1].split(',') ?? ['default']),
        );
        for (const queue of ['default', deadlineQueue]) {
            assert.ok(consumed.has(queue), `${queue} would accumulate without a queue worker`);
        }
        assert.equal(processes.filter(({ command }) => command?.includes('artisan schedule:work')).length, 1);
        for (const worker of workers) {
            if (path.startsWith('docker/')) {
                assert.match(worker.body, /^autostart=%\(ENV_TEG_BACKGROUND_ENABLED\)s\s*$/m);
            } else {
                assert.match(worker.body, /^autostart=true\s*$/m);
            }
            assert.match(worker.body, /^autorestart=true\s*$/m);
            assert.match(worker.body, /^stopasgroup=true\s*$/m);
        }
    });
}

test('rehearsal prevents background delivery and external API access', () => {
    const override = read('docker-compose.rehearsal.yml');
    assert.match(override, /TEG_BACKGROUND_ENABLED:\s*"false"/);
    assert.match(override, /RUN_MIGRATIONS:\s*"false"/);
    assert.match(override, /TELEGRAM_TOKEN:\s*""/);
    assert.match(override, /internal:\s*true/);
    const background = programs('docker/supervisord.conf').filter(({ command }) =>
        /artisan (queue:work|schedule:work)/.test(command),
    );
    assert.ok(background.length >= 2);
    for (const program of background) {
        assert.match(program.body, /^autostart=%\(ENV_TEG_BACKGROUND_ENABLED\)s\s*$/m);
    }
});
