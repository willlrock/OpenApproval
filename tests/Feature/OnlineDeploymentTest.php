<?php

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

it('does not change the migration repository when no migrations are pending', function () {
    $before = DB::table('migrations')->count();
    $this->artisan('deployment:migrate', ['--check' => true])->assertSuccessful();
    $this->artisan('deployment:migrate')->assertSuccessful();
    expect(DB::table('migrations')->count())->toBe($before);
});

it('rejects an unapproved pending migration before executing it', function () {
    $migrator = Mockery::mock(Migrator::class);
    $migrator->shouldReceive('repositoryExists')->once()->andReturnTrue();
    $migrator->shouldReceive('getMigrationFiles')->once()->andReturn(['unreviewed' => __FILE__]);
    $migrator->shouldReceive('getRepository->getRan')->once()->andReturn([]);
    $migrator->shouldNotReceive('run');
    app()->instance('migrator', $migrator);
    $this->artisan('deployment:migrate')->expectsOutputToContain('requires compatibility review')->assertFailed();
});

it('does not create a migration repository during an online deployment', function () {
    $migrator = Mockery::mock(Migrator::class);
    $migrator->shouldReceive('repositoryExists')->once()->andReturnFalse();
    $migrator->shouldNotReceive('run');
    app()->instance('migrator', $migrator);
    $this->artisan('deployment:migrate')->expectsOutputToContain('explicit maintenance')->assertFailed();
});

it('executes a reviewed PostgreSQL migration and restores session timeouts', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL session timeout verification.');
    }
    $directory = sys_get_temp_dir().'/teg-online-migration-'.bin2hex(random_bytes(8));
    File::ensureDirectoryExists($directory.'/database/migrations');
    File::ensureDirectoryExists($directory.'/ops');
    $name = '2099_01_01_000001_online_deploy_probe';
    $migration = $directory.'/database/migrations/'.$name.'.php';
    file_put_contents($migration, <<<'PHP'
<?php
return new class extends \Illuminate\Database\Migrations\Migration {
    public function up(): void {
        \Illuminate\Support\Facades\Schema::create('online_deploy_probe', function ($table) { $table->id(); });
    }
};
PHP);
    file_put_contents($directory.'/ops/online-migrations.json', json_encode([$name => hash_file('sha256', $migration)]));
    $base = app()->basePath();
    $database = database_path();
    $lockTimeout = DB::selectOne('SHOW lock_timeout')->lock_timeout;
    $statementTimeout = DB::selectOne('SHOW statement_timeout')->statement_timeout;
    try {
        app()->setBasePath($directory)->useDatabasePath($directory.'/database');
        $this->artisan('deployment:migrate', ['--check' => true])->assertSuccessful();
        expect(Schema::hasTable('online_deploy_probe'))->toBeFalse();
        $this->artisan('deployment:migrate')->assertSuccessful();
        expect(Schema::hasTable('online_deploy_probe'))->toBeTrue()
            ->and(DB::table('migrations')->where('migration', $name)->exists())->toBeTrue()
            ->and(DB::selectOne('SHOW lock_timeout')->lock_timeout)->toBe($lockTimeout)
            ->and(DB::selectOne('SHOW statement_timeout')->statement_timeout)->toBe($statementTimeout);
    } finally {
        app()->setBasePath($base)->useDatabasePath($database);
        Schema::dropIfExists('online_deploy_probe');
        DB::table('migrations')->where('migration', $name)->delete();
        File::deleteDirectory($directory);
    }
});
