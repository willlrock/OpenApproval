import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import ts from 'typescript';

const code = ts.transpileModule(
    readFileSync(
        new URL('../resources/js/lib/monitoring.ts', import.meta.url),
        'utf8',
    ),
    {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ES2022,
        },
    },
).outputText;
const module = { exports: {} };
const initializations = [];
new Function('require', 'module', 'exports', code)(
    () => ({ init: (options) => initializations.push(options) }),
    module,
    module.exports,
);
const { scrubBrowserEvent, initializeMonitoring } = module.exports;

test('browser reports omit form state urls breadcrumbs user details and stack variables', () => {
    const safe = scrubBrowserEvent(
        {
            event_id: 'event-1',
            release: 'release-1',
            request: {
                url: 'https://app/verify/private-hash',
                data: 'private-form',
            },
            user: { email: 'private@example.com' },
            extra: { props: 'private-document' },
            breadcrumbs: [{ message: 'private-navigation' }],
            exception: {
                values: [
                    {
                        type: 'TypeError',
                        value: 'token=private-token',
                        stacktrace: {
                            frames: [
                                {
                                    filename:
                                        'https://app/build/app.js?token=private-query',
                                    lineno: 4,
                                    colno: 2,
                                    vars: { password: 'private-var' },
                                },
                            ],
                        },
                    },
                ],
            },
        },
        '/dashboard',
    );
    const text = JSON.stringify(safe);
    assert.equal(safe.event_id, 'event-1');
    assert.equal(safe.exception.values[0].stacktrace.frames[0].lineno, 4);
    assert.ok(!text.includes('private-'));
    assert.ok(!text.includes('private@example.com'));
    for (const key of ['request', 'user', 'extra', 'breadcrumbs'])
        assert.ok(!(key in safe));
});

test('monitoring does not initialize or send without a dsn', () => {
    globalThis.document = { querySelector: () => null };
    globalThis.window = { location: { pathname: '/dashboard' } };
    initializeMonitoring({});
    assert.equal(initializations.length, 0);
    globalThis.document = {
        querySelector: () => ({ content: 'https://public@example.com/1' }),
    };
    initializeMonitoring({});
    assert.equal(initializations.length, 1);
    assert.equal(initializations[0].tracesSampleRate, 0);
    assert.equal(initializations[0].dataCollection.userInfo, false);
    assert.equal(initializations[0].attachProps, false);
    assert.equal(initializations[0].replaysSessionSampleRate, 0);
    assert.deepEqual(
        initializations[0].integrations([
            { name: 'Breadcrumbs' },
            { name: 'BrowserSession' },
            { name: 'GlobalHandlers' },
        ]),
        [{ name: 'GlobalHandlers' }],
    );
});
