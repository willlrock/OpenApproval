<?php

use App\Jobs\Deadline\NotifyOverdueRequestsJob;
use App\Jobs\Deadline\NotifySupplyDeadlinesJob;
use App\Models\ApprovalRequest;
use App\Models\User;
use App\Services\DeadlineNotifier;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    seedRoles();
    $this->bot = fakeBot();
});

test('deadline delivery rechecks current account state instead of trusting a previously loaded user', function (string $state) {
    $user = User::factory()->create(['telegram_id' => 991120]);
    match ($state) {
        'disabled' => DB::table('users')->where('id', $user->id)->update(['is_active' => false]),
        'deleted' => $user->delete(),
        default => DB::table('users')->where('id', $user->id)->update(['account_status' => $state]),
    };

    expect(app(DeadlineNotifier::class)->send($user, 'bot.dl_supply_warn_applicant', [
        'id' => 42, 'name' => 'Private purchase', 'date' => '16.10.2026', 'days' => 1,
    ]))->toBeFalse();
    expect($this->bot->messages)->toBeEmpty();
})->with(['disabled', 'pending', 'rejected', 'deleted']);

test('delivery uses the current Telegram link for an approved account', function () {
    $user = User::factory()->create(['telegram_id' => 991121]);
    DB::table('users')->where('id', $user->id)->update(['telegram_id' => 991122]);

    expect(app(DeadlineNotifier::class)->send($user, 'bot.dl_supply_warn_applicant', [
        'id' => 42, 'name' => 'Purchase', 'date' => '16.10.2026', 'days' => 1,
    ]))->toBeTrue();
    expect($this->bot->messagesTo(991121))->toBeEmpty()
        ->and($this->bot->messagesTo(991122))->toHaveCount(1);
});

test('supply warning and overdue jobs skip inactive applicants while notifying active suppliers', function (bool $overdue, string $state) {
    $author = User::factory()->create(['telegram_id' => 991123,
        'is_active' => $state !== 'disabled', 'account_status' => $state === 'disabled' ? 'approved' : $state]);
    $supplier = User::factory()->role('supplier')->create(['telegram_id' => 991124]);
    $request = ApprovalRequest::create([
        'applicant_id' => $author->id, 'project_id' => makeProject()->id,
        'type' => 'approval', 'name' => 'Private purchase', 'status' => 'approved',
        'deadline_from_supplier' => $overdue ? today()->subDay() : today()->addDay(),
    ]);

    $job = $overdue ? new NotifyOverdueRequestsJob : new NotifySupplyDeadlinesJob;
    $job->handle(app(DeadlineNotifier::class));

    expect($this->bot->messagesTo($author->telegram_id))->toBeEmpty()
        ->and($this->bot->messagesTo($supplier->telegram_id))->toHaveCount(1);
    if ($overdue) {
        expect($request->fresh()->is_overdue)->toBeTrue();
    }
})->with([
    [false, 'disabled'], [true, 'disabled'],
    [false, 'pending'], [true, 'pending'],
    [false, 'rejected'], [true, 'rejected'],
]);
