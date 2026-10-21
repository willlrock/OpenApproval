<?php

use App\Models\ApprovalRequest;
use App\Services\ApprovalRouteRecovery;
use App\Services\ApprovalWorkflow;
use App\Services\RequestEffects;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    seedRoles();
    $this->withoutVite();
    Storage::fake('local');
    $this->admin = makeUserWithRole('superadmin');
    $this->owner = makeUserWithRole('requester');
    $this->first = makeUserWithRole('approver');
    $this->future = makeUserWithRole('approver');
    $this->project = makeProject('Dependency changes');
    foreach ([$this->owner, $this->first, $this->future] as $user) {
        $user->projects()->attach($this->project);
    }
    $this->request = ApprovalRequest::create([
        'applicant_id' => $this->owner->id, 'project_id' => $this->project->id,
        'type' => 'approval', 'name' => 'Preserved request', 'status' => 'pending_approval',
        'expected_approval_level' => 1, 'currency' => 'USD', 'total_price' => 100,
    ]);
    foreach ([$this->first, $this->future] as $index => $user) {
        $this->request->approvals()->create(['approver_id' => $user->id, 'level' => $index + 1, 'status' => 'pending']);
        Storage::disk('local')->put('signatures/'.$user->id.'.png', 'Signature');
        $user->update(['signature_path' => 'signatures/'.$user->id.'.png']);
    }
});

test('removing a required current or future signature pauses the document and permits explicit recovery', function (int $level) {
    $signer = $level === 1 ? $this->first : $this->future;
    $this->request->update(['type' => 'management_document']);
    $this->actingAs($this->admin)->delete(route('users.signature.delete', $signer))->assertRedirect()->assertSessionHasNoErrors();

    $request = $this->request->fresh();
    expect($request->status)->toBe('approval_paused')->and($request->revision)->toBe(1)
        ->and($request->route_unavailable[0]['id'])->toBe($signer->id)
        ->and($request->approvals()->count())->toBe(2)
        ->and(app(ApprovalRouteRecovery::class)->canRecover($request, $this->owner))->toBeTrue();
    expect(fn () => app(ApprovalWorkflow::class)->approve($request->id, $this->first, null))->toThrow(ValidationException::class);
    $this->assertDatabaseHas('request_effects', ['approval_request_id' => $request->id, 'event' => 'route-paused:'.$signer->id]);

    $this->delete(route('users.signature.delete', $signer))->assertRedirect();
    expect($request->fresh()->route_recovery_sequence)->toBe(1);
    // Restoring the dependency never silently resumes a paused document.
    $signer->update(['signature_path' => 'signatures/restored.png']);
    expect($request->fresh()->status)->toBe('approval_paused');
    $replacement = $level === 1 ? $this->future : $this->first;
    $recovery = app(ApprovalRouteRecovery::class);
    $recovery->save($request->id, $this->owner, ['revision' => 1, 'sequence' => 1, 'approvers' => [$replacement->id]]);
    $recovery->submit($request->id, $this->owner, ['revision' => 1, 'sequence' => $request->fresh()->route_recovery_sequence]);
    expect($request->fresh()->status)->toBe('pending_approval')->and($request->fresh()->revision)->toBe(2)
        ->and($request->approvals()->sole()->approver_id)->toBe($replacement->id);
})->with([1, 2]);

test('signature removal leaves optional signatures and completed decisions unchanged', function (string $kind) {
    if ($kind !== 'optional') {
        $this->request->update(['type' => 'management_document', 'expected_approval_level' => 2]);
        $this->request->approvals()->where('level', 1)->update(['status' => 'approved', 'comment' => 'Preserve decision',
            'signature_snapshot_path' => 'history/signed.png']);
        Storage::disk('local')->put('history/signed.png', 'Historical signature');
    }
    if ($kind === 'finished') {
        $this->request->update(['status' => 'approved']);
    }
    $this->actingAs($this->admin)->delete(route('users.signature.delete', $this->first))->assertRedirect();
    expect($this->request->fresh()->status)->toBe($kind === 'finished' ? 'approved' : 'pending_approval')
        ->and(DB::table('request_effects')->count())->toBe(0);
    if ($kind !== 'optional') {
        Storage::disk('local')->assertExists('history/signed.png');
        expect($this->request->approvals()->where('level', 1)->value('comment'))->toBe('Preserve decision');
    }
})->with(['optional', 'completed', 'finished']);

test('project deactivation pauses only its pending routes and reactivation requires explicit resubmission', function () {
    $other = makeProject('Unaffected project');
    $unaffected = ApprovalRequest::create(['applicant_id' => $this->owner->id, 'project_id' => $other->id,
        'name' => 'Other request', 'type' => 'approval', 'status' => 'pending_approval']);
    $finished = ApprovalRequest::create(['applicant_id' => $this->owner->id, 'project_id' => $this->project->id,
        'name' => 'Paid request', 'type' => 'approval', 'status' => 'paid', 'total_price' => 100]);
    $tranche = $finished->tranches()->create(['amount' => 100, 'status' => 'paid', 'payment_proof' => 'Retain evidence']);
    $before = $this->request->approvals()->orderBy('level')->get()->toArray();
    $this->actingAs($this->admin)->put(route('projects.update', $this->project), [
        'name' => $this->project->name, 'is_active' => false,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $request = $this->request->fresh();
    expect($request->status)->toBe('approval_paused')->and($request->revision)->toBe(1)
        ->and(collect($request->route_unavailable)->pluck('id')->all())->toBe([$this->first->id, $this->future->id])
        ->and($request->approvals()->orderBy('level')->get()->toArray())->toBe($before)
        ->and($unaffected->fresh()->status)->toBe('pending_approval')
        ->and($finished->fresh()->status)->toBe('paid')->and($tranche->fresh()->payment_proof)->toBe('Retain evidence');
    expect(fn () => app(ApprovalRouteRecovery::class)->save($request->id, $this->admin, [
        'revision' => 1, 'sequence' => $request->route_recovery_sequence, 'approvers' => [$this->first->id],
    ]))->toThrow(ValidationException::class);

    $this->put(route('projects.update', $this->project), ['name' => $this->project->name, 'is_active' => false])->assertRedirect();
    expect($request->fresh()->route_recovery_sequence)->toBe($request->route_recovery_sequence);
    $this->put(route('projects.update', $this->project), ['name' => $this->project->name, 'is_active' => true])->assertRedirect();
    expect($request->fresh()->status)->toBe('approval_paused');
    $recovery = app(ApprovalRouteRecovery::class);
    $recovery->save($request->id, $this->owner, ['revision' => 1, 'sequence' => $request->route_recovery_sequence, 'approvers' => [$this->first->id]]);
    $recovery->submit($request->id, $this->owner, ['revision' => 1, 'sequence' => $request->fresh()->route_recovery_sequence]);
    expect($request->fresh()->status)->toBe('pending_approval')->and($request->fresh()->revision)->toBe(2)
        ->and(DB::table('request_revisions')->where('approval_request_id', $request->id)->count())->toBe(1);
});

test('dependency changes roll back when their pause cannot be persisted', function (string $change) {
    $this->mock(RequestEffects::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('Persist failed'));
    $this->withoutExceptionHandling();
    expect(function () use ($change) {
        $this->actingAs($this->admin);
        if ($change === 'signature') {
            $this->request->update(['type' => 'management_document']);
            $this->delete(route('users.signature.delete', $this->first));
        } else {
            $this->put(route('projects.update', $this->project), ['name' => $this->project->name, 'is_active' => false]);
        }
    })->toThrow(RuntimeException::class, 'Persist failed');
    expect((bool) $this->project->fresh()->is_active)->toBeTrue()
        ->and($this->first->fresh()->signature_path)->toBe('signatures/'.$this->first->id.'.png')
        ->and($this->request->fresh()->status)->toBe('pending_approval');
    Storage::disk('local')->assertExists('signatures/'.$this->first->id.'.png');
})->with(['signature', 'project']);

test('deactivating an already paused project notifies administrators when its owner can no longer recover', function () {
    foreach ([$this->first, $this->future] as $user) {
        app(App\Services\AccountActivation::class)->update($this->admin, $user->id, false);
    }
    $spy = fakeBot();
    foreach (DB::table('request_effects')->pluck('id') as $id) {
        app(RequestEffects::class)->deliver($id);
    }
    expect($spy->messagesTo($this->owner->telegram_id))->not->toBeEmpty();
    $this->actingAs($this->admin)->put(route('projects.update', $this->project), [
        'name' => $this->project->name, 'is_active' => false,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $effect = DB::table('request_effects')->where('event', 'route-owner-unavailable:'.$this->owner->id)->sole();
    app(RequestEffects::class)->deliver($effect->id);
    expect($spy->messagesTo($this->admin->telegram_id))->toHaveCount(1)
        ->and($this->request->fresh()->status)->toBe('approval_paused');
});
