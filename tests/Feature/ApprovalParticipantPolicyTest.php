<?php

use App\Models\ApprovalRequest;
use App\Services\ApprovalParticipantPolicy;
use App\Services\ApprovalRequestCreation;
use App\Services\ApprovalWorkflow;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    seedRoles();
    fakeBot();
    $this->withoutVite();
    $this->project = makeProject();
    $this->author = makeUserWithRole('approver');
    $this->signer = makeUserWithRole('approver');
    $this->author->projects()->attach($this->project);
    $this->signer->projects()->attach($this->project);
    $this->request = ApprovalRequest::create(['applicant_id' => $this->author->id, 'project_id' => $this->project->id,
        'type' => 'approval', 'name' => 'Policy test', 'status' => 'pending_approval', 'currency' => 'UZS',
        'transaction_type' => 'bank', 'total_price' => 100, 'expected_approval_level' => 1]);
    $this->approval = $this->request->approvals()->create(['approver_id' => $this->signer->id, 'level' => 1, 'status' => 'pending']);
});

test('revoking a role project or account blocks an already assigned signer', function ($revocation) {
    if ($revocation === 'project') {
        $this->signer->projects()->detach();
    }
    if ($revocation === 'role') {
        $this->signer->update(['role_id' => \App\Models\Role::where('name', 'requester')->value('id')]);
    }
    if ($revocation === 'account') {
        $this->signer->update(['account_status' => 'rejected']);
    }
    // Keep the original model instance: the service must read current permissions.
    expect(fn () => app(ApprovalWorkflow::class)->approve($this->request->id, $this->signer, null))->toThrow(ValidationException::class);
    expect($this->approval->fresh()->status)->toBe('pending')->and($this->request->fresh()->status)->toBe('pending_approval');
})->with(['project', 'role', 'account']);

test('an author cannot approve an existing self assigned request', function () {
    $this->approval->update(['approver_id' => $this->author->id]);
    expect(fn () => app(ApprovalWorkflow::class)->approve($this->request->id, $this->author, null))->toThrow(ValidationException::class);
});

test('creation refuses a self approval chain and a signer outside the project', function ($candidate) {
    $id = $candidate === 'self' ? $this->author->id : makeUserWithRole('approver')->id;
    $payload = ['type' => 'approval', 'project_id' => $this->project->id, 'currency' => 'UZS', 'transaction_type' => 'bank',
        'supplier_name' => 'Vendor', 'items' => [['name' => 'Item', 'quantity' => 1, 'price_per_unit' => 100]], 'approvers' => [$id]];
    expect(fn () => app(ApprovalRequestCreation::class)->create($this->author, $payload))->toThrow(ValidationException::class);
    expect(ApprovalRequest::count())->toBe(1);
})->with(['self', 'foreign']);

test('an empty project never falls back to company wide signers', function () {
    $empty = makeProject();
    makeUserWithRole('superadmin', ['signature_path' => 'signature.png']);
    expect(app(ApprovalRequestCreation::class)->approvers($empty->id))->toBeEmpty()
        ->and(app(\App\Services\ManagementDocumentCreation::class)->signers($empty->id))->toBeEmpty();
});

test('adding a foreign signer is rejected and a project signer is accepted', function () {
    $next = makeUserWithRole('approver');
    expect(fn () => app(ApprovalWorkflow::class)->addApprover($this->request->id, $this->signer, $next->id))->toThrow(ValidationException::class);
    expect($this->request->approvals()->count())->toBe(1);
    $next->projects()->attach($this->project);
    app(ApprovalWorkflow::class)->addApprover($this->request->id, $this->signer, $next->id);
    expect($this->request->approvals()->count())->toBe(2);
});

test('adding a signer queues an escaped notification that can be retried', function () {
    $this->signer->update(['full_name' => 'Signer <script>']);
    $next = makeUserWithRole('approver');
    $next->projects()->attach($this->project);
    app(ApprovalWorkflow::class)->addApprover($this->request->id, $this->signer, $next->id);
    $effect = \Illuminate\Support\Facades\DB::table('request_effects')->first();
    expect($effect->event)->toStartWith('approver-added:');

    $bot = \Mockery::mock(\SergiX44\Nutgram\Nutgram::class);
    $bot->shouldReceive('sendMessage')->once()->andThrow(new RuntimeException('offline'));
    app()->instance(\SergiX44\Nutgram\Nutgram::class, $bot);
    expect(fn () => app(\App\Services\RequestEffects::class)->deliver($effect->id))->toThrow(RuntimeException::class);
    expect(\Illuminate\Support\Facades\DB::table('request_effects')->value('completed_at'))->toBeNull();

    $spy = fakeBot();
    app(\App\Services\RequestEffects::class)->deliver($effect->id);
    app(\App\Services\RequestEffects::class)->deliver($effect->id);
    expect($spy->messagesTo($next->telegram_id))->toHaveCount(1)
        ->and($spy->messagesTo($next->telegram_id)[0]['text'])->toContain('Signer &lt;script&gt;');
});

test('web rejection cannot save an empty reason', function () {
    $this->actingAs($this->signer)->postJson(route('requests.reject', $this->request->id), ['revision' => 1])
        ->assertJsonValidationErrors('reason');
    expect($this->approval->fresh()->status)->toBe('pending')
        ->and($this->request->fresh()->status)->toBe('pending_approval');
});

test('web candidate list is scoped to the request and hides its author', function () {
    $foreign = makeUserWithRole('approver');
    $next = makeUserWithRole('approver');
    $next->projects()->attach($this->project);
    $this->actingAs($this->signer)->getJson('/supply/approvers?request_id='.$this->request->id)
        ->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $next->id);
    $this->actingAs($foreign)->getJson('/supply/approvers?request_id='.$this->request->id)->assertNotFound();
});

test('web reject and return actions check current project membership', function () {
    $this->signer->projects()->detach();
    $this->actingAs($this->signer)->postJson(route('requests.request-changes', $this->request->id), ['reason' => 'Fix', 'revision' => 1])->assertForbidden();
    $this->postJson(route('requests.reject', $this->request->id), ['comment' => 'No', 'revision' => 1])->assertUnprocessable();
    expect($this->approval->fresh()->status)->toBe('pending')->and($this->request->fresh()->status)->toBe('pending_approval');
});

test('management decisions require both independent membership and a signature', function () {
    $this->request->update(['type' => 'management_document']);
    $policy = app(ApprovalParticipantPolicy::class);
    expect($policy->canDecide($this->request, $this->signer))->toBeFalse();
    $this->signer->update(['signature_path' => 'signatures/a.png']);
    expect($policy->canDecide($this->request, $this->signer))->toBeTrue();
    $this->signer->projects()->detach();
    expect($policy->canDecide($this->request, $this->signer))->toBeFalse();
});

test('revoked signers receive neither attachment notifications nor final protocol access', function () {
    $this->request->attachments()->create(['file_type' => 'pdf', 'telegram_file_id' => 'private-file']);
    $this->signer->projects()->detach();
    app(\SergiX44\Nutgram\Nutgram::class)->shouldNotReceive('sendDocument');
    app(\App\Services\RequestCreationNotifier::class)->forwardAttachments($this->request, $this->signer);
    expect(app(\App\Services\ApprovalRequestAccess::class)->canView($this->request, $this->signer))->toBeFalse();
});

test('Telegram supply conversion rechecks project signers before changing the request', function ($assigned) {
    $director = makeUserWithRole('supplier');
    $director->projects()->attach($this->project);
    $this->request->update(['type' => 'supply', 'status' => 'pending_supply']);
    $this->request->items()->create(['name' => 'Item', 'quantity' => 1, 'price_per_unit' => 100, 'total_price' => 100]);
    if (! $assigned) {
        $this->signer->projects()->detach();
    }
    $conversation = new class extends \App\Telegram\Conversations\ConvertSupplyRequest
    {
        protected function end(): void {}

        protected function sendMenu(\SergiX44\Nutgram\Nutgram $bot): void {}

        protected function releaseCallbackClaim(\SergiX44\Nutgram\Nutgram $bot, string $action): void {}

        public function saveForTest(\SergiX44\Nutgram\Nutgram $bot): void
        {
            $this->convert($bot);
        }
    };
    $bot = app(\SergiX44\Nutgram\Nutgram::class);
    $bot->shouldReceive('userId')->andReturn($director->telegram_id);
    $conversation->requestId = $this->request->id;
    $conversation->revision = $this->request->revision;
    $conversation->supplierName = 'Vendor';
    $conversation->approvers = [$this->signer->id];
    $conversation->currency = 'UZS';
    $conversation->transactionType = 'bank';
    $conversation->saveForTest($bot);
    expect($this->request->fresh()->status)->toBe($assigned ? 'pending_approval' : 'pending_supply');
    if ($assigned) {
        expect($this->request->fresh()->supply_applicant_id)->toBe($this->author->id)
            ->and($this->request->fresh()->applicant_id)->toBe($director->id);
    }
})->with([true, false]);
