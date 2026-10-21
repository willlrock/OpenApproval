<?php

// Separate processes and real row locks; never run against an application database.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
// This is a standalone script, not an Artisan command. Fail the CI step when
// a race assertion throws, including in a child or during fixture cleanup.
set_exception_handler(function (Throwable $error): never {
    fwrite(STDERR, (string) $error.PHP_EOL);
    exit(1);
});
if (! $app->environment('testing') || config('database.default') !== 'pgsql'
    || config('database.connections.pgsql.database') !== 'teg_test') {
    throw new RuntimeException('This concurrency test requires APP_ENV=testing and the isolated teg_test PostgreSQL database.');
}

use App\Models\ApprovalRequest;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Services\AccountActivation;
use App\Services\ApprovalRequestCreation;
use App\Services\ApprovalRouteRecovery;
use App\Services\ApprovalWorkflow;
use App\Services\ManagementDocumentCreation;
use App\Services\RequestCreationNotifier;
use App\Services\SupplyWorkflow;
use App\Services\UserAdministration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;

function awaitMarker(string $path): void
{
    $deadline = microtime(true) + 15;
    while (! is_file($path)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Concurrent participant operation timed out: '.basename($path));
        }
        usleep(10000);
        clearstatcache();
    }
}

function performOperation(string $operation, array $f): void
{
    $actor = User::findOrFail($f['owner']);
    $admin = User::findOrFail($f['admin']);
    $route = ['revision' => $f['revision'], 'sequence' => $f['sequence']];
    switch ($operation) {
        case 'create':
            app(ApprovalRequestCreation::class)->create($actor, [
                'type' => 'approval', 'project_id' => $f['project'], 'currency' => 'USD', 'transaction_type' => 'bank',
                'supplier_name' => 'Concurrency supplier', 'approvers' => [$f['signer']],
                'items' => [['name' => 'Concurrency item', 'quantity' => 1, 'price_per_unit' => 100]], 'tranches' => [],
            ]);
            break;
        case 'create-document':
            app(ManagementDocumentCreation::class)->createFrozen($actor, [
                'title' => 'Concurrent order', 'project_id' => $f['project'], 'document_kind' => 'order',
                'effective_date_mode' => 'approval_date', 'approvers' => [$f['signer']],
            ], $f['source'], $f['preview'], $f['submission_key']);
            break;
        case 'disable':
        case 'disable-future':
        case 'disable-replacement':
            app(AccountActivation::class)->update($admin, $operation === 'disable' ? $f['signer'] : $f['replacement'], false);
            break;
        case 'remove-project':
            app(UserAdministration::class)->update($admin, $f['signer'], [], []);
            break;
        case 'toggle-project':
            app(UserAdministration::class)->toggleProject($admin, $f['signer'], $f['project']);
            break;
        case 'change-role':
            app(UserAdministration::class)->update($admin, $f['signer'],
                ['role_id' => Role::where('name', 'requester')->firstOrFail()->id]);
            break;
        case 'approve':
            app(ApprovalWorkflow::class)->approve($f['request'], User::findOrFail($f['signer']), 'Concurrent decision', $f['revision']);
            break;
        case 'save':
            app(ApprovalRouteRecovery::class)->save($f['request'], $actor, $route + ['approvers' => [$f['replacement']]]);
            break;
        case 'submit':
            app(ApprovalRouteRecovery::class)->submit($f['request'], $actor, $route);
            break;
        case 'recover':
            app(AccountActivation::class)->update($admin, $f['signer'], false);
            $request = ApprovalRequest::findOrFail($f['request']);
            app(ApprovalRouteRecovery::class)->save($request->id, $actor,
                ['revision' => 1, 'sequence' => $request->route_recovery_sequence, 'approvers' => [$f['replacement']]]);
            $request->refresh();
            app(ApprovalRouteRecovery::class)->submit($request->id, $actor,
                ['revision' => 1, 'sequence' => $request->route_recovery_sequence]);
            break;
        case 'notify':
            app(RequestCreationNotifier::class)->deliver($f['request']);
            break;
        case 'convert':
            $item = ApprovalRequest::findOrFail($f['request'])->items()->sole();
            app(SupplyWorkflow::class)->convert($f['request'], $actor, [
                'revision' => $f['revision'], 'currency' => 'USD', 'transaction_type' => 'bank',
                'supplier_name' => 'Concurrency supplier', 'approvers' => [$f['signer']],
                'items' => [['id' => $item->id, 'quantity' => 1, 'price_per_unit' => 100]], 'deadline_date' => '2026-12-01',
            ]);
            break;
        default:
            throw new RuntimeException('Unknown operation');
    }
}

if (($argv[1] ?? '') === 'child') {
    [$label, $operation, $directory, $gate] = array_slice($argv, 2);
    $fixture = json_decode(file_get_contents($directory.'/fixture.json'), true, flags: JSON_THROW_ON_ERROR);
    DB::connection()->beforeExecuting(function ($sql) use ($label, $directory) {
        if (str_contains($sql, '"roles"') && str_contains(strtolower($sql), 'for update')) {
            touch($directory.'/'.$label.'.attempt');
        }
    });
    $gated = false;
    DB::listen(function ($query) use ($label, $directory, $gate, &$gated) {
        if ($gate === '1' && ! $gated && str_contains($query->sql, '"roles"') && str_contains(strtolower($query->sql), 'for update')) {
            $gated = true;
            touch($directory.'/'.$label.'.locked');
            awaitMarker($directory.'/'.$label.'.resume');
        }
    });
    if ($operation === 'notify') {
        $bot = \Mockery::mock(\SergiX44\Nutgram\Nutgram::class);
        $bot->shouldReceive('sendMessage')->once()->andReturnUsing(function () use ($directory, $label) {
            touch($directory.'/'.$label.'.locked');
            awaitMarker($directory.'/'.$label.'.resume');
        });
        app()->instance(\SergiX44\Nutgram\Nutgram::class, $bot);
    }
    try {
        performOperation($operation, $fixture);
        $result = 'success';
    } catch (ValidationException|HttpException $e) {
        $result = 'denied';
    }
    file_put_contents($directory.'/'.$label.'.result', $result);
    exit;
}

function raceAssert(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

$directory = sys_get_temp_dir().'/teg-participant-race-'.bin2hex(random_bytes(8));
mkdir($directory, 0700);
$processes = [];
$users = [];
$projects = [];
$count = 0;
$fixtureFiles = [];
try {
    $scenarios = [
        ['create', 'disable'], ['disable', 'create'],
        ['create-document', 'disable'], ['disable', 'create-document'],
        ['create', 'remove-project'], ['remove-project', 'create'],
        ['create', 'toggle-project'], ['toggle-project', 'create'],
        ['create', 'change-role'], ['change-role', 'create'],
        ['approve', 'disable'], ['disable', 'approve'],
        ['approve', 'disable-future'], ['disable-future', 'approve'],
        ['disable', 'disable-future'],
        ['save', 'disable-replacement'], ['disable-replacement', 'save'],
        ['submit', 'disable-replacement'], ['disable-replacement', 'submit'],
        ['submit', 'submit'], ['disable', 'disable'],
        ['convert', 'disable'], ['disable', 'convert'],
        ['notify', 'recover'],
    ];
    foreach ($scenarios as [$first, $second]) {
        foreach (glob($directory.'/*') as $path) {
            unlink($path);
        }
        $caseUsers = [];
        foreach (['superadmin', in_array('convert', [$first, $second], true) ? 'supplier' : 'requester', 'approver', 'approver'] as $role) {
            $u = User::create(['full_name' => 'Concurrency '.$role, 'role_id' => Role::firstOrCreate(['name' => $role])->id, 'account_status' => 'approved']);
            $caseUsers[] = $u;
            $users[] = $u;
        }
        [$admin, $owner, $signer, $replacement] = $caseUsers;
        $project = Project::create(['name' => 'Concurrency '.bin2hex(random_bytes(5)), 'is_active' => true]);
        $projects[] = $project;
        foreach ($caseUsers as $u) {
            $u->projects()->attach($project);
        }
        $request = null;
        $creating = in_array('create', [$first, $second], true) || in_array('create-document', [$first, $second], true);
        if (! $creating) {
            $request = ApprovalRequest::create(['applicant_id' => $owner->id, 'project_id' => $project->id,
                'name' => 'Concurrent route', 'type' => in_array('convert', [$first, $second], true) ? 'supply' : 'approval',
                'status' => in_array('convert', [$first, $second], true) ? 'pending_supply' : 'pending_approval', 'expected_approval_level' => 1]);
            $request->approvals()->create(['approver_id' => $signer->id, 'level' => 1, 'status' => 'pending']);
            if (in_array('disable-future', [$first, $second], true)) {
                $request->approvals()->create(['approver_id' => $replacement->id, 'level' => 2, 'status' => 'pending']);
            }
            if (in_array('convert', [$first, $second], true)) {
                $request->items()->create(['name' => 'Concurrent supply item', 'quantity' => 1]);
            }
            if (in_array('save', [$first, $second], true) || in_array('submit', [$first, $second], true)) {
                app(AccountActivation::class)->update($admin, $signer->id, false);
                if (in_array('submit', [$first, $second], true)) {
                    $request->refresh();
                    app(ApprovalRouteRecovery::class)->save($request->id, $owner,
                        ['revision' => 1, 'sequence' => $request->route_recovery_sequence, 'approvers' => [$replacement->id]]);
                }
            }
        }
        if ($first === 'notify') {
            $signer->update(['telegram_id' => 800000000 + $signer->id]);
            $replacement->update(['telegram_id' => 800000000 + $replacement->id]);
            app(RequestCreationNotifier::class)->schedule($request);
        }
        $request?->refresh();
        $f = ['admin' => $admin->id, 'owner' => $owner->id, 'signer' => $signer->id, 'replacement' => $replacement->id,
            'project' => $project->id, 'request' => $request?->id, 'revision' => $request?->revision ?? 1,
            'sequence' => $request?->route_recovery_sequence ?? 0];
        if (in_array('create-document', [$first, $second], true)) {
            $stem = 'test-races/'.basename($directory).'/'.$project->id;
            foreach (['signature.png', 'source.docx', 'preview.pdf'] as $file) {
                $fixtureFiles[] = $stem.'/'.$file;
                Storage::disk('local')->put($stem.'/'.$file, 'Frozen fixture '.$file);
            }
            $owner->update(['signature_path' => $stem.'/signature.png']);
            $signer->update(['signature_path' => $stem.'/signature.png']);
            $f += ['source' => $stem.'/source.docx', 'preview' => $stem.'/preview.pdf', 'submission_key' => (string) Str::uuid()];
        }
        file_put_contents($directory.'/fixture.json', json_encode($f, JSON_THROW_ON_ERROR));
        $start = function ($label, $operation, $gate) use ($directory, &$processes) {
            $p = new Process([PHP_BINARY, __FILE__, 'child', $label, $operation, $directory, $gate ? '1' : '0'], base_path());
            $p->setTimeout(25);
            $p->start();
            $processes[] = $p;

            return $p;
        };
        $p1 = $start('first', $first, true);
        awaitMarker($directory.'/first.locked');
        $p2 = $start('second', $second, false);
        awaitMarker($directory.'/second.attempt');
        if ($first === 'notify') {
            // Recovery must commit while the old external send is still in flight.
            $p2->wait();
            raceAssert($p2->isSuccessful(), $p2->getErrorOutput().$p2->getOutput());
        } else {
            usleep(100000);
            raceAssert($p2->isRunning(), 'Second operation did not wait: '.$first.'/'.$second);
        }
        touch($directory.'/first.resume');
        foreach ([$p1, $p2] as $process) {
            $process->wait();
            raceAssert($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
        }
        $a = file_get_contents($directory.'/first.result');
        $b = file_get_contents($directory.'/second.result');
        raceAssert($a === 'success', 'First operation failed: '.$first.'/'.$second);
        $request = ApprovalRequest::where('project_id', $project->id)->first();
        if ($first === 'notify') {
            $notice = DB::table('request_creation_notifications')->where('approval_request_id', $request->id)->first();
            raceAssert($b === 'success' && $request->revision === 2 && (int) $notice->revision === 2
                && $notice->delivered_at === null && json_decode($notice->completed_steps, true) === [],
                'Old worker completed or checkpointed the new route notification.');
            $sent = 0;
            $bot = \Mockery::mock(\SergiX44\Nutgram\Nutgram::class);
            $bot->shouldReceive('sendMessage')->andReturnUsing(function ($text = null, $chat_id = null) use (&$sent, $replacement) {
                raceAssert((int) $chat_id === (int) $replacement->telegram_id, 'New notification reached the old approver.');
                $sent++;
            });
            app()->instance(\SergiX44\Nutgram\Nutgram::class, $bot);
            app(RequestCreationNotifier::class)->deliver($request->id);
            app(RequestCreationNotifier::class)->deliver($request->id);
            raceAssert($sent === 1 && DB::table('request_creation_notifications')->where('approval_request_id', $request->id)->value('delivered_at') !== null,
                'New route did not receive exactly one successful notification.');
        } elseif ($creating) {
            raceAssert(str_starts_with($first, 'create') ? $b === 'success' && $request?->status === 'approval_paused'
                : $b === 'denied' && ! $request, 'Creation/access race left a pending inaccessible route.');
        } elseif (in_array('disable-future', [$first, $second], true)) {
            raceAssert($request->status === 'approval_paused' && $request->revision === 1, 'Future access race did not pause the request.');
            if ($first === 'approve') {
                raceAssert($b === 'success' && $request->expected_approval_level === 2
                    && $request->approvals()->where('level', 1)->sole()->status === 'approved', 'Concurrent future revocation lost a completed decision.');
            } elseif ($second === 'approve') {
                raceAssert($b === 'denied' && $request->approvals()->where('level', 1)->sole()->status === 'pending', 'A decision changed a paused route.');
            } else {
                raceAssert($b === 'success' && count($request->route_unavailable) === 2
                    && DB::table('request_effects')->where('approval_request_id', $request->id)->count() === 2, 'Concurrent distinct revocations lost an unavailable participant.');
            }
        } elseif (in_array('approve', [$first, $second], true)) {
            raceAssert($first === 'approve' ? $b === 'success' && $request->status === 'approved'
                && $request->approvals()->sole()->comment === 'Concurrent decision'
                : $b === 'denied' && $request->status === 'approval_paused'
                && $request->approvals()->sole()->status === 'pending', 'Decision/access race lost a decision or allowed a revoked signer.');
        } elseif (in_array('save', [$first, $second], true)) {
            raceAssert($request->status === 'approval_paused' && $request->revision === 1
                && ($first === 'save' ? $b === 'success' && $request->route_draft !== null : $b === 'denied' && $request->route_draft === null),
                'Edit/access race allowed an unavailable replacement.');
            if ($request->route_draft) {
                try {
                    app(ApprovalRouteRecovery::class)->submit($request->id, $owner,
                        ['revision' => $request->revision, 'sequence' => $request->route_recovery_sequence]);
                    throw new RuntimeException('Unavailable saved replacement was submitted.');
                } catch (ValidationException) {
                    // Revalidation is required even for a previously saved valid draft.
                }
            }
        } elseif ($first === 'submit' && $second === 'submit') {
            raceAssert($b === 'denied' && $request->revision === 2 && $request->status === 'pending_approval'
                && DB::table('request_revisions')->where('approval_request_id', $request->id)->count() === 1
                && DB::table('request_effects')->where('approval_request_id', $request->id)->where('event', 'route-resubmitted')->count() === 1,
                'Concurrent submit created duplicate versions or effects.');
        } elseif (in_array('submit', [$first, $second], true)) {
            raceAssert($request->status === 'approval_paused' && ($first === 'submit' ? $b === 'success' && $request->revision === 2
                : $b === 'denied' && $request->revision === 1), 'Submit/access race left an inaccessible active route.');
        } elseif (in_array('convert', [$first, $second], true)) {
            raceAssert($first === 'convert' ? $b === 'success' && $request->revision === 2 && $request->status === 'approval_paused'
                : $b === 'denied' && $request->revision === 1 && $request->status === 'pending_supply', 'Conversion/access race lost the financial stage.');
        } else {
            raceAssert($b === 'success' && $request->status === 'approval_paused' && $request->revision === 1
                && DB::table('request_effects')->where('approval_request_id', $request->id)->count() === 1, 'Repeated revoke duplicated an event.');
        }
        $count++;
    }
    echo $count." PostgreSQL creation/document/decision/edit/submit/conversion/access/notification race scenarios passed\n";
} finally {
    foreach ($processes as $p) {
        if ($p->isRunning()) {
            $p->stop();
        }
    }
    foreach ($projects as $project) {
        foreach (ApprovalRequest::where('project_id', $project->id)->where('type', 'management_document')->get() as $document) {
            Storage::disk('local')->deleteDirectory('management_documents/signatures/request_'.$document->id);
        }
        DB::table('approval_requests')->where('project_id', $project->id)->delete();
    }
    foreach ($users as $user) {
        DB::table('users')->where('id', $user->id)->delete();
    }
    foreach ($projects as $project) {
        DB::table('projects')->where('id', $project->id)->delete();
    }
    Storage::disk('local')->delete($fixtureFiles);
    foreach (glob($directory.'/*') as $path) {
        unlink($path);
    }
    rmdir($directory);
}
