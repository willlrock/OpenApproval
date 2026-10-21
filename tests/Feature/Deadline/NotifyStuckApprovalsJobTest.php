<?php

use App\Jobs\Deadline\NotifyStuckApprovalsJob;
use App\Models\ApprovalRequest;
use App\Models\RequestApproval;
use App\Services\DeadlineNotifier;
use Carbon\Carbon;

beforeEach(function () {
    seedRoles();
    $this->bot      = fakeBot();
    $this->notifier = app(DeadlineNotifier::class);
});

function makePendingApproval(int $hoursAgo, ?\Carbon\Carbon $notifiedAt = null): array
{
    $project   = makeProject();
    $applicant = makeUserWithRole('requester');
    $approver  = makeUserWithRole('approver');
    $approver->projects()->attach($project);

    $request = ApprovalRequest::create([
        'type' => 'approval',
        'project_id' => $project->id,
        'applicant_id' => $applicant->id,
        'name' => 'Test Stuck',
        'description' => '—',
        'currency' => 'USD',
        'price_per_unit' => 10,
        'quantity' => 1,
        'total_price' => 10,
        'transaction_type' => 'bank',
        'supplier_name' => '—',
        'status' => 'pending_approval',
        'expected_approval_level' => 1,
    ]);

    $approval = RequestApproval::create([
        'approval_request_id' => $request->id,
        'approver_id' => $approver->id,
        'level' => 1,
        'status' => 'pending',
        'notified_at' => $notifiedAt,
    ]);

    // обходим updated_at — выставляем created_at вручную
    $approval->forceFill(['created_at' => Carbon::now()->subHours($hoursAgo)])->saveQuietly();

    return ['request' => $request, 'approver' => $approver, 'approval' => $approval];
}

test('young pending approval (<24h) is skipped', function () {
    [$request, $approver, $approval] = array_values(makePendingApproval(hoursAgo: 5));

    (new NotifyStuckApprovalsJob())->handle($this->notifier);

    expect($this->bot->messages)->toBeEmpty();
    expect($approval->fresh()->notified_at)->toBeNull();
});

test('stuck approval (>24h) reminds approver and sets notified_at', function () {
    ['approval' => $approval, 'approver' => $approver] = makePendingApproval(hoursAgo: 30);

    (new NotifyStuckApprovalsJob())->handle($this->notifier);

    expect($this->bot->messagesTo($approver->telegram_id))->toHaveCount(1);
    expect($approval->fresh()->notified_at)->not->toBeNull();
});

test('escalation (>48h) also pings admins', function () {
    ['approver' => $approver] = makePendingApproval(hoursAgo: 60);
    $admin = makeUserWithRole('admin');

    (new NotifyStuckApprovalsJob())->handle($this->notifier);

    // Approver получает напоминание + admin получает эскалацию
    expect($this->bot->messagesTo($approver->telegram_id))->toHaveCount(1);
    expect($this->bot->messagesTo($admin->telegram_id))->toHaveCount(1);
});

test('cooldown prevents re-notification within window', function () {
    // notified 2 часа назад (cooldown 12ч) — должен быть пропущен
    ['approval' => $approval, 'approver' => $approver] = makePendingApproval(
        hoursAgo: 30,
        notifiedAt: Carbon::now()->subHours(2),
    );

    (new NotifyStuckApprovalsJob())->handle($this->notifier);

    expect($this->bot->messagesTo($approver->telegram_id))->toBeEmpty();
});

test('cooldown allows re-notification after window passes', function () {
    // notified 13 часов назад (cooldown 12ч) — должен быть пингнут заново
    ['approver' => $approver, 'approval' => $approval] = makePendingApproval(
        hoursAgo: 30,
        notifiedAt: Carbon::now()->subHours(13),
    );

    (new NotifyStuckApprovalsJob())->handle($this->notifier);

    expect($this->bot->messagesTo($approver->telegram_id))->toHaveCount(1);
    // notified_at должен обновиться
    expect($approval->fresh()->notified_at->diffInMinutes(Carbon::now()))->toBeLessThan(2);
});
