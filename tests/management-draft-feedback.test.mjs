import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { test } from 'node:test';
import { parse, compileScript } from '@vue/compiler-sfc';
import ts from 'typescript';
import * as Vue from 'vue';

const require = createRequire(import.meta.url);
const source = readFileSync(
    new URL(
        '../resources/js/pages/RequestDrafts/Management.vue',
        import.meta.url,
    ),
    'utf8',
);
const script = compileScript(parse(source).descriptor, {
    id: 'management-feedback-test',
});
const code = ts.transpileModule(script.content, {
    compilerOptions: {
        module: ts.ModuleKind.CommonJS,
        target: ts.ScriptTarget.ES2022,
    },
}).outputText;

function editor(respond) {
    const calls = [],
        visits = [],
        timers = new Map();
    let nextTimer = 0;
    const module = { exports: {} };
    const scope = Vue.effectScope();
    new Function(
        'require',
        'module',
        'exports',
        'fetch',
        'document',
        'window',
        'setTimeout',
        'clearTimeout',
        code,
    )(
        (name) => {
            if (name === 'vue') return { ...Vue, onBeforeUnmount() {} };
            if (name === '@inertiajs/vue3')
                return {
                    router: {
                        on: () => () => {},
                        visit: (url) => visits.push(url),
                    },
                };
            if (name.startsWith('@/')) return {};
            return require(name);
        },
        module,
        module.exports,
        async (url, options) => {
            calls.push({
                url,
                method: options.method,
                body: JSON.parse(options.body),
            });
            return respond(url, options);
        },
        { cookie: '' },
        {
            addEventListener() {},
            removeEventListener() {},
            confirm: () => false,
        },
        (callback) => {
            timers.set(++nextTimer, callback);
            return nextTimer;
        },
        (id) => timers.delete(id),
    );
    const state = scope.run(() =>
        module.exports.default.setup(
            {
                draft: {
                    id: 'test',
                    version: 1,
                    preview_version: 1,
                    payload: {
                        title: 'Исходное название',
                        project_id: 1,
                        approvers: [7],
                    },
                },
                files: [],
                projects: [{ id: 1, name: 'Проект' }],
                signers: { 1: [] },
            },
            { expose() {} },
        ),
    );
    return {
        state,
        calls,
        visits,
        dispose: () => scope.stop(),
        async autosave(title) {
            state.payload.value.title = title;
            await Vue.nextTick();
            assert.equal(
                timers.size,
                1,
                'edits schedule a single debounced save',
            );
            const [id, callback] = [...timers][0];
            timers.delete(id);
            await callback();
            await Vue.nextTick();
        },
    };
}

const success = (result) => ({ ok: true, json: async () => result });
const deferred = () => {
    let resolve;
    const promise = new Promise((done) => {
        resolve = done;
    });
    return { promise, resolve };
};

test('offline autosave preserves edits and version; explicit retry saves and clears feedback', async (t) => {
    let offline = true;
    const { state, calls, autosave, dispose } = editor(async () => {
        if (offline) throw new TypeError('Failed to fetch');
        return success({ version: 2 });
    });
    t.after(dispose);
    await autosave('Данные после обрыва связи');
    assert.equal(state.payload.value.title, 'Данные после обрыва связи');
    assert.equal(state.version.value, 1);
    assert.equal(state.dirty.value, true);
    assert.match(
        state.errors.value.join(' '),
        /Нет связи.*повторите сохранение/,
    );
    assert.match(state.status.value, /Не сохранено/);

    offline = false;
    await state.action(async () => {});
    assert.deepEqual(
        calls.map(({ method, body }) => ({
            method,
            version: body.version,
            title: body.payload.title,
        })),
        [
            { method: 'PUT', version: 1, title: 'Данные после обрыва связи' },
            { method: 'PUT', version: 1, title: 'Данные после обрыва связи' },
        ],
    );
    assert.equal(state.version.value, 2);
    assert.equal(state.dirty.value, false);
    assert.deepEqual(state.errors.value, []);
    assert.equal(state.status.value, 'Сохранено');
    assert.equal(state.busy.value, false);
    assert.equal(state.pendingAction.value, '');
});

test('a non-JSON server response exposes a recoverable error without losing edits', async (t) => {
    const harness = editor(async () => ({
        ok: true,
        json: async () => {
            throw new SyntaxError('Unexpected token <');
        },
    }));
    t.after(harness.dispose);
    await harness.autosave('Новое название');
    assert.equal(harness.state.payload.value.title, 'Новое название');
    assert.equal(harness.state.dirty.value, true);
    assert.equal(harness.state.version.value, 1);
    assert.match(
        harness.state.errors.value.join(' '),
        /неожиданный ответ.*Повторите сохранение/,
    );
});

test('overlapping saves serialize and send later edits using the acknowledged version', async (t) => {
    const first = deferred();
    let request = 0;
    const { state, calls, dispose } = editor(async () =>
        ++request === 1 ? first.promise : success({ version: 3 }),
    );
    t.after(dispose);
    state.payload.value.title = 'Первое изменение';
    await Vue.nextTick();
    const save = state.save();
    await new Promise(setImmediate);
    assert.equal(calls.length, 1);
    state.payload.value.title = 'Последнее изменение';
    await Vue.nextTick();
    const overlapping = state.save();
    first.resolve(success({ version: 2 }));
    await Promise.all([save, overlapping]);
    assert.deepEqual(
        calls.map(({ body }) => [body.version, body.payload.title]),
        [
            [1, 'Первое изменение'],
            [2, 'Последнее изменение'],
        ],
    );
    assert.equal(state.version.value, 3);
    assert.equal(state.dirty.value, false);
});

test('preview confirmation and busy guard prevent premature or duplicate submission', async (t) => {
    const pending = deferred();
    const { state, calls, visits, dispose } = editor(
        async () => pending.promise,
    );
    t.after(dispose);
    await state.submit();
    assert.equal(
        calls.length,
        0,
        'a preview must be confirmed before submission',
    );
    state.confirmed.value = true;
    const first = state.submit();
    const duplicate = state.submit();
    await new Promise(setImmediate);
    assert.equal(state.busy.value, true);
    assert.equal(state.pendingAction.value, 'submit');
    assert.equal(calls.length, 1);
    assert.deepEqual(calls[0], {
        url: '/request-drafts/test/submit',
        method: 'POST',
        body: { version: 1 },
    });
    pending.resolve(success({ url: '/dashboard/requests/42' }));
    await Promise.all([first, duplicate]);
    assert.deepEqual(visits, ['/dashboard/requests/42']);
    assert.equal(state.busy.value, false);
});
