<?php

use App\Models\ApprovalRequest;

beforeEach(function () {
    seedRoles();
    $this->withoutVite();
    $this->user = makeUserWithRole('superadmin');
    $this->project = makeProject();
    $this->actingAs($this->user);
    $attributes = ['applicant_id' => $this->user->id, 'supply_applicant_id' => $this->user->id,
        'project_id' => $this->project->id, 'type' => 'approval', 'name' => 'Кабель Cable', 'description' => 'Материал',
        'supplier_name' => 'Vendor', 'currency' => 'UZS', 'total_price' => 100, 'transaction_type' => 'bank', 'expected_approval_level' => 1];
    $this->paid = ApprovalRequest::create($attributes + ['status' => 'approved']);
    $this->pending = ApprovalRequest::create($attributes + ['status' => 'pending_approval']);
    $this->paid->approvals()->create(['approver_id' => $this->user->id, 'level' => 1, 'status' => 'approved', 'action_date' => now()]);
    $this->pending->approvals()->create(['approver_id' => $this->user->id, 'level' => 1, 'status' => 'pending']);
});

dataset('request search pages', [
    ['requests', 'requests.data', 2], ['finance.requests', 'requests.data', 1],
    ['supply.requests', 'requests.data', 2], ['my-requests', 'requests.data', 2],
    ['my-approvals', 'active.data', 1], ['my-approvals', 'history.data', 1],
]);

test('request lists search text without sending it to a numeric ID comparison', function ($route, $prop, $count) {
    $this->get(route($route, ['search' => 'Кабель']))->assertOk()->assertInertia(fn ($page) => $page->has($prop, $count));
    $this->get(route($route, ['search' => 'cable']))->assertOk()->assertInertia(fn ($page) => $page->has($prop, $count));
})->with('request search pages');

test('request lists accept exact numbers and hash prefixed request numbers', function ($route, $prop) {
    $id = $prop === 'active.data' ? $this->pending->id : $this->paid->id;
    foreach ([(string) $id, '#'.$id, '00'.$id] as $search) {
        $this->get(route($route, ['search' => $search]))->assertOk()->assertInertia(fn ($page) => $page->has($prop, 1)->where($prop.'.0.id', $id));
    }
})->with('request search pages');

test('out of range and non integer numbers do not produce a server error', function ($route, $prop) {
    foreach (['9223372036854775808', str_repeat('9', 100), '12.5', '1e100', "не найдено' OR 1=1 --"] as $search) {
        $this->get(route($route, ['search' => $search]))->assertOk()->assertInertia(fn ($page) => $page->has($prop, 0));
    }
})->with('request search pages');

test('malformed search input produces validation errors instead of a server error', function ($route) {
    foreach ([['nested'], str_repeat('x', 201), "bad\0text"] as $search) {
        $this->getJson(route($route, ['search' => $search]))->assertUnprocessable()->assertJsonValidationErrors('search');
    }
})->with('request search pages');

test('numeric and text search stay inside the existing access and status filters', function () {
    $user = makeUserWithRole('accountant');
    $user->projects()->attach($this->project);
    $other = ApprovalRequest::create(['applicant_id' => $this->user->id, 'project_id' => makeProject()->id,
        'type' => 'approval', 'name' => 'Private', 'status' => 'approved', 'currency' => 'UZS', 'transaction_type' => 'bank', 'total_price' => 100]);
    $this->actingAs($user);
    foreach (['requests', 'finance.requests'] as $route) {
        foreach ([(string) $other->id, 'Private', (string) $this->pending->id] as $search) {
            $this->get(route($route, ['search' => $search]))->assertOk()->assertInertia(fn ($page) => $page->has('requests.data', 0));
        }
    }
});

test('supplier and description searches retain their existing fields', function () {
    foreach (['requests', 'finance.requests', 'my-requests'] as $route) {
        $this->get(route($route, ['search' => 'Vendor']))->assertOk()->assertInertia(fn ($page) => $page->has('requests.data', $route === 'finance.requests' ? 1 : 2));
    }
    $this->get(route('requests', ['search' => 'Материал']))->assertOk()->assertInertia(fn ($page) => $page->has('requests.data', 2));
});
