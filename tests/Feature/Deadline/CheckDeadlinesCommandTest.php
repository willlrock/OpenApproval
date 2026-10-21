<?php

use App\Models\ApprovalRequest;
use Carbon\Carbon;

beforeEach(function () {
    seedRoles();
    $this->bot = fakeBot();
});

test('command runs cleanly on empty database', function () {
    $this->artisan('deadlines:check', ['--sync' => true])->assertExitCode(0);
    expect($this->bot->messages)->toBeEmpty();
});

test('--stage filter restricts to a single job', function () {
    $accountant = makeUserWithRole('accountant');
    $project    = makeProject();
    $accountant->projects()->attach($project->id);
    $applicant  = makeUserWithRole('requester');

    ApprovalRequest::create([
        'type' => 'approval',
        'project_id' => $project->id,
        'applicant_id' => $applicant->id,
        'name' => 'Overdue Only',
        'description' => '—',
        'currency' => 'USD',
        'price_per_unit' => 10,
        'quantity' => 1,
        'total_price' => 10,
        'transaction_type' => 'bank',
        'supplier_name' => '—',
        'status' => 'approved',
        'expected_approval_level' => 1,
        'deadline_date' => Carbon::now()->subDay(),
    ]);

    // Только overdue должен сработать
    $this->artisan('deadlines:check', ['--sync' => true, '--stage' => 'overdue'])
        ->assertExitCode(0);

    expect($this->bot->messagesTo($accountant->telegram_id))->not->toBeEmpty();
});

test('unknown stage returns failure', function () {
    $this->artisan('deadlines:check', ['--sync' => true, '--stage' => 'nonsense'])
        ->assertExitCode(1);
});
