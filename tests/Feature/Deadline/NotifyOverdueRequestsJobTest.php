<?php

use App\Jobs\Deadline\NotifyOverdueRequestsJob;
use App\Models\ApprovalRequest;
use App\Models\RequestTranche;
use App\Services\DeadlineNotifier;
use Carbon\Carbon;

beforeEach(function () {
    seedRoles();
    $this->bot      = fakeBot();
    $this->notifier = app(DeadlineNotifier::class);
    $this->project  = makeProject();
    $this->applicant = makeUserWithRole('requester');
});

function makeOverdueRequest(array $overrides = []): ApprovalRequest
{
    return ApprovalRequest::create(array_merge([
        'type' => 'approval',
        'project_id' => test()->project->id,
        'applicant_id' => test()->applicant->id,
        'name' => 'Overdue',
        'description' => '—',
        'currency' => 'USD',
        'price_per_unit' => 10,
        'quantity' => 1,
        'total_price' => 10,
        'transaction_type' => 'bank',
        'supplier_name' => '—',
        'status' => 'approved',
        'expected_approval_level' => 1,
    ], $overrides));
}

test('overdue payment marks is_overdue and notifies accountant + admins', function () {
    $accountant = makeUserWithRole('accountant');
    $accountant->projects()->attach($this->project->id);
    $admin      = makeUserWithRole('admin');

    $req = makeOverdueRequest([
        'deadline_date' => Carbon::now()->subDays(2),
    ]);

    (new NotifyOverdueRequestsJob())->handle($this->notifier);

    expect($req->fresh()->is_overdue)->toBeTrue();
    expect($this->bot->messagesTo($accountant->telegram_id))->toHaveCount(1);
    expect($this->bot->messagesTo($admin->telegram_id))->toHaveCount(1);
});

test('overdue supply pings applicant + supplier + admins', function () {
    $supplier = makeUserWithRole('supplier');
    $admin    = makeUserWithRole('admin');

    $req = makeOverdueRequest([
        'deadline_from_supplier' => Carbon::now()->subDays(3),
    ]);

    (new NotifyOverdueRequestsJob())->handle($this->notifier);

    expect($req->fresh()->is_overdue)->toBeTrue();
    expect($this->bot->messagesTo($supplier->telegram_id))->toHaveCount(1);
    expect($this->bot->messagesTo($this->applicant->telegram_id))->toHaveCount(1);
    expect($this->bot->messagesTo($admin->telegram_id))->toHaveCount(1);
});

test('payment commitment does not remove an unpaid debt from overdue control', function () {
    $accountant = makeUserWithRole('accountant');
    $accountant->projects()->attach($this->project->id);

    $req = makeOverdueRequest([
        'deadline_date' => Carbon::now()->subDays(2),
        'payment_commitment_date' => Carbon::now()->subDay(),
    ]);

    (new NotifyOverdueRequestsJob())->handle($this->notifier);

    expect($req->fresh()->is_overdue)->toBeTrue();
    expect($this->bot->messagesTo($accountant->telegram_id))->toHaveCount(1);
});

test('overdue tranche flags parent request as overdue', function () {
    $accountant = makeUserWithRole('accountant');
    $accountant->projects()->attach($this->project->id);

    $req = makeOverdueRequest();
    RequestTranche::create([
        'approval_request_id' => $req->id,
        'amount' => 50,
        'due_date' => Carbon::now()->subDays(2),
        'status' => 'pending',
    ]);

    (new NotifyOverdueRequestsJob())->handle($this->notifier);

    expect($req->fresh()->is_overdue)->toBeTrue();
    expect($this->bot->messagesTo($accountant->telegram_id))->toHaveCount(1);
});
