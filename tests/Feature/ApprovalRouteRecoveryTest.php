<?php

use App\Models\ApprovalRequest;
use App\Models\Role;
use App\Services\AccountActivation;
use App\Services\ApprovalRouteRecovery;
use App\Services\ApprovalWorkflow;
use App\Services\RequestEffects;
use App\Services\UserAdministration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use SergiX44\Nutgram\Nutgram;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    seedRoles();
    $this->withoutVite();
    Storage::fake('local');
    $this->admin = makeUserWithRole('superadmin');
    $this->owner = makeUserWithRole('requester');
    $this->first = makeUserWithRole('approver');
    $this->future = makeUserWithRole('approver');
    $this->replacement = makeUserWithRole('approver');
    $this->project = makeProject('Recovery');
    foreach ([$this->owner, $this->first, $this->future, $this->replacement] as $u) {
        $u->projects()->attach($this->project);
    }
    $this->request = ApprovalRequest::create(['applicant_id' => $this->owner->id, 'project_id' => $this->project->id,
        'type' => 'approval', 'name' => 'Unchanged request', 'status' => 'pending_approval', 'expected_approval_level' => 1,
        'currency' => 'USD', 'total_price' => 100, 'deadline_date' => '2026-12-01']);
    foreach ([$this->first, $this->future] as $i => $u) {
        $this->request->approvals()->create(['approver_id' => $u->id, 'level' => $i + 1, 'status' => 'pending']);
    }
});

function pauseRecoveryRequest(): void
{
    app(AccountActivation::class)->update(test()->admin, test()->future->id, false);
    test()->request->refresh();
}

function recoveryInput(): array
{
    $r = test()->request->fresh();

    return ['revision' => $r->revision, 'sequence' => $r->route_recovery_sequence];
}

function saveRecoveryRoute(array $ids, $actor = null): void
{
    app(ApprovalRouteRecovery::class)->save(test()->request->id, $actor ?? test()->owner,
        recoveryInput() + ['approvers' => $ids]);
}

test('access changes pause current or future pending signers atomically', function (string $change, int $level) {
    $signer = $level === 1 ? $this->first : $this->future;
    if ($change === 'deactivate') {
        app(AccountActivation::class)->update($this->admin, $signer->id, false);
    } elseif ($change === 'role') {
        app(UserAdministration::class)->update($this->admin, $signer->id, ['role_id' => Role::where('name', 'requester')->value('id')]);
    } else {
        app(UserAdministration::class)->update($this->admin, $signer->id, [], []);
    }
    expect($this->request->fresh()->status)->toBe('approval_paused')
        ->and($this->request->fresh()->revision)->toBe(1)
        ->and($this->request->fresh()->route_unavailable[0]['id'])->toBe($signer->id)
        ->and($this->request->fresh()->route_unavailable[0]['changed_by'])->toBe($this->admin->id)
        ->and($this->request->approvals()->count())->toBe(2)
        ->and(DB::table('request_effects')->where('event', 'route-paused:'.$signer->id)->count())->toBe(1);
    expect(fn () => app(ApprovalWorkflow::class)->approve($this->request->id, $this->first, null))->toThrow(ValidationException::class);
})->with(['deactivate', 'role', 'project'])->with([1, 2]);

test('completed decisions and finished requests do not trigger route recovery', function (bool $finished) {
    $this->request->approvals()->where('approver_id', $this->first->id)->update(['status' => 'approved', 'comment' => 'Done', 'action_date' => now()]);
    $this->request->update(['expected_approval_level' => 2, 'status' => $finished ? 'approved' : 'pending_approval']);
    app(AccountActivation::class)->update($this->admin, $finished ? $this->future->id : $this->first->id, false);
    expect($this->request->fresh()->status)->toBe($finished ? 'approved' : 'pending_approval')
        ->and(DB::table('request_effects')->count())->toBe(0);
})->with([false, true]);

test('eligible role changes and project additions leave the route active', function () {
    app(UserAdministration::class)->update($this->admin, $this->future->id,
        ['role_id' => Role::where('name', 'supplier')->value('id')], [$this->project->id, makeProject('Additional')->id]);
    expect($this->request->fresh()->status)->toBe('pending_approval')->and(DB::table('request_effects')->count())->toBe(0);
});

test('multiple unavailable participants and repeated access changes preserve one pause and require explicit recovery', function () {
    pauseRecoveryRequest();
    app(AccountActivation::class)->update($this->admin, $this->future->id, false);
    app(AccountActivation::class)->update($this->admin, $this->first->id, false);
    app(AccountActivation::class)->update($this->admin, $this->first->id, true);
    app(AccountActivation::class)->update($this->admin, $this->future->id, true);
    expect($this->request->fresh()->route_unavailable)->toHaveCount(2)
        ->and($this->request->fresh()->status)->toBe('approval_paused')
        ->and(DB::table('request_effects')->count())->toBe(2)
        ->and($this->request->fresh()->route_recovery_sequence)->toBe(2);
});

test('route replacement preserves all request data payments documents and historical signatures', function (string $kind) {
    if ($kind === 'financial') {
        $initial = makeUserWithRole('requester');
        $this->owner->update(['role_id' => Role::where('name', 'supplier')->value('id')]);
        $this->request->update(['supply_applicant_id' => $initial->id]);
    } else {
        $this->request->update(['type' => $kind]);
    }
    if ($kind === 'management_document') {
        Storage::disk('local')->put('source.docx', 'source');
        Storage::disk('local')->put('preview.pdf', 'preview');
        Storage::disk('local')->put('replacement.png', 'signature');
        $this->replacement->update(['signature_path' => 'replacement.png']);
        $this->request->update(['source_docx_path' => 'source.docx', 'source_sha256' => hash('sha256', 'source'),
            'preview_pdf_path' => 'preview.pdf', 'document_version' => 1, 'document_kind' => 'order',
            'effective_date_mode' => 'specific_date', 'effective_date' => '2026-12-01']);
    }
    $this->request->update(['applicant_signature_snapshot_path' => 'history/owner.png', 'applicant_signature_sha256' => 'owner-hash']);
    Storage::disk('local')->put('history/first.png', 'historical signature');
    $this->request->approvals()->where('level', 1)->update(['status' => 'approved', 'comment' => 'Original decision',
        'action_date' => now(), 'signature_snapshot_path' => 'history/first.png', 'signature_sha256' => hash('sha256', 'historical signature')]);
    $this->request->update(['expected_approval_level' => 2]);
    $item = $this->request->items()->create(['name' => 'Original item', 'description' => 'Keep', 'quantity' => 2, 'price_per_unit' => 50, 'total_price' => 100]);
    $file = $this->request->attachments()->create(['file_type' => 'pdf', 'file_path' => 'preview.pdf', 'telegram_file_id' => 'telegram-original']);
    $payment = $this->request->tranches()->create(['amount' => 100, 'percent' => 100, 'status' => 'paid', 'due_date' => '2026-12-01',
        'payment_proof' => 'paid.pdf', 'payment_proof_telegram_id' => 'original-proof']);
    pauseRecoveryRequest();
    $before = $this->request->fresh()->getAttributes();
    $children = [$item->fresh()->getAttributes(), $file->fresh()->getAttributes(), $payment->fresh()->getAttributes()];
    saveRecoveryRoute([$this->replacement->id]);
    expect($this->request->fresh()->status)->toBe('approval_paused')->and($this->request->approvals()->count())->toBe(2);
    app(ApprovalRouteRecovery::class)->submit($this->request->id, $this->owner, recoveryInput());
    $after = $this->request->fresh();
    expect($after->status)->toBe('pending_approval')->and($after->revision)->toBe(2)
        ->and($after->expected_approval_level)->toBe(1)->and(ApprovalRequest::count())->toBe(1)
        ->and([$item->fresh()->getAttributes(), $file->fresh()->getAttributes(), $payment->fresh()->getAttributes()])->toBe($children);
    $changed = ['status', 'revision', 'expected_approval_level', 'document_version', 'route_unavailable', 'route_draft', 'route_recovery_sequence', 'approval_paused_at', 'updated_at'];
    expect(collect($after->getAttributes())->except($changed)->all())->toBe(collect($before)->except($changed)->all());
    $approval = $after->approvals()->sole();
    expect($approval->approver_id)->toBe($this->replacement->id)->and($approval->status)->toBe('pending')
        ->and($approval->signature_snapshot_path)->toBeNull()->and($approval->comment)->toBeNull()->and($approval->action_date)->toBeNull();
    $history = json_decode(DB::table('request_revisions')->where('approval_request_id', $after->id)->sole()->snapshot, true);
    $previousDecision = collect($history['approvals'])->firstWhere('approver_id', $this->first->id);
    expect($previousDecision['comment'])->toBe('Original decision')
        ->and($previousDecision['signature_snapshot_path'])->toBe('history/first.png')
        ->and($history['tranches'][0]['payment_proof_telegram_id'])->toBe('original-proof')
        ->and($history['applicant_signature_snapshot_path'])->toBe('history/owner.png');
    Storage::disk('local')->assertExists('history/first.png');
})->with(['approval', 'supply', 'financial', 'management_document']);

test('authorization protects recovery GET and direct API writes including financial stage ownership', function () {
    pauseRecoveryRequest();
    $initial = makeUserWithRole('requester');
    $this->owner->update(['role_id' => Role::where('name', 'supplier')->value('id')]);
    $this->request->update(['supply_applicant_id' => $initial->id]);
    foreach ([$initial, $this->first, makeUserWithRole('supplier')] as $outsider) {
        $this->actingAs($outsider)->get(route('requests.approval-route.edit', $this->request))->assertForbidden();
        $this->put(route('requests.approval-route.save', $this->request), recoveryInput() + ['approvers' => [$this->replacement->id]])->assertForbidden();
        $this->post(route('requests.approval-route.submit', $this->request), recoveryInput())->assertForbidden();
    }
    $this->actingAs($this->owner)->get(route('requests.approval-route.edit', $this->request))->assertOk();
    app(AccountActivation::class)->update($this->admin, $this->owner->id, false);
    expect(app(ApprovalRouteRecovery::class)->canRecover($this->request->fresh(), $this->owner))->toBeFalse();
    $this->actingAs($this->admin)->put(route('requests.approval-route.save', $this->request), recoveryInput() + ['approvers' => [$this->replacement->id]])->assertSessionHasNoErrors();
    $this->post(route('requests.approval-route.submit', $this->request), recoveryInput())->assertRedirect(route('requests.detail', $this->request));
});

test('ordinary administrators cannot take over another administrative authors request', function (string $role) {
    pauseRecoveryRequest();
    $administrativeOwner = makeUserWithRole($role);
    $this->request->update(['applicant_id' => $administrativeOwner->id]);
    $ordinary = makeUserWithRole('admin');
    expect(app(ApprovalRouteRecovery::class)->canRecover($this->request->fresh(), $ordinary))->toBeFalse();
    $this->actingAs($ordinary)->put(route('requests.approval-route.save', $this->request), recoveryInput() + ['approvers' => [$this->replacement->id]])->assertForbidden();
})->with(['admin', 'superadmin']);

test('new chains enforce independence membership eligibility duplicates and document signatures', function (string $invalid) {
    pauseRecoveryRequest();
    $ids = match ($invalid) {
        'owner' => [$this->owner->id], 'inactive' => [$this->future->id],
        'outsider' => [makeUserWithRole('approver')->id], 'duplicate' => [$this->replacement->id, $this->replacement->id],
        'empty' => [], default => [$this->replacement->id],
    };
    if ($invalid === 'signature') {
        $this->request->update(['type' => 'management_document']);
    }
    $this->actingAs($this->owner)->putJson(route('requests.approval-route.save', $this->request), recoveryInput() + ['approvers' => $ids])->assertUnprocessable();
    expect($this->request->fresh()->route_draft)->toBeNull()->and($this->request->fresh()->revision)->toBe(1);
})->with(['owner', 'inactive', 'outsider', 'duplicate', 'empty', 'signature']);

test('stale edit tokens duplicate submits and old approval reject and add buttons cannot change a new version', function () {
    pauseRecoveryRequest();
    $stale = recoveryInput();
    saveRecoveryRoute([$this->first->id]);
    expect(fn () => app(ApprovalRouteRecovery::class)->save($this->request->id, $this->owner, $stale + ['approvers' => [$this->replacement->id]]))->toThrow(HttpException::class);
    $send = recoveryInput();
    app(ApprovalRouteRecovery::class)->submit($this->request->id, $this->owner, $send);
    expect(fn () => app(ApprovalRouteRecovery::class)->submit($this->request->id, $this->owner, $send))->toThrow(HttpException::class);
    expect(fn () => app(ApprovalWorkflow::class)->approve($this->request->id, $this->first, 'stale', 1))->toThrow(ValidationException::class);
    expect(fn () => app(ApprovalWorkflow::class)->reject($this->request->id, $this->first, 'stale', 1))->toThrow(HttpException::class);
    expect(fn () => app(ApprovalWorkflow::class)->addApprover($this->request->id, $this->first, $this->replacement->id, 1))->toThrow(ValidationException::class);
    expect($this->request->fresh()->revision)->toBe(2)->and(DB::table('request_revisions')->count())->toBe(1)
        ->and(DB::table('request_effects')->where('event', 'route-resubmitted')->count())->toBe(1)
        ->and($this->request->approvals()->sole()->status)->toBe('pending');
});

test('a saved replacement losing access is revalidated before submission without partial history', function () {
    pauseRecoveryRequest();
    saveRecoveryRoute([$this->replacement->id]);
    app(AccountActivation::class)->update($this->admin, $this->replacement->id, false);
    expect(fn () => app(ApprovalRouteRecovery::class)->submit($this->request->id, $this->owner, recoveryInput()))->toThrow(ValidationException::class);
    expect(DB::table('request_revisions')->count())->toBe(0)->and($this->request->fresh()->status)->toBe('approval_paused');
});

test('site shows the pause unavailable names and shared recovery permission', function () {
    pauseRecoveryRequest();
    $this->actingAs($this->owner)->get(route('requests.detail', $this->request))
        ->assertInertia(fn (Assert $p) => $p->where('request.status', 'approval_paused')->where('request.can_recover_route', true)
            ->where('request.route_unavailable.0.name', $this->future->full_name)->where('request.can_approve', false));
});

test('pause notification delivery retries failed pages without repeating delivered pages or recording duplicate events', function () {
    $this->future->update(['full_name' => str_repeat('&', 255)]);
    $this->request->update(['name' => str_repeat('<', 255)]);
    pauseRecoveryRequest();
    $id = DB::table('request_effects')->where('event', 'route-paused:'.$this->future->id)->value('id');
    $sent = [];
    $attempt = 0;
    $bot = Mockery::mock(Nutgram::class);
    $bot->shouldReceive('sendMessage')->andReturnUsing(function (...$args) use (&$sent, &$attempt) {
        if (++$attempt === 2) {
            throw new RuntimeException('Temporary transport failure');
        }
        $sent[] = ['text' => $args[0], 'chat_id' => $args[1], 'reply_markup' => $args[12] ?? null];
    });
    app()->instance(Nutgram::class, $bot);
    expect(fn () => app(RequestEffects::class)->deliver($id))->toThrow(RuntimeException::class);
    expect(DB::table('request_effects')->where('id', $id)->value('completed_at'))->toBeNull();
    app(RequestEffects::class)->deliver($id);
    app(RequestEffects::class)->deliver($id);
    $visible = html_entity_decode(strip_tags(implode('', array_column($sent, 'text'))), ENT_QUOTES, 'UTF-8');
    expect($visible)->toContain('больше недоступен', 'повторное согласование')->not->toContain('отключил себя')
        ->and(array_unique(array_column($sent, 'chat_id')))->toBe([$this->owner->telegram_id])
        ->and(count($sent))->toBe(count(array_unique(array_column($sent, 'text'))));
    $keyboard = json_decode(json_encode($sent[0]['reply_markup']), true);
    expect($keyboard['inline_keyboard'][0][0]['url'])->toBe(route('requests.approval-route.edit', $this->request))
        ->and(DB::table('request_effects')->where('id', $id)->value('completed_at'))->not->toBeNull();
});

test('financial pause notifies its manager and unavailable manager falls back to authorized administrators', function () {
    $initial = makeUserWithRole('requester');
    $this->owner->update(['role_id' => Role::where('name', 'supplier')->value('id')]);
    $this->request->update(['supply_applicant_id' => $initial->id]);
    pauseRecoveryRequest();
    $spy = fakeBot();
    app(RequestEffects::class)->deliver(DB::table('request_effects')->value('id'));
    expect($spy->messagesTo($this->owner->telegram_id))->toHaveCount(1)->and($spy->messagesTo($initial->telegram_id))->toBeEmpty();
    app(AccountActivation::class)->update($this->admin, $this->owner->id, false);
    app(RequestEffects::class)->deliver(DB::table('request_effects')->where('event', 'route-owner-unavailable:'.$this->owner->id)->value('id'));
    expect($spy->messagesTo($this->admin->telegram_id))->toHaveCount(1)
        ->and($spy->messagesTo($this->admin->telegram_id)[0]['text'])->toContain('уполномоченному администратору');
});

test('Telegram recovery saves and explicitly submits through the shared service and stale callbacks do nothing', function () {
    pauseRecoveryRequest();
    $dialog = new App\Telegram\Conversations\RecoverApprovalRoute;
    $bot = Mockery::mock(Nutgram::class);
    $callback = new SergiX44\Nutgram\Telegram\Types\Inline\CallbackQuery;
    $bot->shouldReceive('userId')->andReturn($this->owner->telegram_id);
    $bot->shouldReceive('isCallbackQuery')->andReturnTrue();
    $bot->shouldReceive('answerCallbackQuery')->andReturnNull();
    $bot->shouldReceive('callbackQuery')->andReturn($callback);
    $bot->shouldReceive('sendMessage')->andReturnNull();
    $bot->shouldReceive('update')->andReturnNull();
    $bot->shouldReceive('stepConversation')->andReturnSelf();
    $bot->shouldReceive('endConversation')->andReturnSelf();
    $dialog($bot, $this->request->id, 1);
    $token = $this->request->fresh()->route_recovery_sequence;
    $callback->data = "route:{$this->request->id}:1:{$token}:remove:{$this->future->id}";
    $dialog->handle($bot);
    $callback->data = "route:{$this->request->id}:1:{$token}:add:{$this->replacement->id}";
    $dialog->handle($bot);
    $callback->data = "route:{$this->request->id}:1:{$token}:save:0";
    $dialog->handle($bot);
    expect($this->request->fresh()->status)->toBe('approval_paused')
        ->and($this->request->fresh()->route_draft['approvers'])->toBe([$this->first->id, $this->replacement->id]);
    // This save button predates the successful save and cannot submit or add versions.
    $callback->data = "route:{$this->request->id}:1:{$token}:submit:0";
    $dialog->handle($bot);
    expect($this->request->fresh()->revision)->toBe(1);
    $token = $this->request->fresh()->route_recovery_sequence;
    $callback->data = "route:{$this->request->id}:1:{$token}:submit:0";
    $dialog->handle($bot);
    $dialog->handle($bot);
    expect($this->request->fresh()->revision)->toBe(2)
        ->and(DB::table('request_revisions')->count())->toBe(1)
        ->and(DB::table('request_effects')->where('event', 'route-resubmitted')->count())->toBe(1);
});

test('revocation transaction rolls back both access and the pause if effect persistence fails', function () {
    $this->mock(RequestEffects::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('Database unavailable'));
    expect(fn () => app(AccountActivation::class)->update($this->admin, $this->future->id, false))->toThrow(RuntimeException::class);
    expect($this->future->fresh()->is_active)->toBeTrue()->and($this->request->fresh()->status)->toBe('pending_approval')
        ->and($this->request->fresh()->route_unavailable)->toBeNull();
});

test('administrator authors with legitimate cross-project rights remain responsible without a membership', function () {
    $this->request->update(['applicant_id' => $this->admin->id]);
    pauseRecoveryRequest();
    expect(app(ApprovalRouteRecovery::class)->recipients($this->request->fresh())->pluck('id')->all())->toBe([$this->admin->id]);
});

test('resubmission rollback preserves the prior route and history when effect recording fails', function () {
    pauseRecoveryRequest();
    saveRecoveryRoute([$this->replacement->id]);
    $before = $this->request->approvals()->orderBy('level')->get()->toArray();
    $this->mock(RequestEffects::class)->shouldReceive('record')->once()->withArgs(fn ($r, $event) => $event === 'route-resubmitted')
        ->andThrow(new RuntimeException('Effect persistence failed'));
    expect(fn () => app(ApprovalRouteRecovery::class)->submit($this->request->id, $this->owner, recoveryInput()))->toThrow(RuntimeException::class);
    expect($this->request->fresh()->revision)->toBe(1)->and($this->request->fresh()->status)->toBe('approval_paused')
        ->and($this->request->approvals()->orderBy('level')->get()->toArray())->toBe($before)
        ->and(DB::table('request_revisions')->count())->toBe(0);
});

test('recovery API cannot rewrite request content or bypass the separate explicit submission', function () {
    pauseRecoveryRequest();
    $this->actingAs($this->owner)->putJson(route('requests.approval-route.save', $this->request), recoveryInput() + [
        'approvers' => [$this->replacement->id], 'status' => 'approved', 'total_price' => 9999,
        'items' => [['name' => 'Injected']], 'applicant_id' => $this->first->id,
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect($this->request->fresh()->status)->toBe('approval_paused')
        ->and((float) $this->request->fresh()->total_price)->toBe(100.0)
        ->and($this->request->fresh()->applicant_id)->toBe($this->owner->id)
        ->and($this->request->items()->count())->toBe(0)
        ->and(DB::table('request_revisions')->count())->toBe(0);
});

test('a repeated signer captures a new signature and decision rather than inheriting the archived decision', function () {
    Storage::disk('local')->put('signatures/current.png', 'current signature');
    Storage::disk('local')->put('history/old.png', 'old signature');
    $this->first->update(['signature_path' => 'signatures/current.png']);
    $this->request->approvals()->where('level', 1)->update(['status' => 'approved', 'comment' => 'Old decision',
        'signature_snapshot_path' => 'history/old.png', 'signature_sha256' => hash('sha256', 'old signature'), 'action_date' => now()]);
    $this->request->update(['expected_approval_level' => 2]);
    pauseRecoveryRequest();
    saveRecoveryRoute([$this->first->id]);
    app(ApprovalRouteRecovery::class)->submit($this->request->id, $this->owner, recoveryInput());
    app(ApprovalWorkflow::class)->approve($this->request->id, $this->first, 'New decision', 2);
    $decision = $this->request->approvals()->sole();
    expect($decision->comment)->toBe('New decision')->and($decision->signature_snapshot_path)->not->toBe('history/old.png')
        ->and($decision->signature_sha256)->toBe(hash('sha256', 'current signature'));
    $old = collect(json_decode(DB::table('request_revisions')->sole()->snapshot, true)['approvals'])->firstWhere('approver_id', $this->first->id);
    expect($old['comment'])->toBe('Old decision')->and($old['signature_sha256'])->toBe(hash('sha256', 'old signature'));
    Storage::disk('local')->assertExists('history/old.png');
});

test('an old notification worker cannot complete delivery for a recovered route', function () {
    DB::table('request_creation_notifications')->insert(['approval_request_id' => $this->request->id, 'created_at' => now()]);
    $oldBot = \Mockery::mock(Nutgram::class);
    $oldBot->shouldReceive('sendMessage')->once()->andReturnUsing(function () {
        // The external send is in flight while another transaction replaces the route.
        pauseRecoveryRequest();
        saveRecoveryRoute([$this->replacement->id]);
        app(ApprovalRouteRecovery::class)->submit($this->request->id, $this->owner, recoveryInput());
    });
    app()->instance(Nutgram::class, $oldBot);
    app(\App\Services\RequestCreationNotifier::class)->deliver($this->request->id);

    $notice = DB::table('request_creation_notifications')->where('approval_request_id', $this->request->id)->first();
    expect($this->request->fresh()->revision)->toBe(2)
        ->and($notice->delivered_at)->toBeNull()
        ->and(json_decode($notice->completed_steps, true))->toBe([]);

    $spy = fakeBot();
    app(\App\Services\RequestCreationNotifier::class)->deliver($this->request->id);
    app(\App\Services\RequestCreationNotifier::class)->deliver($this->request->id);
    expect($spy->messagesTo($this->replacement->telegram_id))->toHaveCount(1)
        ->and(DB::table('request_creation_notifications')->where('approval_request_id', $this->request->id)->value('delivered_at'))->not->toBeNull();
});

test('notification migration preserves delivery progress for existing revised requests', function () {
    $this->request->update(['revision' => 4]);
    $migration = require database_path('migrations/2026_10_15_000001_version_request_creation_notifications.php');
    $migration->down();
    DB::table('request_creation_notifications')->insert([
        'approval_request_id' => $this->request->id, 'created_at' => now(), 'delivered_at' => now(),
        'completed_steps' => '["revision:4:page:0"]',
    ]);
    $migration->up();
    $notice = DB::table('request_creation_notifications')->where('approval_request_id', $this->request->id)->first();
    expect((int) $notice->revision)->toBe(4)
        ->and($notice->delivered_at)->not->toBeNull()
        ->and(json_decode($notice->completed_steps, true))->toBe(['revision:4:page:0']);
});
