<?php

use App\Models\ApprovalRequest;
use App\Models\User;
use App\Services\ApprovalParticipantPolicy;
use App\Services\UserAdministration;
use App\Telegram\Conversations\AdminPanel;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Inline\CallbackQuery;

beforeEach(function () {
    seedRoles();
    $this->withoutVite();
    $this->admin = User::factory()->role('superadmin')->create(['telegram_id' => 991100]);
    $this->author = User::factory()->create();
    $this->signer = User::factory()->role('approver')->create();
    $this->project = makeProject('Pending approval project');
    $this->otherProject = makeProject('Other project');
    $this->author->projects()->attach($this->project);
    $this->signer->projects()->attach([$this->project->id, $this->otherProject->id]);
});

function continuityRequest(int $level = 1, array $attributes = []): ApprovalRequest
{
    $request = ApprovalRequest::create($attributes + [
        'applicant_id' => test()->author->id, 'project_id' => test()->project->id,
        'type' => 'approval', 'name' => 'Pending approval', 'status' => 'pending_approval',
        'expected_approval_level' => 1,
    ]);
    if ($level > 1) {
        $current = User::factory()->role('approver')->create();
        $current->projects()->attach(test()->project);
        $request->approvals()->create(['approver_id' => $current->id, 'level' => 1, 'status' => 'pending']);
    }
    $request->approvals()->create(['approver_id' => test()->signer->id, 'level' => $level, 'status' => 'pending']);

    return $request;
}

test('web access changes pause current or future approvals for explicit recovery', function (string $operation, int $level) {
    $request = continuityRequest($level);
    $data = $operation === 'role' ? ['role' => 'requester'] : ['project_ids' => []];
    $this->actingAs($this->admin)->putJson(route('users.update', $this->signer), $data)
        ->assertRedirect()->assertSessionHasNoErrors();

    expect($request->fresh()->status)->toBe('approval_paused')
        ->and($request->fresh()->revision)->toBe(1)
        ->and($request->approvals()->count())->toBe($level);
    expect(app(ApprovalParticipantPolicy::class)->canDecide($request, $this->signer))->toBeFalse();
    $this->assertDatabaseHas('request_effects', [
        'approval_request_id' => $request->id, 'event' => 'route-paused:'.$this->signer->id,
    ]);
})->with([['role', 1], ['role', 2], ['projects', 1], ['projects', 2]]);

test('project toggles still allow adding projects and removing unrelated ones', function () {
    $request = continuityRequest();
    $administration = app(UserAdministration::class);
    $administration->toggleProject($this->admin, $this->signer->id, $this->otherProject->id);
    expect($this->signer->projects()->whereKey($this->otherProject->id)->exists())->toBeFalse();
    $administration->toggleProject($this->admin, $this->signer->id, $this->otherProject->id);
    expect($this->signer->projects()->whereKey($this->otherProject->id)->exists())->toBeTrue()
        ->and($request->fresh()->status)->toBe('pending_approval');
    expect(app(ApprovalParticipantPolicy::class)->canDecide($request, $this->signer))->toBeTrue();
});

test('Telegram project removal pauses the route through the shared recovery service', function (int $level) {
    $request = continuityRequest($level);
    $callback = new CallbackQuery;
    $callback->data = 'up_toggle:'.$this->project->id;
    $bot = Mockery::mock(Nutgram::class);
    $bot->shouldReceive('userId')->andReturn($this->admin->telegram_id);
    $bot->shouldReceive('isCallbackQuery')->andReturnTrue();
    $bot->shouldReceive('callbackQuery')->andReturn($callback);
    $bot->shouldReceive('answerCallbackQuery')->once()->andReturnNull();
    $conversation = new ContinuityAdminPanelProbe;
    $conversation->editingUserId = $this->signer->id;
    $conversation->handleUserProjectToggle($bot);

    expect($this->signer->projects()->whereKey($this->project->id)->exists())->toBeFalse()
        ->and($request->fresh()->status)->toBe('approval_paused')
        ->and($request->fresh()->route_unavailable[0]['id'])->toBe($this->signer->id);
})->with([1, 2]);

test('management document approvals pause when their participant loses the project assignment', function () {
    $this->signer->update(['signature_path' => 'signatures/participant.png']);
    $request = continuityRequest(attributes: ['type' => 'management_document']);
    app(UserAdministration::class)->toggleProject($this->admin, $this->signer->id, $this->project->id);

    expect($request->fresh()->status)->toBe('approval_paused');
});

class ContinuityAdminPanelProbe extends AdminPanel
{
    protected function manageUserProjects(Nutgram $bot) {}
}
