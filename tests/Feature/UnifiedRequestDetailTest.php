<?php

use App\Models\ApprovalRequest;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    seedRoles();
    $this->withoutVite();
    fakeBot();
    $this->owner = makeUserWithRole('requester');
    $this->project = makeProject('Unified request project');
    $this->request = ApprovalRequest::create([
        'applicant_id' => $this->owner->id, 'project_id' => $this->project->id,
        'type' => 'approval', 'status' => 'approved', 'name' => 'Equipment',
        'currency' => 'UZS', 'transaction_type' => 'bank', 'total_price' => 200,
        'revision' => 1,
    ]);
    $this->pending = $this->request->tranches()->create(['amount' => 100, 'status' => 'pending', 'comment' => 'Visible payment details']);
    $this->paid = $this->request->tranches()->create(['amount' => 100, 'status' => 'paid']);
});

test('read access preserves payment details without granting payment or supply actions', function (string $role) {
    $user = $role === 'requester' ? $this->owner : makeUserWithRole($role);
    $this->actingAs($user)->get(route('requests.detail', $this->request))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Dashboard/RequestDetail')
            ->where('request.can_manage_payments', false)->where('request.can_manage_supply', false)
            ->where('request.tranches.0.comment', 'Visible payment details')
            ->where('request.tranches.0.can_manage', false));
    $this->put(route('finance.tranches.update', [$this->request, $this->pending]), ['status' => 'paid'])->assertForbidden();
    expect($this->pending->fresh()->status)->toBe('pending');
})->with(['requester', 'supplier']);

test('payment buttons respect project payment method and paid tranche restrictions', function (string $role, string $method, bool $assigned, bool $allowed) {
    $actor = makeUserWithRole($role);
    if ($assigned) {
        $actor->projects()->attach($this->project);
    }
    $this->request->update(['transaction_type' => $method, 'applicant_id' => $actor->id]);
    $this->actingAs($actor)->get(route('requests.detail', $this->request))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('request.can_manage_payments', $allowed)
            ->where('request.tranches.0.can_manage', $allowed)->where('request.tranches.1.can_manage', false));
})->with([
    ['accountant', 'bank', true, true], ['accountant', 'cash', true, false],
    ['treasurer', 'cash', true, true], ['treasurer', 'bank', false, false],
    ['admin', 'bank', false, true], ['superadmin', 'cash', false, true],
]);

test('supply buttons require an active assigned project and pending supply state', function (bool $assigned, bool $active, string $status, bool $allowed) {
    $actor = makeUserWithRole('supplier');
    $this->project->update(['is_active' => $active]);
    if ($assigned) {
        $actor->projects()->attach($this->project);
    }
    $this->request->update(['type' => 'supply', 'status' => $status]);
    $this->actingAs($actor)->get(route('requests.detail', $this->request))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('request.can_manage_supply', $allowed));
})->with([
    [true, true, 'pending_supply', true], [false, true, 'pending_supply', false],
    [true, false, 'pending_supply', false], [true, true, 'approved', false],
]);

test('legacy detail links open the correct unified section and retain list filters', function () {
    $actor = makeUserWithRole('treasurer');
    $actor->projects()->attach($this->project);
    $back = '/finance/requests?status=approved&search=Equipment&page=2';
    $this->actingAs($actor)->get(route('finance.requests.detail', ['id' => $this->request->id, 'back' => $back]))
        ->assertRedirect(route('requests.detail', ['id' => $this->request->id, 'section' => 'payments', 'back' => $back]));
    $this->get(route('requests.detail', ['id' => $this->request->id, 'section' => 'payments', 'back' => $back]))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('section', 'payments')->where('back_url', $back));
    $this->get(route('requests.detail', ['id' => $this->request->id, 'section' => 'supply', 'back' => '//example.com']))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('section', 'overview')->where('back_url', '/dashboard/requests'));
});

test('authorized payment action from the unified card updates the correct tranche and locks paid actions', function () {
    $actor = makeUserWithRole('treasurer');
    $actor->projects()->attach($this->project);
    $url = route('requests.detail', ['id' => $this->request->id, 'section' => 'payments']);
    $this->actingAs($actor)->from($url)->put(route('finance.tranches.update', [$this->request, $this->pending]), ['status' => 'paid'])
        ->assertRedirect($url)->assertSessionHasNoErrors();
    expect($this->request->fresh()->status)->toBe('paid')->and($this->pending->fresh()->paid_at)->not->toBeNull();
    $this->get($url)->assertInertia(fn (AssertableInertia $page) => $page->where('request.tranches.0.can_manage', false)
        ->where('request.tranches.1.can_manage', false));
});
