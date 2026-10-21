import { closeSync, existsSync, openSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const envPath = join(root, '.env');
const sqlitePath = join(root, 'database', 'database.sqlite');

if (existsSync(envPath)) {
    console.error('.env already exists; keep it and configure it manually if needed.');
    process.exit(1);
}

let contents = readFileSync(join(root, '.env.example'), 'utf8');
const values = {
    APP_ENV: 'local',
    APP_DEBUG: 'true',
    APP_URL: 'http://127.0.0.1:8000',
    DB_CONNECTION: 'sqlite',
    DB_DATABASE: `"${sqlitePath.replaceAll('\\', '/')}"`,
    SESSION_DRIVER: 'file',
    CACHE_STORE: 'file',
};

for (const [key, value] of Object.entries(values)) {
    const pattern = new RegExp(`^${key}=.*$`, 'm');
    if (!pattern.test(contents)) {
        throw new Error(`${key} is missing from .env.example`);
    }
    contents = contents.replace(pattern, `${key}=${value}`);
}

const sqliteFile = openSync(sqlitePath, 'a');
closeSync(sqliteFile);
writeFileSync(envPath, contents, { flag: 'wx', mode: 0o600 });
console.log('Created .env for local SQLite. Run composer install, npm ci, and the Laravel migrations.');
