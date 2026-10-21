<?php

use App\Models\ApprovalRequest;
use Illuminate\Support\Facades\DB;

it('preserves existing data and legacy writes across the two production upgrade migrations', function () {
    seedRoles();
    $owner = makeUserWithRole('requester');
    $project = makeProject('Upgrade compatibility');
    $request = ApprovalRequest::create([
        'applicant_id' => $owner->id, 'project_id' => $project->id,
        'type' => 'approval', 'name' => 'Existing request', 'status' => 'pending_approval',
        'currency' => 'USD', 'total_price' => 100, 'revision' => 4,
    ]);
    $recovery = require database_path('migrations/2026_10_14_000001_add_approval_route_recovery.php');
    $notifications = require database_path('migrations/2026_10_15_000001_version_request_creation_notifications.php');
    // Reconstruct the schema used by the live release in this isolated test DB.
    $notifications->down();
    $recovery->down();
    DB::table('request_creation_notifications')->insert([
        'approval_request_id' => $request->id, 'created_at' => now(),
        'completed_steps' => '["applicant"]', 'delivered_at' => now(),
    ]);
    $recovery->up();
    $notifications->up();

    $upgraded = DB::table('approval_requests')->find($request->id);
    $notice = DB::table('request_creation_notifications')->where('approval_request_id', $request->id)->first();
    expect($upgraded->name)->toBe('Existing request')
        ->and((int) $upgraded->revision)->toBe(4)
        ->and((int) $upgraded->route_recovery_sequence)->toBe(0)
        ->and($upgraded->route_unavailable)->toBeNull()
        ->and($upgraded->route_draft)->toBeNull()
        ->and($upgraded->approval_paused_at)->toBeNull()
        ->and((int) $notice->revision)->toBe(4)
        ->and(json_decode($notice->completed_steps, true))->toBe(['applicant'])
        ->and($notice->delivered_at)->not->toBeNull();

    // Old workers omit the new fields while finishing jobs during activation.
    DB::table('approval_requests')->where('id', $request->id)->update(['name' => 'Legacy update']);
    DB::table('request_creation_notifications')->where('approval_request_id', $request->id)->delete();
    DB::table('request_creation_notifications')->insert(['approval_request_id' => $request->id, 'created_at' => now()]);
    expect(DB::table('approval_requests')->find($request->id)->name)->toBe('Legacy update')
        ->and((int) DB::table('request_creation_notifications')->where('approval_request_id', $request->id)->value('revision'))->toBe(1);
});
