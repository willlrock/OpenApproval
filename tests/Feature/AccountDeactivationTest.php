<?php

use App\Models\ApprovalRequest;
use App\Models\User;
use App\Services\AccountActivation;
use App\Services\ApprovalParticipantPolicy;
use App\Services\ApprovalWorkflow;
use App\Services\DirectoryDeletion;
use App\Telegram\Middleware\EnsureActiveAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use SergiX44\Nutgram\Nutgram;

beforeEach(function () {
    seedRoles();
    $this->withoutVite();
    $this->admin = User::factory()->role('superadmin')->create();
    $this->employee = User::factory()->role('approver')->create(['telegram_id' => 87654]);
});

test('deactivation preserves requests decisions attachments payments and memberships', function () {
    Storage::fake('local');
    Storage::disk('local')->put('history/document.pdf', 'document');
    $project = makeProject();
    $this->employee->projects()->attach($project);
    $request = ApprovalRequest::create(['applicant_id' => $this->employee->id, 'project_id' => $project->id,
        'type' => 'approval', 'name' => 'History', 'status' => 'approved']);
    $otherRequest = ApprovalRequest::create(['applicant_id' => $this->admin->id, 'supply_applicant_id' => $this->employee->id,
        'project_id' => $project->id, 'type' => 'supply', 'name' => 'Other history', 'status' => 'approved']);
    $item = $request->items()->create(['name' => 'Item', 'quantity' => 1]);
    $attachment = $request->attachments()->create(['file_type' => 'document', 'file_path' => 'history/document.pdf']);
    $tranche = $request->tranches()->create(['amount' => 100, 'percent' => 100, 'status' => 'paid']);
    $approval = $otherRequest->approvals()->create(['approver_id' => $this->employee->id,
        'level' => 1, 'status' => 'approved', 'action_date' => now(), 'signature_snapshot_path' => 'history/signature.png']);
    $archived = ApprovalRequest::create(['applicant_id' => $this->employee->id, 'project_id' => $project->id,
        'type' => 'approval', 'name' => 'Archived', 'status' => 'draft']);
    $archived->delete();

    $this->actingAs($this->admin)->patch(route('users.activation', $this->employee), ['is_active' => false])
        ->assertSessionHasNoErrors()->assertRedirect();

    expect($this->employee->fresh()->is_active)->toBeFalse()
        ->and($this->employee->fresh()->account_status)->toBe('approved')
        ->and($this->employee->fresh()->projects()->count())->toBe(1)
        ->and($request->fresh()->applicant_id)->toBe($this->employee->id)
        ->and($otherRequest->fresh()->supply_applicant_id)->toBe($this->employee->id)
        ->and($approval->fresh()->status)->toBe('approved')
        ->and($approval->fresh()->signature_snapshot_path)->toBe('history/signature.png')
        ->and($item->fresh())->not->toBeNull()
        ->and($attachment->fresh())->not->toBeNull()
        ->and($tranche->fresh()->status)->toBe('paid')
        ->and(ApprovalRequest::withTrashed()->find($archived->id)->trashed())->toBeTrue();
    Storage::disk('local')->assertExists('history/document.pdf');
});

test('reactivation restores access without changing role or registration status', function () {
    $this->employee->update(['is_active' => false]);
    $roleId = $this->employee->role_id;
    $this->actingAs($this->admin)->patch(route('users.activation', $this->employee), ['is_active' => true])
        ->assertSessionHasNoErrors();
    expect($this->employee->fresh()->isApproved())->toBeTrue()->and($this->employee->fresh()->role_id)->toBe($roleId);
    $this->actingAs($this->employee->fresh())->get(route('dashboard'))->assertOk();
});

test('activation never approves a pending registration', function () {
    $this->employee->update(['is_active' => false, 'account_status' => 'pending']);
    app(AccountActivation::class)->update($this->admin, $this->employee->id, true);
    expect($this->employee->fresh()->account_status)->toBe('pending')->and($this->employee->fresh()->isApproved())->toBeFalse();
});

test('inactive accounts cannot log in even with the correct password', function () {
    $this->employee->update(['is_active' => false]);
    $this->post(route('login.store'), ['phone' => $this->employee->phone, 'password' => 'password'])
        ->assertSessionHasErrors('phone');
    $this->assertGuest();
});

test('reactivated users can authenticate again with their existing password', function () {
    $this->employee->update(['is_active' => false]);
    app(AccountActivation::class)->update($this->admin, $this->employee->id, true);
    $this->post(route('login.store'), ['phone' => $this->employee->phone, 'password' => 'password'])
        ->assertSessionHasNoErrors()->assertRedirect();
    $this->assertAuthenticatedAs($this->employee);
});

test('deactivated recipients are omitted from new broadcasts', function () {
    $this->admin->update(['telegram_id' => 88665]);
    $this->employee->update(['is_active' => false]);
    $draft = app(\App\Services\TelegramBroadcastService::class)->createDraft($this->admin, 'Update');
    expect($draft->recipients)->toBe([$this->admin->telegram_id]);
});

test('deactivated sessions are logged out before accessing web routes', function (string $route) {
    $this->employee->update(['is_active' => false]);
    $this->actingAs($this->employee)->get(route($route))->assertForbidden();
    $this->assertGuest();
})->with(['dashboard', 'profile.edit', 'user-password.edit']);

test('deactivation invalidates remembered access and database sessions', function () {
    config(['session.driver' => 'database']);
    $token = $this->employee->remember_token;
    DB::table('sessions')->insert(['id' => 'employee-session', 'user_id' => $this->employee->id,
        'payload' => '', 'last_activity' => time()]);
    DB::table('sessions')->insert(['id' => 'admin-session', 'user_id' => $this->admin->id,
        'payload' => '', 'last_activity' => time()]);
    app(AccountActivation::class)->update($this->admin, $this->employee->id, false);
    expect($this->employee->fresh()->remember_token)->not->toBe($token);
    $this->assertDatabaseMissing('sessions', ['id' => 'employee-session']);
    $this->assertDatabaseHas('sessions', ['id' => 'admin-session']);
});

test('a two factor challenge opened before deactivation cannot complete', function () {
    $this->employee->update(['is_active' => false]);
    $this->withSession(['login.id' => $this->employee->id, 'login.remember' => true])
        ->get(route('two-factor.login'))->assertForbidden()->assertSessionMissing('login.id');
    $this->assertGuest();
});

test('ordinary users cannot deactivate another account', function () {
    $this->actingAs($this->employee)->patch(route('users.activation', $this->admin), ['is_active' => false])->assertForbidden();
    expect($this->admin->fresh()->is_active)->toBeTrue();
});

test('administrators cannot deactivate themselves or manage other administrators', function () {
    $this->actingAs($this->admin)->patch(route('users.activation', $this->admin), ['is_active' => false])
        ->assertSessionHasErrors('is_active');
    $ordinaryAdmin = User::factory()->role('admin')->create();
    $this->actingAs($ordinaryAdmin)->patch(route('users.activation', $this->admin), ['is_active' => false])->assertForbidden();
    expect($this->admin->fresh()->is_active)->toBeTrue();
});

test('an inactive superadmin cannot be used as the last administrators replacement', function () {
    User::factory()->role('superadmin')->create(['is_active' => false]);
    $this->actingAs($this->admin)->put(route('users.update', $this->admin), ['role' => 'requester'])
        ->assertSessionHasErrors('role');
    expect($this->admin->fresh()->role)->toBe('superadmin');
});

test('deactivation revokes Telegram link codes even after reactivation', function () {
    $this->employee->update(['telegram_id' => null]);
    $links = app(\App\Services\TelegramAccountLink::class);
    $token = $links->issue($this->employee);
    app(AccountActivation::class)->update($this->admin, $this->employee->id, false);
    app(AccountActivation::class)->update($this->admin, $this->employee->id, true);
    expect($this->employee->fresh()->telegram_link_token_hash)->toBeNull();
    expect(fn () => $links->consume($token, 987654, 'old-code'))->toThrow(ValidationException::class);
});

test('Telegram linking cannot delete a registration placeholder with request history', function () {
    $target = User::factory()->create(['telegram_id' => null]);
    $placeholder = User::create(['telegram_id' => 987654, 'account_status' => 'new',
        'role_id' => \App\Models\Role::where('name', 'requester')->value('id')]);
    $request = ApprovalRequest::create(['applicant_id' => $placeholder->id, 'project_id' => makeProject()->id,
        'type' => 'approval', 'name' => 'Archived history', 'status' => 'draft']);
    $request->delete();
    $links = app(\App\Services\TelegramAccountLink::class);
    $token = $links->issue($target);
    expect(fn () => $links->consume($token, 987654, 'linked'))->toThrow(ValidationException::class);
    expect($placeholder->fresh()->telegram_id)->toBe(987654)
        ->and($target->fresh()->telegram_id)->toBeNull()
        ->and(ApprovalRequest::withTrashed()->find($request->id))->not->toBeNull();
});

test('legacy delete URLs and internal deletion cannot erase users', function () {
    $this->actingAs($this->admin)->delete('/dashboard/users/'.$this->employee->id)->assertStatus(405);
    expect(fn () => app(DirectoryDeletion::class)->user($this->employee->id))->toThrow(ValidationException::class);
    expect($this->employee->fresh())->not->toBeNull();
});

test('deactivated signers are excluded and cannot act on existing approvals', function () {
    $project = makeProject();
    $this->employee->projects()->attach($project);
    $request = ApprovalRequest::create(['applicant_id' => $this->admin->id, 'project_id' => $project->id,
        'type' => 'approval', 'name' => 'Pending', 'status' => 'pending_approval', 'expected_approval_level' => 1]);
    $approval = $request->approvals()->create(['approver_id' => $this->employee->id, 'level' => 1, 'status' => 'pending']);
    $this->employee->update(['is_active' => false]);
    expect(app(ApprovalParticipantPolicy::class)->eligible($project->id)->whereKey($this->employee->id)->exists())->toBeFalse();
    expect(fn () => app(ApprovalWorkflow::class)->approve($request->id, $this->employee, null))->toThrow(ValidationException::class);
    expect($approval->fresh()->status)->toBe('pending');
});

test('the user list exposes inactive status and supports filtering', function () {
    $this->employee->update(['is_active' => false]);
    $this->actingAs($this->admin)->get(route('users', ['active' => '0']))
        ->assertInertia(fn (Assert $page) => $page->component('Dashboard/Users')
            ->has('users.data', 1)->where('users.data.0.id', $this->employee->id)
            ->where('users.data.0.is_active', false)->where('users.data.0.can_manage_activation', true));
});

test('Telegram middleware blocks messages and old callback buttons for inactive users', function (bool $callback) {
    $this->employee->update(['is_active' => false]);
    $bot = Mockery::mock(Nutgram::class);
    $bot->shouldReceive('userId')->andReturn($this->employee->telegram_id);
    $bot->shouldReceive('isCallbackQuery')->andReturn($callback);
    $bot->shouldReceive($callback ? 'answerCallbackQuery' : 'sendMessage')->once()->andReturnNull();
    $called = false;
    (new EnsureActiveAccount)($bot, function () use (&$called) {
        $called = true;
    });
    expect($called)->toBeFalse();
})->with([false, true]);

test('Telegram middleware allows active users and new registrations', function (bool $registered) {
    $bot = Mockery::mock(Nutgram::class);
    $bot->shouldReceive('userId')->andReturn($registered ? $this->employee->telegram_id : 98765);
    $called = false;
    (new EnsureActiveAccount)($bot, function () use (&$called) {
        $called = true;
    });
    expect($called)->toBeTrue();
})->with([false, true]);
