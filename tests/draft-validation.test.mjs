import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { test } from 'node:test';
import { parse, compileScript } from '@vue/compiler-sfc';
import ts from 'typescript';
import * as Vue from 'vue';

const require = createRequire(import.meta.url);
const componentSource = readFileSync(
    new URL('../resources/js/pages/RequestDrafts/Edit.vue', import.meta.url),
    'utf8',
);
const script = compileScript(parse(componentSource).descriptor, {
    id: 'draft-test',
});
const componentCode = ts.transpileModule(script.content, {
    compilerOptions: {
        module: ts.ModuleKind.CommonJS,
        target: ts.ScriptTarget.ES2022,
    },
}).outputText;

function editor(data, response) {
    const calls = [],
        visits = [],
        focused = [];
    const fakeRequire = (name) => {
        if (name === 'vue')
            return { ...Vue, onMounted() {}, onBeforeUnmount() {} };
        if (name === '@inertiajs/vue3')
            return {
                router: { visit: (url) => visits.push(url) },
                usePage: () => ({ props: { auth: { user: { id: 1 } } } }),
            };
        if (name === '@/lib/draftValidation') return { validateDraft };
        if (name === '@/lib/draftInputs') return draftInputs;
        if (name.startsWith('@/')) return {};
        return require(name);
    };
    const module = { exports: {} };
    new Function(
        'require',
        'module',
        'exports',
        'fetch',
        'document',
        'sessionStorage',
        'setTimeout',
        'clearTimeout',
        componentCode,
    )(
        fakeRequire,
        module,
        module.exports,
        async (...args) => {
            calls.push(args);
            return response(...args);
        },
        {
            cookie: '',
            getElementById: (id) => ({
                focus: () => focused.push(id),
                scrollIntoView() {},
            }),
        },
        { removeItem() {}, setItem() {} },
        () => 0,
        () => {},
    );
    const state = module.exports.default.setup(
        {
            draft: {
                id: 'test',
                payload: data,
                version: 1,
                updated_at: '2026-09-15T00:00:00Z',
                files: [],
            },
            projects: context.projects,
            suppliers: context.suppliers,
            approversByProject: { 1: context.approvers },
            supplyApproversByProject: { 1: context.supplyChain },
        },
        { expose() {} },
    );
    return { state, calls, visits, focused };
}
const flush = () => new Promise((resolve) => setImmediate(resolve));

const code = ts.transpileModule(
    readFileSync(
        new URL('../resources/js/lib/draftValidation.ts', import.meta.url),
        'utf8',
    ),
    {
        compilerOptions: {
            module: ts.ModuleKind.ESNext,
            target: ts.ScriptTarget.ES2022,
        },
    },
).outputText;
const draftInputsCode = ts.transpileModule(
    readFileSync(
        new URL('../resources/js/lib/draftInputs.ts', import.meta.url),
        'utf8',
    ),
    {
        compilerOptions: {
            module: ts.ModuleKind.ESNext,
            target: ts.ScriptTarget.ES2022,
        },
    },
).outputText;
const draftInputs = await import(
    'data:text/javascript;base64,' +
        Buffer.from(draftInputsCode).toString('base64')
);
const validationCode = code.replace(
    './draftInputs',
    'data:text/javascript;base64,' +
        Buffer.from(draftInputsCode).toString('base64'),
);
const { validateDraft } = await import(
    'data:text/javascript;base64,' +
        Buffer.from(validationCode).toString('base64')
);
const context = {
    projects: [{ id: 1 }],
    suppliers: [{ id: 2, name: 'OOO Eskiz', inn: '123456789' }],
    approvers: [{ id: 3 }],
    supplyChain: [{ id: 3 }],
    today: '2026-09-15',
};
const valid = () => ({
    type: 'approval',
    project_id: 1,
    currency: 'UZS',
    transaction_type: 'bank',
    supplier_name: 'Поставщик',
    supplier_id: '',
    deadline_date: '',
    items: [{ name: 'Бумага', quantity: '2', price_per_unit: '10.25' }],
    approvers: [3],
    tranches: [],
});
const selectedFiles = (...files) => ({ target: { files, value: 'selected' } });
const photo = (name = 'photo.png') => new File(['image'], name, { type: 'image/png' });

test('failed upload survives autosave and blocks review and submit until retry succeeds', async () => {
    let fail = true;
    const e = editor(valid(), async (url) => {
        if (url.endsWith('/files')) {
            if (fail) return { ok: false, status: 503, json: async () => ({ message: 'Повторите загрузку' }) };
            return { ok: true, json: async () => ({ version: 3, updated_at: 'now', files: [{ id: 1, name: 'photo.png' }] }) };
        }
        return { ok: true, json: async () => ({ version: 2, updated_at: 'now' }) };
    });
    e.state.upload(selectedFiles(photo()));
    // The selected file blocks sending even before the queue starts.
    assert.ok(e.state.submitBlockReason.value);
    await flush();
    assert.equal(e.state.pendingUploads.value[0].status, 'failed');
    e.state.payload.value.items[0].name = 'После ошибки';
    await Vue.nextTick();
    await e.state.enqueue(e.state.persist);
    assert.equal(e.state.state.value, 'saved');
    assert.equal(e.state.pendingUploads.value[0].error, 'Повторите загрузку');
    e.state.check();
    e.state.review.value = true;
    e.state.submit();
    await flush();
    assert.equal(e.calls.some(([url]) => url.endsWith('/submit')), false);
    fail = false;
    e.state.retryUpload(e.state.pendingUploads.value[0].id);
    await flush();
    assert.equal(e.state.pendingUploads.value.length, 0);
    assert.equal(e.state.files.value.length, 1);
    e.state.check();
    await flush();
    assert.equal(e.state.review.value, true);
});

test('mixed uploads retain successes and retry only the failed file', async () => {
    let attempts = 0;
    const uploaded = [];
    const e = editor(valid(), async (url, options) => {
        assert.ok(url.endsWith('/files'));
        attempts++;
        const file = options.body.get('file');
        if (attempts === 2) return { ok: false, status: 422, json: async () => ({ errors: { file: ['Неверный формат'] } }) };
        uploaded.push({ id: attempts, name: file.name });
        return { ok: true, json: async () => ({ version: attempts + 1, files: [...uploaded], updated_at: 'now' }) };
    });
    e.state.upload(selectedFiles(photo('first.png'), photo('second.png'), photo('third.png')));
    await flush();
    assert.deepEqual(e.state.files.value.map((file) => file.name), ['first.png', 'third.png']);
    assert.equal(e.state.pendingUploads.value[0].file.name, 'second.png');
    assert.equal(e.state.pendingUploads.value[0].error, 'Неверный формат');
    e.state.retryUpload(e.state.pendingUploads.value[0].id);
    await flush();
    assert.equal(e.calls.length, 4);
    assert.equal(e.state.pendingUploads.value.length, 0);
    assert.equal(e.state.files.value.length, 3);
});

test('explicit removal resolves a failed upload and unload warns before that removal', async () => {
    const e = editor(valid(), async () => { throw Error('Сеть недоступна'); });
    e.state.upload(selectedFiles(photo()));
    await flush();
    let warned = false;
    e.state.leave({ preventDefault() { warned = true; } });
    assert.equal(warned, true);
    e.state.discardUpload(e.state.pendingUploads.value[0].id);
    assert.equal(e.state.pendingUploads.value.length, 0);
    e.state.check();
    await flush();
    assert.equal(e.state.review.value, true);
});

test('upload stays blocked while pending and oversized files never reach the server', async () => {
    let release;
    const e = editor(valid(), () => new Promise((resolve) => { release = resolve; }));
    e.state.upload(selectedFiles(photo()));
    await flush();
    e.state.check();
    assert.equal(e.state.review.value, false);
    assert.equal(e.state.pendingUploads.value[0].status, 'uploading');
    release({ ok: true, json: async () => ({ version: 2, files: [{ id: 1 }], updated_at: 'now' }) });
    await flush();
    const large = new File([new Uint8Array(15 * 1024 * 1024 + 1)], 'large.png');
    e.state.upload(selectedFiles(large));
    await flush();
    assert.equal(e.calls.length, 1);
    assert.equal(e.state.pendingUploads.value[0].status, 'failed');
    assert.match(e.state.pendingUploads.value[0].error, /15 МБ/);
});

test('failure to save fields retains selected files for retry', async () => {
    const e = editor(valid(), async () => { throw Error('Сеть недоступна'); });
    e.state.payload.value.items[0].name = 'Изменено';
    await Vue.nextTick();
    e.state.upload(selectedFiles(photo()));
    await flush();
    assert.equal(e.state.pendingUploads.value.length, 1);
    assert.equal(e.state.pendingUploads.value[0].status, 'failed');
    assert.ok(e.state.submitBlockReason.value);
});
test('empty check stays in form, focuses error and makes no request', async () => {
    const e = editor({ ...valid(), project_id: '', approvers: [] }, () => {
        throw Error('unexpected fetch');
    });
    e.state.check();
    await flush();
    assert.equal(e.state.review.value, false);
    assert.equal(e.calls.length, 0);
    assert.ok(e.state.errors.value.project_id);
    assert.equal(e.focused[0], 'draft-field-project_id');
});
test('valid check opens review and repeated submit sends only once', async () => {
    let release;
    const e = editor(
        valid(),
        () =>
            new Promise((resolve) => {
                release = resolve;
            }),
    );
    e.state.check();
    await flush();
    assert.equal(e.state.review.value, true);
    e.state.submit();
    e.state.submit();
    await flush();
    assert.equal(e.calls.length, 1);
    assert.ok(e.state.submitBlockReason.value);
    release({ ok: true, json: async () => ({ url: '/submitted' }) });
    await flush();
    e.state.submit();
    await flush();
    assert.equal(e.calls.length, 1);
    assert.deepEqual(e.visits, ['/submitted']);
});
test('server field rejection returns to form and preserves entered values', async () => {
    const e = editor(valid(), async () => ({
        ok: false,
        status: 422,
        json: async () => ({
            message: 'Проверьте данные',
            errors: { approvers: ['Согласующий больше недоступен'] },
        }),
    }));
    e.state.check();
    await flush();
    e.state.submit();
    await flush();
    assert.equal(e.state.review.value, false);
    assert.deepEqual(e.state.errors.value.approvers, [
        'Согласующий больше недоступен',
    ]);
    assert.equal(e.state.payload.value.items[0].name, 'Бумага');
    assert.equal(e.focused[0], 'draft-field-approvers');
});
test('failed save prevents review without losing edits', async () => {
    const e = editor(valid(), async () => {
        throw Error('network');
    });
    e.state.payload.value.items[0].name = 'Изменено';
    await Vue.nextTick();
    e.state.check();
    await flush();
    assert.equal(e.state.review.value, false);
    assert.equal(e.state.state.value, 'error');
    assert.equal(e.state.payload.value.items[0].name, 'Изменено');
});
test('empty draft identifies project, supplier, item fields and route', () => {
    const errors = validateDraft(
        {
            ...valid(),
            project_id: '',
            supplier_name: ' ',
            items: [{ name: '', quantity: '', price_per_unit: '' }],
            approvers: [],
        },
        context,
    );
    for (const key of [
        'project_id',
        'supplier_name',
        'items.0.name',
        'items.0.quantity',
        'items.0.price_per_unit',
        'approvers',
    ])
        assert.ok(errors[key], key);
});
test('expense accepts optional deadline and manual supplier', () =>
    assert.deepEqual(validateDraft(valid(), context), {}));
test('directory supplier does not require manual name', () =>
    assert.deepEqual(
        validateDraft(
            { ...valid(), supplier_id: 2, supplier_name: '' },
            context,
        ),
        {},
    ));
test('supply ignores expense fields and uses automatic route', () =>
    assert.deepEqual(
        validateDraft(
            {
                ...valid(),
                type: 'supply',
                deadline_date: '2026-09-15',
                currency: '',
                supplier_name: '',
                approvers: [],
                items: [{ name: 'Бумага', quantity: '.5' }],
            },
            context,
        ),
        {},
    ));
test('supply requires deadline and configured route', () => {
    const errors = validateDraft(
        { ...valid(), type: 'supply' },
        { ...context, supplyChain: [] },
    );
    assert.ok(errors.deadline_date);
    assert.ok(errors.approvers);
});
test('dates reject past dates and impossible calendar dates', () => {
    for (const deadline_date of ['2026-09-14', '2026-02-30'])
        assert.ok(
            validateDraft({ ...valid(), deadline_date }, context).deadline_date,
        );
});
test('unavailable or duplicate approvers fail', () => {
    for (const approvers of [[3, 3], [4]])
        assert.ok(validateDraft({ ...valid(), approvers }, context).approvers);
});
test('decimal precision and numeric limits match expense rules', () => {
    const p = valid();
    p.items[0].quantity = '0.0001';
    p.items[0].price_per_unit = '1.001';
    const errors = validateDraft(p, context);
    assert.ok(errors['items.0.quantity']);
    assert.ok(errors['items.0.price_per_unit']);
});
test('zero total is invalid but a zero-price line with a positive total is valid', () => {
    const p = valid();
    p.items[0].price_per_unit = '0';
    assert.ok(validateDraft(p, context).items);
    p.items.push({ name: 'Доставка', quantity: '1', price_per_unit: '10' });
    assert.deepEqual(validateDraft(p, context), {});
});
test('payment sum uses rounded cents and nested errors identify payment fields', () => {
    const p = valid();
    p.tranches = [{ amount: '20.50', due_date: '2026-09-15' }];
    assert.deepEqual(validateDraft(p, context), {});
    p.tranches[0] = { amount: '', due_date: '' };
    const errors = validateDraft(p, context);
    assert.ok(errors['tranches.0.amount']);
    assert.ok(errors['tranches.0.due_date']);
    assert.ok(errors.tranches);
});
test('validation never mutates autosaved draft', () => {
    const p = valid();
    const before = structuredClone(p);
    validateDraft(p, context);
    assert.deepEqual(p, before);
});

test('formatted money calculates totals and validates matching payments without rewriting input', () => {
    for (const price of [
        '600 000',
        '600000 ',
        '600\u00a0000',
        '600\u202f000',
        ' 600 000,50 ',
        '600000.50',
    ]) {
        const p = valid();
        p.items[0].price_per_unit = price;
        const amount = Number(draftInputs.normalizeMoneyInput(price)) * 2;
        p.tranches = [
            {
                amount: String(amount).replace('.', ','),
                due_date: '2026-09-15',
            },
        ];
        const before = structuredClone(p);
        assert.deepEqual(validateDraft(p, context), {});
        const e = editor(p, () => {
            throw Error('unexpected fetch');
        });
        assert.equal(e.state.total.value, amount);
        assert.deepEqual(p, before);
    }
    const p = valid();
    p.items[0].price_per_unit = '0 ';
    p.items.push({ name: 'Delivery', quantity: '1', price_per_unit: '10' });
    assert.deepEqual(validateDraft(p, context), {});
});

test('formatted money still rejects malformed, negative, excessive and overprecise prices', () => {
    for (const price of [
        '-600 000',
        '1,000.50',
        '1,23,45',
        '600 000,123',
        'money',
        '1 000 000 001',
        '   ',
    ]) {
        const p = valid();
        p.items[0].price_per_unit = price;
        assert.ok(validateDraft(p, context)['items.0.price_per_unit'], price);
    }
});

test('supplier suggestions match partial names, mixed case, spaces and INN', () => {
    const suppliers = [
        ...context.suppliers,
        { id: 4, name: 'Другая компания' },
    ];
    for (const query of ['esk', ' ESKIZ ', 'ooo esk', '123456'])
        assert.deepEqual(draftInputs.matchingSuppliers(suppliers, query), [
            suppliers[0],
        ]);
    for (const query of ['', '   ', 'unknown'])
        assert.deepEqual(draftInputs.matchingSuppliers(suppliers, query), []);
});

test('keyboard directory search fills the supplier id, canonical name and INN and restores focus', async () => {
    const e = editor({ ...valid(), supplier_name: 'esk' }, () => {
        throw Error('unexpected fetch');
    });
    e.state.searchSupplier();
    assert.equal(e.state.supplierMatches.value.length, 1);
    e.state.supplierKeydown({ key: 'ArrowDown', preventDefault() {} });
    assert.equal(e.state.supplierSuggestionsOpen.value, true);
    e.state.supplierKeydown({ key: 'Escape' });
    assert.equal(e.state.supplierSuggestionsOpen.value, false);
    e.state.supplierKeydown({ key: 'ArrowDown', preventDefault() {} });
    e.state.supplierKeydown({ key: 'Enter', preventDefault() {} });
    assert.equal(e.state.payload.value.supplier_id, 2);
    assert.equal(e.state.payload.value.supplier_name, 'OOO Eskiz');
    assert.equal(e.state.payload.value.supplier_inn, '123456789');
    assert.equal(e.state.supplierSuggestionsOpen.value, false);
    assert.deepEqual(e.state.supplierMatches.value, []);
    await flush();
    assert.equal(e.focused.at(-1), 'draft-field-supplier_id');
});

test('saved manual supplier stays editable and can switch to directory search without losing input', async () => {
    const e = editor({ ...valid(), supplier_name: 'Новая компания', supplier_inn: '987654321' }, () => {
        throw Error('unexpected fetch');
    });
    assert.equal(e.state.newSupplier.value, true);
    assert.equal(e.state.supplierSuggestionsOpen.value, false);
    e.state.searchSupplier();
    assert.equal(e.state.supplierSuggestionsOpen.value, true);
    assert.equal(e.state.supplierMatches.value.length, 0);
    e.state.useNewSupplier();
    assert.equal(e.state.newSupplier.value, true);
    assert.equal(e.state.payload.value.supplier_name, 'Новая компания');
    assert.equal(e.state.payload.value.supplier_inn, '987654321');
    await flush();
    assert.equal(e.focused.at(-1), 'draft-field-supplier_name');
});

test('decrement stops before zero without changing whole or fractional quantities', () => {
    const e = editor(valid(), () => {
        throw Error('unexpected fetch');
    });
    const item = e.state.payload.value.items[0];
    e.state.changeQuantity(item, -1);
    assert.equal(item.quantity, 1);
    e.state.changeQuantity(item, -1);
    assert.equal(item.quantity, 1);
    for (const quantity of ['0.5', '0.001', '1.000']) {
        item.quantity = quantity;
        e.state.changeQuantity(item, -1);
        assert.equal(item.quantity, quantity);
    }
    item.quantity = '1.5';
    e.state.changeQuantity(item, -1);
    assert.equal(item.quantity, 0.5);
    e.state.changeQuantity(item, 1);
    assert.equal(item.quantity, 1.5);
});

test('manually entered zero and negative quantities show errors and cannot open review', async () => {
    for (const quantity of ['0', '-1', '-0.5']) {
        const p = valid();
        p.items[0].quantity = quantity;
        const e = editor(p, () => {
            throw Error('unexpected fetch');
        });
        assert.ok(e.state.error('items.0.quantity'));
        assert.equal(
            e.state.fieldAttrs('items.0.quantity')['aria-invalid'],
            true,
        );
        e.state.check();
        await flush();
        assert.equal(e.state.review.value, false);
        assert.equal(e.calls.length, 0);
        assert.equal(e.state.payload.value.items[0].quantity, quantity);
        e.state.payload.value.items[0].quantity = '1';
        await Vue.nextTick();
        assert.equal(e.state.error('items.0.quantity'), undefined);
    }
});
