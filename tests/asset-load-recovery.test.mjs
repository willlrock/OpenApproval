import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import ts from 'typescript';

const code = ts.transpileModule(
    readFileSync(
        new URL('../resources/js/lib/assetLoadRecovery.ts', import.meta.url),
        'utf8',
    ),
    {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ES2022,
        },
    },
).outputText;

function fixture(pathname = '/requests') {
    const browser = new EventTarget();
    const nodes = [];
    const mounts = [];
    let reloads = 0;
    browser.location = { pathname, reload: () => reloads++ };
    browser.document = {
        createElement: () => ({
            remove() {
                nodes.splice(nodes.indexOf(this), 1);
            },
        }),
        body: { appendChild: (node) => nodes.push(node) },
    };
    const module = { exports: {} };
    new Function('require', 'module', 'exports', code)(
        (name) =>
            name === 'vue'
                ? {
                      createApp: (_, props) => ({
                          mount: (host) => mounts.push({ host, ...props }),
                          unmount() {},
                      }),
                  }
                : {},
        module,
        module.exports,
    );
    const cleanup = module.exports.installAssetLoadRecovery(browser);
    return { browser, nodes, mounts, cleanup, reloads: () => reloads };
}

test('failed imports leave the page mounted and preserve reporting without automatic reloads', () => {
    const f = fixture();
    const event = new Event('vite:preloadError', { cancelable: true });
    f.browser.dispatchEvent(event);
    f.browser.dispatchEvent(new Event('vite:preloadError'));
    assert.equal(f.nodes.length, 1);
    assert.equal(f.mounts.length, 1);
    assert.equal(f.reloads(), 0);
    assert.equal(event.defaultPrevented, false);
    f.mounts[0].onReload();
    assert.equal(f.reloads(), 1);
    f.cleanup();
    assert.equal(f.nodes.length, 0);
});

test('dismissal preserves the current page and permits recovery after another failed navigation', () => {
    const f = fixture();
    f.browser.dispatchEvent(new Event('vite:preloadError'));
    f.mounts[0].onDismiss();
    assert.equal(f.nodes.length, 0);
    assert.equal(f.reloads(), 0);
    f.browser.dispatchEvent(new Event('vite:preloadError'));
    assert.equal(f.nodes.length, 1);
    f.cleanup();
    f.browser.dispatchEvent(new Event('vite:preloadError'));
    assert.equal(f.nodes.length, 0);
});
