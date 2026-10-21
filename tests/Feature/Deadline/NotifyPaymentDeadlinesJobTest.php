<?php

use App\Jobs\Deadline\NotifyPaymentDeadlinesJob;
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

function makeApprovedRequest(array $overrides = []): ApprovalRequest
{
    return ApprovalRequest::create(array_merge([
        'type' => 'approval',
        'project_id' => test()->project->id,
        'applicant_id' => test()->applicant->id,
        'name' => 'Payment Test',
        'description' => '—',
        'currency' => 'USD',
        'price_per_unit' => 100,
        'quantity' => 1,
        'total_price' => 100,
        'transaction_type' => 'bank',
        'supplier_name' => '—',
        'status' => 'approved',
        'expected_approval_level' => 1,
    ], $overrides));
}

test('bank request near deadline pings only accountant', function () {
    $accountant = makeUserWithRole('accountant');
    $accountant->projects()->attach($this->project->id);
    $treasurer  = makeUserWithRole('treasurer');
    $treasurer->projects()->attach($this->project->id);
    $admin      = makeUserWithRole('admin');
    $superadmin = makeUserWithRole('superadmin');

    makeApprovedRequest([
        'transaction_type' => 'bank',
        'deadline_date' => Carbon::now()->addDay(),
    ]);

    (new NotifyPaymentDeadlinesJob())->handle($this->notifier);

    expect($this->bot->messagesTo($accountant->telegram_id))->toHaveCount(1);
    expect($this->bot->messagesTo($admin->telegram_id))->toBeEmpty();
    expect($this->bot->messagesTo($superadmin->telegram_id))->toBeEmpty();
    expect($this->bot->messagesTo($treasurer->telegram_id))->toBeEmpty();
});

test('cash request near deadline pings only treasurer', function () {
    $accountant = makeUserWithRole('accountant');
    $accountant->projects()->attach($this->project->id);
    $treasurer  = makeUserWithRole('treasurer');
    $treasurer->projects()->attach($this->project->id);
    $admin      = makeUserWithRole('admin');
    $superadmin = makeUserWithRole('superadmin');

    makeApprovedRequest([
        'transaction_type' => 'cash',
        'deadline_date' => Carbon::now()->addDay(),
    ]);

    (new NotifyPaymentDeadlinesJob())->handle($this->notifier);

    expect($this->bot->messagesTo($treasurer->telegram_id))->toHaveCount(1);
    expect($this->bot->messagesTo($admin->telegram_id))->toBeEmpty();
    expect($this->bot->messagesTo($superadmin->telegram_id))->toBeEmpty();
    expect($this->bot->messagesTo($accountant->telegram_id))->toBeEmpty();
});

test('request beyond warn window is skipped', function () {
    $accountant = makeUserWithRole('accountant');
    $accountant->projects()->attach($this->project->id);

    makeApprovedRequest([
        'deadline_date' => Carbon::now()->addDays(10), // далеко впереди
    ]);

    (new NotifyPaymentDeadlinesJob())->handle($this->notifier);

    expect($this->bot->messagesTo($accountant->telegram_id))->toBeEmpty();
});

test('payment commitment does not suppress an upcoming unpaid deadline', function () {
    $accountant = makeUserWithRole('accountant');
    $accountant->projects()->attach($this->project->id);

    makeApprovedRequest([
        'deadline_date' => Carbon::now()->addDay(),
        'payment_commitment_date' => Carbon::now()->subDay(),
    ]);

    (new NotifyPaymentDeadlinesJob())->handle($this->notifier);

    expect($this->bot->messagesTo($accountant->telegram_id))->toHaveCount(1);
});

test('tranche with due date in window pings accountant', function () {
    $accountant = makeUserWithRole('accountant');
    $accountant->projects()->attach($this->project->id);

    $req = makeApprovedRequest(['deadline_date' => Carbon::now()->addDays(20)]);
    RequestTranche::create([
        'approval_request_id' => $req->id,
        'amount' => 50,
        'due_date' => Carbon::now()->addDay(),
        'status' => 'pending',
    ]);

    (new NotifyPaymentDeadlinesJob())->handle($this->notifier);

    // 1 за заявку (если в окне) + 1 за транш. deadline 20 дней — в окно не попадёт, только транш.
    expect($this->bot->messagesTo($accountant->telegram_id))->toHaveCount(1);
});

test('tranche reminders reach only the project payment role', function (string $type, string $role) {
    $recipient = makeUserWithRole($role);
    $recipient->projects()->attach($this->project->id);
    $outsider = makeUserWithRole($role);
    $otherRole = makeUserWithRole($role === 'treasurer' ? 'accountant' : 'treasurer');
    $admin = makeUserWithRole('admin');
    $superadmin = makeUserWithRole('superadmin');
    foreach ([$otherRole, $admin, $superadmin] as $user) {
        $user->projects()->attach($this->project->id);
    }

    $req = makeApprovedRequest([
        'transaction_type' => $type,
        'deadline_date' => Carbon::now()->addDays(20),
    ]);
    RequestTranche::create([
        'approval_request_id' => $req->id,
        'amount' => 50,
        'due_date' => Carbon::now()->addDay(),
        'status' => 'pending',
    ]);

    (new NotifyPaymentDeadlinesJob())->handle($this->notifier);

    expect($this->bot->messagesTo($recipient->telegram_id))->toHaveCount(1);
    foreach ([$outsider, $otherRole, $admin, $superadmin] as $user) {
        expect($this->bot->messagesTo($user->telegram_id))->toBeEmpty();
    }
})->with([
    'cash' => ['cash', 'treasurer'],
    'bank' => ['bank', 'accountant'],
]);

test('paid tranche is skipped', function () {
    $accountant = makeUserWithRole('accountant');
    $accountant->projects()->attach($this->project->id);

    $req = makeApprovedRequest(['deadline_date' => Carbon::now()->addDays(20)]);
    RequestTranche::create([
        'approval_request_id' => $req->id,
        'amount' => 50,
        'due_date' => Carbon::now()->addDay(),
        'status' => 'paid',
    ]);

    (new NotifyPaymentDeadlinesJob())->handle($this->notifier);

    expect($this->bot->messagesTo($accountant->telegram_id))->toBeEmpty();
});
