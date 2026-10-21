<?php

use App\Models\ApprovalRequest;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ApprovalWorkflow;
use App\Services\PdfGeneratorService;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    seedRoles();
    $this->withoutVite();
    fakeBot();
    $this->mock(PdfGeneratorService::class)->shouldReceive('generateProtocol')->andReturn('audit-nonexistent.pdf');
});

function securedRequest(User $user, array $attributes = []): ApprovalRequest
{
    return ApprovalRequest::create(array_merge([
        'applicant_id' => $user->id, 'project_id' => makeProject('Security project')->id,
        'type' => 'approval', 'name' => 'Private request', 'status' => 'approved',
        'currency' => 'UZS', 'transaction_type' => 'bank', 'total_price' => 1000, 'expected_approval_level' => 1,
    ], $attributes));
}

test('rejected accounts cannot access finance or password settings', function () {
    $user = makeUserWithRole('accountant', ['account_status' => 'rejected']);
    $this->actingAs($user)->get(route('finance.requests'))->assertForbidden();
    $this->get(route('user-password.edit'))->assertForbidden();
});

test('approved amounts are immutable while an unchanged tranche can be paid once', function () {
    $user = makeUserWithRole('accountant');
    $request = securedRequest(makeUserWithRole('requester'));
    $user->projects()->attach($request->project_id);
    $tranche = $request->tranches()->create(['amount' => 1000, 'percent' => 100, 'status' => 'pending']);
    $url = route('finance.tranches.update', [$request->id, $tranche->id]);
    $this->actingAs($user)->put($url, ['status' => 'paid', 'amount' => 2500])->assertSessionHasErrors('amount');
    expect((float) $tranche->fresh()->amount)->toBe(1000.0)->and($request->fresh()->status)->toBe('approved');
    $this->put($url, ['status' => 'paid', 'amount' => 1000])->assertSessionHasNoErrors();
    expect($request->fresh()->status)->toBe('paid');
    $this->put($url, ['status' => 'pending'])->assertSessionHasErrors('status');
    expect($tranche->fresh()->status)->toBe('paid');
});

test('a payment schedule that differs from the budget cannot be marked paid', function () {
    $user = makeUserWithRole('admin');
    $request = securedRequest($user);
    $tranche = $request->tranches()->create(['amount' => 900, 'percent' => 100, 'status' => 'pending']);
    $this->actingAs($user)->put(route('finance.tranches.update', [$request->id, $tranche->id]), ['status' => 'paid'])->assertSessionHasErrors('amount');
    expect($tranche->fresh()->status)->toBe('pending');
});

test('paid requests cannot be deleted by their applicant', function () {
    $user = makeUserWithRole('approver');
    $request = securedRequest($user, ['status' => 'paid']);
    $this->actingAs($user)->delete(route('requests.delete', $request->id))->assertSessionHasErrors('error');
    $this->assertNotSoftDeleted('approval_requests', ['id' => $request->id]);
});

test('approval notifies assigned finance users but not unrelated ones', function () {
    $spy = fakeBot();
    $approver = makeUserWithRole('approver');
    $unrelated = makeUserWithRole('accountant');
    $related = makeUserWithRole('accountant');
    $request = securedRequest(makeUserWithRole('requester'), ['status' => 'pending_approval']);
    $approver->projects()->attach($request->project_id);
    $related->projects()->attach($request->project_id);
    $request->approvals()->create(['approver_id' => $approver->id, 'level' => 1, 'status' => 'pending']);
    $this->actingAs($approver)->post(route('requests.approve', $request->id))->assertSessionHasNoErrors();
    foreach (\Illuminate\Support\Facades\DB::table('request_effects')->pluck('id') as $effectId) {
        app(\App\Services\RequestEffects::class)->deliver($effectId);
    }
    expect($spy->messagesTo($unrelated->telegram_id))->toBeEmpty()
        ->and($spy->messagesTo($related->telegram_id))->not->toBeEmpty();
});

test('fractional supply quantities are preserved and the actual actor becomes applicant', function () {
    makeUserWithRole('supplier');
    $actor = makeUserWithRole('supplier');
    $original = makeUserWithRole('requester');
    $request = securedRequest($original, ['type' => 'supply', 'status' => 'pending_supply']);
    $signer = makeUserWithRole('approver');
    $signer->projects()->attach($request->project_id);
    $item = $request->items()->create(['name' => 'Cable', 'quantity' => 1.5, 'price_per_unit' => 0, 'total_price' => 0]);
    $actor->projects()->attach($request->project_id);
    $this->actingAs($actor)->post(route('supply.requests.convert', $request->id), [
        'revision' => $request->revision,
        'currency' => 'UZS', 'supplier_name' => 'Supplier', 'transaction_type' => 'bank',
        'approvers' => [$signer->id],
        'items' => [['id' => $item->id, 'quantity' => 1.5, 'price_per_unit' => 100]],
    ])->assertSessionHasNoErrors();
    expect((float) $item->fresh()->quantity)->toBe(1.5)->and((float) $request->fresh()->total_price)->toBe(150.0)
        ->and($request->fresh()->applicant_id)->toBe($actor->id)->and($request->fresh()->supply_applicant_id)->toBe($original->id);
});

test('supplier reassignment enforces both access and the request state', function () {
    $supplierUser = makeUserWithRole('supplier');
    $request = securedRequest(makeUserWithRole('requester'), ['status' => 'paid']);
    $supplier = Supplier::create(['name' => 'Replacement']);
    $this->actingAs($supplierUser)->post(route('suppliers.attach', $request->id), ['supplier_id' => $supplier->id])->assertNotFound();
    $this->actingAs(makeUserWithRole('admin'))->post(route('suppliers.attach', $request->id), ['supplier_id' => $supplier->id])->assertStatus(409);
    expect($request->fresh()->supplier_id)->toBeNull();
});

test('private protocols require authorization and remain inaccessible after deletion', function () {
    Storage::fake('local');
    $user = makeUserWithRole('approver');
    $request = securedRequest($user, ['status' => 'rejected', 'pdf_url' => 'protocols/private.pdf']);
    Storage::disk('local')->put('protocols/private.pdf', 'private-pdf');
    $this->get(route('requests.protocol', $request->id))->assertRedirect(route('login'));
    $this->actingAs(makeUserWithRole('accountant'))->get(route('requests.protocol', $request->id))->assertNotFound();
    $this->actingAs($user)->get(route('requests.protocol', $request->id))->assertDownload();
    $this->delete(route('requests.delete', $request->id))->assertSessionHasNoErrors();
    $this->get(route('requests.protocol', $request->id))->assertNotFound();
    Storage::disk('local')->assertExists('protocols/private.pdf');
});

test('legacy files move privately without content loss and migration can be rerun', function () {
    Storage::fake('public');
    Storage::fake('local');
    Storage::disk('public')->put('protocols/old.pdf', 'old-pdf');
    $this->artisan('requests:privatize-files')->assertSuccessful();
    Storage::disk('public')->assertMissing('protocols/old.pdf');
    expect(Storage::disk('local')->get('protocols/old.pdf'))->toBe('old-pdf');
    $this->artisan('requests:privatize-files')->assertSuccessful();
});

test('legacy migration refuses to overwrite a different private file', function () {
    Storage::fake('public');
    Storage::fake('local');
    Storage::disk('public')->put('protocols/old.pdf', 'public-content');
    Storage::disk('local')->put('protocols/old.pdf', 'private-content');
    $this->artisan('requests:privatize-files')->assertFailed();
    expect(Storage::disk('public')->get('protocols/old.pdf'))->toBe('public-content')
        ->and(Storage::disk('local')->get('protocols/old.pdf'))->toBe('private-content');
});

test('ordinary approval freezes its signature and duplicate approval creates no duplicate tranche', function () {
    Storage::fake('public');
    Storage::fake('local');
    $user = makeUserWithRole('approver', ['signature_path' => 'signatures/current.png']);
    Storage::disk('local')->put('signatures/current.png', 'first-signature');
    $request = securedRequest(makeUserWithRole('requester'), ['status' => 'pending_approval']);
    $user->projects()->attach($request->project_id);
    $approval = $request->approvals()->create(['approver_id' => $user->id, 'level' => 1, 'status' => 'pending']);
    app(ApprovalWorkflow::class)->approve($request->id, $user, null);
    Storage::disk('local')->put('signatures/current.png', 'replacement-signature');
    expect(Storage::disk('local')->get($approval->fresh()->signature_snapshot_path))->toBe('first-signature');
    try {
        app(ApprovalWorkflow::class)->approve($request->id, $user, null);
    } catch (\Illuminate\Validation\ValidationException) { /* Expected stale action. */
    }
    expect($request->tranches()->count())->toBe(1);
});

test('adding a signer before final approval keeps the request pending and rejects a duplicate', function () {
    $user = makeUserWithRole('approver');
    $next = makeUserWithRole('approver');
    $request = securedRequest(makeUserWithRole('requester'), ['status' => 'pending_approval']);
    $user->projects()->attach($request->project_id);
    $next->projects()->attach($request->project_id);
    $request->approvals()->create(['approver_id' => $user->id, 'level' => 1, 'status' => 'pending']);
    $this->actingAs($user)->post(route('requests.add-approver', $request->id), ['approver_id' => $next->id])->assertSessionHasNoErrors();
    $this->post(route('requests.add-approver', $request->id), ['approver_id' => $next->id])->assertSessionHasErrors('error');
    app(ApprovalWorkflow::class)->approve($request->id, $user, null);
    expect($request->fresh()->status)->toBe('pending_approval')->and($request->fresh()->expected_approval_level)->toBe(2)
        ->and($request->tranches()->count())->toBe(0);
});

test('supply director dashboard exposes all projects and request types', function () {
    $supplier = makeUserWithRole('supplier');
    $owner = makeUserWithRole('requester');
    $project = makeProject('Assigned supplier project');
    $supplier->projects()->attach($project);
    $supply = securedRequest($owner, ['project_id' => $project->id, 'type' => 'supply']);
    $converted = securedRequest($owner, ['project_id' => $project->id, 'supply_applicant_id' => $owner->id]);
    $own = securedRequest($supplier, ['project_id' => $project->id]);
    securedRequest($owner, ['project_id' => $project->id]);
    securedRequest($owner, ['project_id' => $project->id, 'type' => 'management_document']);
    securedRequest($owner, ['type' => 'supply']);

    $this->actingAs($supplier)->get(route('requests'))->assertOk()->assertInertia(fn ($page) => $page
        ->has('requests.data', 6));
    foreach ([$supply, $converted, $own] as $visible) {
        $this->get(route('requests.detail', $visible->id))->assertOk();
    }
    $this->get(route('dashboard'))->assertOk()->assertInertia(fn ($page) => $page
        ->where('stats.totalRequests', 6)
        ->where('currencySums.UZS', fn ($sum) => (float) $sum === 5000.0)
        ->has('recentRequests', 5)
        ->where('projectStats', fn ($stats) => collect($stats)->firstWhere('id', $project->id)['total_count'] === 5));
});

test('supply director can download expenses and management documents', function () {
    Storage::fake('local');
    $supplier = makeUserWithRole('supplier');
    $owner = makeUserWithRole('requester');
    $project = makeProject('Private project');
    $supplier->projects()->attach($project);
    Storage::disk('local')->put('protocols/secret.pdf', 'private pdf');
    Storage::disk('local')->put('attachments/secret.pdf', 'private attachment');
    foreach (['approval', 'management_document'] as $type) {
        $request = securedRequest($owner, ['project_id' => $project->id, 'type' => $type,
            'pdf_url' => 'protocols/secret.pdf', 'preview_pdf_path' => 'protocols/secret.pdf']);
        $attachment = $request->attachments()->create(['file_type' => 'document', 'file_path' => 'attachments/secret.pdf']);
        $request->items()->create(['name' => 'Private item', 'quantity' => 1, 'price_per_unit' => 1000, 'total_price' => 1000]);
        $urls = [route('requests.detail', $request->id), route('requests.export-items', $request->id),
            route('requests.attachment.download', [$request->id, $attachment->id])];
        $downloads = [route('requests.attachment.download', [$request->id, $attachment->id])];
        if ($type === 'management_document') {
            $downloads[] = route('requests.document.preview', $request->id);
            $downloads[] = route('requests.document.final', $request->id);
        } else {
            $downloads[] = route('requests.protocol', $request->id);
        }
        $this->actingAs($supplier);
        foreach (array_unique(array_merge($urls, $downloads)) as $url) {
            $this->get($url)->assertOk();
        }
        $this->actingAs(makeUserWithRole('admin'));
        foreach ($downloads as $url) {
            $this->get($url)->assertDownload();
        }
    }
});

test('a new payment promise cannot hide the original overdue obligation', function () {
    $user = makeUserWithRole('accountant');
    $request = securedRequest(makeUserWithRole('requester'));
    $user->projects()->attach($request->project_id);
    $original = today()->subDays(2)->toDateString();
    $tranche = $request->tranches()->create(['amount' => 1000, 'percent' => 100, 'status' => 'pending', 'due_date' => $original]);
    $url = route('finance.tranches.update', [$request->id, $tranche->id]);
    $this->actingAs($user)->put($url, ['status' => 'pending', 'due_date' => today()->addDays(5)->format('d.m.Y')])->assertSessionHasErrors('due_date');
    $promise = today()->addDays(5)->toDateString();
    $this->put($url, ['status' => 'pending', 'promised_date' => $promise])->assertSessionHasErrors('reschedule_reason');
    $this->put($url, ['status' => 'pending', 'promised_date' => $promise, 'reschedule_reason' => 'Ожидаем поступление средств'])->assertSessionHasNoErrors();
    expect($tranche->fresh()->due_date->toDateString())->toBe($original)
        ->and($tranche->fresh()->promised_date->toDateString())->toBe($promise)
        ->and(ApprovalRequest::overduePayment()->whereKey($request->id)->exists())->toBeTrue();
    $this->assertDatabaseHas('tranche_deadline_changes', ['request_tranche_id' => $tranche->id, 'user_id' => $user->id, 'promised_date' => $promise, 'reason' => 'Ожидаем поступление средств']);
    $metrics = app(\App\Services\DashboardMetrics::class)->forUser($user);
    expect((float) $metrics['financialSummary']['UZS']['overdue'])->toBe(1000.0);
    $this->put($url, ['status' => 'pending', 'promised_date' => null])->assertSessionHasErrors('reschedule_reason');
    $this->put($url, ['status' => 'paid'])->assertSessionHasNoErrors();
    expect($tranche->fresh()->paid_at)->not->toBeNull()->and($request->fresh()->status)->toBe('paid');
    $this->put($url, ['status' => 'paid', 'promised_date' => null, 'reschedule_reason' => 'Reset'])->assertSessionHasErrors('status');
});
