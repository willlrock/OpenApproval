<?php

use App\Jobs\Deadline\NotifySupplyDeadlinesJob;
use App\Models\ApprovalRequest;
use App\Services\DeadlineNotifier;
use Carbon\Carbon;

beforeEach(function () {
    seedRoles();
    $this->bot      = fakeBot();
    $this->notifier = app(DeadlineNotifier::class);
});

test('supply deadline in window pings supplier role and applicant', function () {
    $supplier  = makeUserWithRole('supplier');
    $applicant = makeUserWithRole('requester');
    $project   = makeProject();

    ApprovalRequest::create([
        'type' => 'approval',
        'project_id' => $project->id,
        'applicant_id' => $applicant->id,
        'name' => 'Supply Test',
        'description' => '—',
        'currency' => 'USD',
        'price_per_unit' => 10,
        'quantity' => 1,
        'total_price' => 10,
        'transaction_type' => 'bank',
        'supplier_name' => '—',
        'status' => 'approved',
        'expected_approval_level' => 1,
        'deadline_from_supplier' => Carbon::now()->addDay(),
    ]);

    (new NotifySupplyDeadlinesJob())->handle($this->notifier);

    expect($this->bot->messagesTo($supplier->telegram_id))->toHaveCount(1);
    expect($this->bot->messagesTo($applicant->telegram_id))->toHaveCount(1);
});

test('factual delivery already set — supply notify is skipped', function () {
    $supplier  = makeUserWithRole('supplier');
    $applicant = makeUserWithRole('requester');
    $project   = makeProject();

    ApprovalRequest::create([
        'type' => 'approval',
        'project_id' => $project->id,
        'applicant_id' => $applicant->id,
        'name' => 'Already Delivered',
        'description' => '—',
        'currency' => 'USD',
        'price_per_unit' => 10,
        'quantity' => 1,
        'total_price' => 10,
        'transaction_type' => 'bank',
        'supplier_name' => '—',
        'status' => 'approved',
        'expected_approval_level' => 1,
        'deadline_from_supplier' => Carbon::now()->addDay(),
        'factual_delivery_date'  => Carbon::now()->subHours(2),
    ]);

    (new NotifySupplyDeadlinesJob())->handle($this->notifier);

    expect($this->bot->messages)->toBeEmpty();
});

test('supply deadline beyond window is skipped', function () {
    $supplier  = makeUserWithRole('supplier');
    $applicant = makeUserWithRole('requester');
    $project   = makeProject();

    ApprovalRequest::create([
        'type' => 'approval',
        'project_id' => $project->id,
        'applicant_id' => $applicant->id,
        'name' => 'Far Future',
        'description' => '—',
        'currency' => 'USD',
        'price_per_unit' => 10,
        'quantity' => 1,
        'total_price' => 10,
        'transaction_type' => 'bank',
        'supplier_name' => '—',
        'status' => 'approved',
        'expected_approval_level' => 1,
        'deadline_from_supplier' => Carbon::now()->addDays(15),
    ]);

    (new NotifySupplyDeadlinesJob())->handle($this->notifier);

    expect($this->bot->messages)->toBeEmpty();
});
