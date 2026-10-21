import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { test } from 'node:test';
import { parse, compileScript } from '@vue/compiler-sfc';
import ts from 'typescript';
import * as Vue from 'vue';

const require = createRequire(import.meta.url);
const transpile = (source) =>
    ts.transpileModule(source, {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ES2022,
        },
    }).outputText;
const countModule = { exports: {} };
new Function(
    'exports',
    transpile(
        readFileSync(
            new URL('../resources/js/lib/formatCount.ts', import.meta.url),
            'utf8',
        ),
    ),
)(countModule.exports);
function pageScript(page) {
    return compileScript(
        parse(
            readFileSync(
                new URL(
                    `../resources/js/pages/Dashboard/${page}.vue`,
                    import.meta.url,
                ),
                'utf8',
            ),
        ).descriptor,
        { id: `directory-${page}` },
    );
}

function projectActions(page = 'Projects', filters = {}) {
    const calls = [],
        focused = [];
    const module = { exports: {} };
    let menuExists = true;
    const unmount = [];
    new Function(
        'require',
        'module',
        'exports',
        'document',
        transpile(pageScript(page).content),
    )(
        (name) => {
            if (name === 'vue')
                return {
                    ...Vue,
                    onBeforeUnmount: (callback) => unmount.push(callback),
                };
            if (name === '@inertiajs/vue3')
                return {
                    usePage: () => ({
                        props: { auth: { user: { role: 'admin' } } },
                    }),
                    useForm: (data) =>
                        Vue.reactive({
                            ...data,
                            errors: {},
                            processing: false,
                        }),
                    router: {
                        delete: (url, options) => calls.push({ url, options }),
                        get: (url, data, options) =>
                            calls.push({ url, data, options }),
                    },
                };
            if (name === '@/composables/useListFeedback')
                return {
                    useListFeedback: () => ({
                        loading: Vue.ref(false),
                        error: Vue.ref(''),
                    }),
                };
            if (name === '@/lib/formatCount') return countModule.exports;
            if (name.startsWith('@/') || name === 'lucide-vue-next') return {};
            return require(name);
        },
        module,
        module.exports,
        {
            getElementById: (id) =>
                id.endsWith('-add') || menuExists
                    ? { focus: () => focused.push(id) }
                    : null,
        },
    );
    const state = module.exports.default.setup(
        {
            projects: [],
            departments: [],
            users: { data: [], total: 0 },
            suppliers: { data: [], total: 0 },
            filters,
            roles: [],
        },
        { expose() {} },
    );
    return {
        state,
        calls,
        focused,
        unmount: () => unmount.forEach((callback) => callback()),
        removeMenu: () => {
            menuExists = false;
        },
    };
}

test('project removal requires an open confirmation and prevents duplicate requests', () => {
    const { state, calls } = projectActions();
    state.deleteProject();
    assert.equal(calls.length, 0);
    state.confirmDelete({ id: 17, name: 'Project with a long name' });
    assert.equal(state.deleteDialogOpen.value, true);
    assert.equal(calls.length, 0, 'opening the dialog never deletes');
    state.deleteDialogOpen.value = false;
    state.deleteProject();
    assert.equal(calls.length, 0, 'a cancelled confirmation cannot submit');
    state.confirmDelete({ id: 17, name: 'Project with a long name' });
    state.deleteProject();
    state.deleteProject();
    state.confirmDelete({ id: 99, name: 'Different project' });
    assert.equal(calls.length, 1);
    assert.equal(calls[0].url, '/dashboard/projects/17');
    assert.equal(
        state.deleteTarget.value.id,
        17,
        'pending deletion cannot change target',
    );
    calls[0].options.onSuccess();
    calls[0].options.onFinish();
    assert.equal(state.deleteDialogOpen.value, false);
    assert.equal(state.rowProcessing.value, null);
});

for (const [page, method, path, prefix] of [
    ['Departments', 'deleteDept', 'departments', 'department'],
    ['Suppliers', 'remove', 'suppliers', 'supplier'],
]) {
    test(`${page}: cancellation, duplicate submission, server refusal and focus recovery`, () => {
        const { state, calls, focused, removeMenu } = projectActions(page);
        state[method]();
        state.confirmDelete({ id: 17, name: 'Record with a long name' });
        assert.equal(calls.length, 0, 'opening never sends deletion');
        state.deleteDialogOpen.value = false;
        state[method]();
        assert.equal(calls.length, 0, 'cancellation prevents submission');
        state.confirmDelete({ id: 17, name: 'Record' });
        state[method]();
        state[method]();
        state.confirmDelete({ id: 99, name: 'Other record' });
        assert.equal(calls.length, 1);
        assert.equal(calls[0].url, `/dashboard/${path}/17`);
        assert.equal(state.deleteTarget.value.id, 17);
        calls[0].options.onError({
            error: 'Cannot delete a record linked to requests',
        });
        calls[0].options.onFinish();
        assert.equal(state.deleteDialogOpen.value, true);
        assert.equal(
            state.deleteErrors.value.error,
            'Cannot delete a record linked to requests',
        );
        state[method]();
        assert.equal(calls.length, 2);
        state.deleteDialogOpen.value = false;
        calls[1].options.onError({ error: 'Still linked' });
        calls[1].options.onFinish();
        assert.equal(state.rowError.value, 'Still linked');
        state.confirmDelete({ id: 17, name: 'Record' });
        state[method]();
        calls[2].options.onSuccess();
        calls[2].options.onFinish();
        assert.equal(state.deleteDialogOpen.value, false);
        const event = { preventDefault() {} };
        state.restoreDeleteFocus(event);
        removeMenu();
        state.restoreDeleteFocus(event);
        assert.deepEqual(focused, [`${prefix}-menu-17`, `${prefix}-add`]);
    });
}

test('supplier search debounces typing, Enter cancels the timer, reset and unmount discard stale searches', (context) => {
    context.mock.timers.enable({ apis: ['setTimeout'] });
    const { state, calls, unmount } = projectActions('Suppliers');
    state.search.value = 'First';
    state.onSearchInput();
    context.mock.timers.tick(250);
    state.search.value = 'Latest';
    state.onSearchInput();
    context.mock.timers.tick(399);
    assert.equal(calls.length, 0);
    context.mock.timers.tick(1);
    assert.equal(calls.length, 1);
    assert.equal(calls[0].data.search, 'Latest');
    state.onSearchInput();
    state.applyFilters();
    context.mock.timers.tick(500);
    assert.equal(calls.length, 2, 'Enter must not duplicate a pending search');
    state.onSearchInput();
    state.resetSearch();
    context.mock.timers.tick(500);
    assert.equal(calls.length, 3);
    assert.equal(calls[2].data.search, undefined);
    state.onSearchInput();
    unmount();
    context.mock.timers.tick(500);
    assert.equal(calls.length, 3, 'leaving the page cancels its timer');
});

test('user filter reset sends one unfiltered request and cancels pending text search', async (context) => {
    context.mock.timers.enable({ apis: ['setTimeout'] });
    const { state, calls, unmount } = projectActions('Users', {
        search: 'Old',
        role: 'admin',
        active: '0',
    });
    state.onSearchInput();
    state.resetFilters();
    await Vue.nextTick();
    context.mock.timers.tick(500);
    assert.equal(calls.length, 1);
    assert.deepEqual(calls[0].data, {});
    assert.equal(state.search.value, '');
    assert.equal(state.roleFilter.value, 'all');
    assert.equal(state.activeFilter.value, 'all');
    state.search.value = 'No match';
    state.onSearchInput();
    state.resetFilters();
    context.mock.timers.tick(500);
    assert.equal(calls.length, 2);
    assert.deepEqual(calls[1].data, {});
    unmount();
});

test('server history restrictions stay visible in the dialog and allow retry', () => {
    const { state, calls } = projectActions();
    state.confirmDelete({ id: 17, name: 'Existing project' });
    state.deleteProject();
    const error = {
        error: 'Проект содержит историю заявок. Сделайте проект неактивным вместо удаления.',
    };
    calls[0].options.onError(error);
    calls[0].options.onFinish();
    assert.equal(state.deleteDialogOpen.value, true);
    assert.deepEqual(state.deleteErrors.value, error);
    assert.equal(state.rowProcessing.value, null);
    state.deleteProject();
    assert.equal(calls.length, 2);
    assert.deepEqual(state.deleteErrors.value, {});
});

test('closing a pending confirmation does not hide a server refusal', () => {
    const { state, calls } = projectActions();
    state.confirmDelete({ id: 17, name: 'Existing project' });
    state.deleteProject();
    state.deleteDialogOpen.value = false;
    calls[0].options.onError({ error: 'Cannot remove this project' });
    assert.equal(state.rowError.value, 'Cannot remove this project');
});

test('confirmation returns focus to the row or the create action if the row was removed', () => {
    const { state, focused, removeMenu } = projectActions();
    state.confirmDelete({ id: 17, name: 'Project' });
    let prevented = 0;
    const event = {
        preventDefault() {
            prevented++;
        },
    };
    state.restoreDeleteFocus(event);
    removeMenu();
    state.restoreDeleteFocus(event);
    assert.deepEqual(focused, ['project-menu-17', 'project-add']);
    assert.equal(prevented, 2);
});

test('directory counters use Russian singular, few and many forms', () => {
    for (const [count, expected] of [
        [0, '0 заявок'],
        [1, '1 заявка'],
        [2, '2 заявки'],
        [5, '5 заявок'],
        [11, '11 заявок'],
        [21, '21 заявка'],
        [24, '24 заявки'],
        [111, '111 заявок'],
    ]) {
        assert.equal(
            countModule.exports.formatCount(count, [
                'заявка',
                'заявки',
                'заявок',
            ]),
            expected,
        );
    }
    assert.equal(
        countModule.exports.formatCount(1, ['решение', 'решения', 'решений']),
        '1 решение',
    );
});
