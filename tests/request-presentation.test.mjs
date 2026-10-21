import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import ts from 'typescript';
function load(relative, dependencies = {}) {
    const code = ts.transpileModule(
        readFileSync(new URL(relative, import.meta.url), 'utf8'),
        {
            compilerOptions: {
                module: ts.ModuleKind.CommonJS,
                target: ts.ScriptTarget.ES2022,
            },
        },
    ).outputText;
    const module = { exports: {} };
    new Function('require', 'module', 'exports', code)(
        (name) => {
            assert.ok(name in dependencies, `Unexpected dependency: ${name}`);
            return dependencies[name];
        },
        module,
        module.exports,
    );
    return module.exports;
}
const labels = load('../resources/js/lib/requestLabels.ts');
const presentation = load('../resources/js/lib/requestPresentation.ts', {
    '@/lib/requestLabels': labels,
});
test('money keeps two decimal places and distinguishes zero from an unknown amount', () => {
    assert.equal(
        presentation.formatMoney('12345678.50'),
        '12\u00a0345\u00a0678,50',
    );
    assert.equal(presentation.formatMoney(0), '0,00');
    assert.equal(presentation.formatMoney('100'), '100,00');
    for (const value of [
        null,
        undefined,
        '',
        '   ',
        'not-a-number',
        NaN,
        Infinity,
    ])
        assert.equal(presentation.formatMoney(value), '—');
});
test('all request phases have labels, including cancellation and return for changes', () => {
    for (const [status, label] of Object.entries(labels.requestStatusLabels)) {
        const result = presentation.requestStatusPresentation(status);
        assert.equal(result.label, label);
        assert.ok(presentation.statusToneClasses[result.tone]);
    }
    assert.equal(
        presentation.requestStatusPresentation('approval_paused').tone,
        'pending',
    );
    assert.equal(
        presentation.requestStatusPresentation('revision_requested').tone,
        'pending',
    );
    assert.equal(
        presentation.requestStatusPresentation('cancelled').tone,
        'neutral',
    );
    assert.deepEqual(
        presentation.requestStatusPresentation('new_backend_phase'),
        { label: 'Статус не определён', tone: 'neutral' },
    );
});
function listFeedback() {
    const handlers = {},
        cleanup = [];
    const { useListFeedback } = load(
        '../resources/js/composables/useListFeedback.ts',
        {
            '@inertiajs/vue3': {
                router: {
                    on(name, handler) {
                        handlers[name] = handler;
                        return () => {
                            delete handlers[name];
                        };
                    },
                },
            },
            vue: {
                ref: (value) => ({ value }),
                onBeforeUnmount: (callback) => cleanup.push(callback),
            },
        },
    );
    return { state: useListFeedback('/supply/requests'), handlers, cleanup };
}
function visit(search) {
    return {
        detail: {
            visit: {
                method: 'get',
                url: new URL(
                    `http://localhost/supply/requests?search=${search}`,
                ),
            },
        },
    };
}
test('a failed refresh retains a recovery message, and retry clears it', () => {
    const { state, handlers } = listFeedback();
    handlers.start(visit('current'));
    assert.equal(state.loading.value, true);
    assert.equal(
        handlers.invalid({
            detail: {
                response: {
                    status: 500,
                    config: {
                        method: 'get',
                        url: 'http://localhost/supply/requests?search=current',
                    },
                },
            },
        }),
        false,
    );
    assert.equal(state.loading.value, false);
    assert.match(state.error.value, /предыдущие данные/);
    handlers.start(visit('retry'));
    assert.equal(state.error.value, '');
    handlers.finish(visit('retry'));
    assert.equal(state.loading.value, false);
});
test('feedback ignores other modules, POSTs, old responses, and removes its listeners', () => {
    const { state, handlers, cleanup } = listFeedback();
    handlers.start(visit('old'));
    handlers.start(visit('new'));
    handlers.finish(visit('old'));
    assert.equal(state.loading.value, true);
    for (const config of [
        { url: 'http://localhost/finance/requests', method: 'get' },
        { url: 'http://localhost/supply/requests?search=new', method: 'post' },
        { url: 'http://localhost/supply/requests?search=old', method: 'get' },
    ])
        assert.equal(
            handlers.invalid({ detail: { response: { status: 500, config } } }),
            undefined,
        );
    assert.equal(state.error.value, '');
    handlers.exception({
        detail: {
            exception: Object.assign(new Error('offline'), {
                config: {
                    url: 'http://localhost/supply/requests?search=new',
                    method: 'get',
                },
            }),
        },
    });
    assert.match(state.error.value, /Нет связи/);
    cleanup.forEach((callback) => callback());
    assert.deepEqual(handlers, {});
});
