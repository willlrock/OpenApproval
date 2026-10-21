<?php

use App\Jobs\Deadline\NotifyOverdueRequestsJob;
use App\Jobs\Deadline\NotifyStuckApprovalsJob;
use App\Jobs\Deadline\NotifySupplyDeadlinesJob;
use App\Models\ApprovalRequest;
use App\Models\RequestApproval;
use App\Services\DashboardMetrics;
use App\Services\DeadlineNotifier;
use App\Services\TrancheWorkflow;

beforeEach(function () {
    seedRoles();
    $this->withoutVite();
    $this->bot = fakeBot();
    $this->travelTo(now()->setDate(2026, 9, 8)->setTime(12, 0));
    $this->owner = makeUserWithRole('requester');
    $this->project = makeProject('Metrics');
});

function metricRequest(array $attributes = []): ApprovalRequest
{
    return ApprovalRequest::create(array_merge([
        'applicant_id' => test()->owner->id, 'project_id' => test()->project->id,
        'type' => 'approval', 'name' => 'Metrics request', 'status' => 'approved',
        'currency' => 'UZS', 'transaction_type' => 'bank', 'total_price' => 100,
        'expected_approval_level' => 1,
    ], $attributes));
}

test('dashboard counts partial payments and paid requests separately without mixing currencies or documents', function () {
    $admin = makeUserWithRole('admin');
    $partial = metricRequest(['deadline_date' => today()->subDay()]);
    $partial->tranches()->create(['amount' => 40, 'status' => 'paid']);
    $partial->tranches()->create(['amount' => 20, 'status' => 'pending', 'due_date' => today()->subDay()]);
    $partial->tranches()->create(['amount' => 40, 'status' => 'pending', 'due_date' => today()->addDay()]);
    $paid = metricRequest(['status' => 'paid', 'total_price' => 50]);
    $paid->tranches()->create(['amount' => 50, 'status' => 'paid']);
    metricRequest(['currency' => 'USD', 'total_price' => '10.25']);
    metricRequest(['type' => 'management_document', 'total_price' => 9999]);
    metricRequest(['status' => 'rejected', 'total_price' => 8888]);
    metricRequest(['total_price' => 7777])->delete();
    $this->actingAs($admin)->get(route('dashboard'))->assertOk()->assertInertia(fn ($page) => $page
        ->where('stats.paidRequests', 1)
        ->where('financialSummary.UZS.approved', 150)
        ->where('financialSummary.UZS.paid', 90)
        ->where('financialSummary.UZS.remaining', 60)
        ->where('financialSummary.UZS.overdue', 20)
        ->where('financialSummary.USD.approved', 10.25)
        ->where('attention.overduePayment', 1)
        ->where('paymentDataIssues', 0));
    $this->get(route('requests', ['attention' => 'overdue-payment']))->assertInertia(fn ($page) => $page
        ->has('requests.data', 1)->where('requests.data.0.id', $partial->id));
});

test('supply directors see metrics across request types', function () {
    $supplier = makeUserWithRole('supplier');
    $supplier->projects()->attach($this->project);
    metricRequest(['deadline_date' => today()->subDay()]);
    metricRequest(['type' => 'management_document', 'deadline_date' => today()->subDay()]);
    $supply = metricRequest(['type' => 'supply', 'deadline_date' => today()->subDay()]);
    $this->actingAs($supplier)->get(route('dashboard'))->assertInertia(fn ($page) => $page
        ->where('financialSummary.UZS.approved', 200)->where('attention.overduePayment', 2));
    $this->get(route('requests', ['attention' => 'overdue-payment']))->assertInertia(fn ($page) => $page
        ->has('requests.data', 2));
});

test('today is not overdue and undated tranches inherit the request deadline', function () {
    $today = metricRequest(['deadline_date' => today()]);
    $late = metricRequest(['deadline_date' => today()->subDay(), 'payment_commitment_date' => today()->addDay()]);
    $late->tranches()->create(['amount' => 100, 'status' => 'pending']);
    expect(ApprovalRequest::overduePayment()->pluck('id')->all())->toBe([$late->id]);
    $metrics = app(DashboardMetrics::class)->forUser(makeUserWithRole('admin'));
    expect($metrics['financialSummary']['UZS']['overdue'])->toEqual(100);
});

test('paid but undelivered requests remain in upcoming and overdue delivery control', function () {
    $late = metricRequest(['status' => 'paid', 'deadline_from_supplier' => today()->subDay()]);
    $soon = metricRequest(['status' => 'paid', 'deadline_from_supplier' => today()->addDay()]);
    metricRequest(['status' => 'paid', 'deadline_from_supplier' => today()->subDay(), 'factual_delivery_date' => today()]);
    $notifier = app(DeadlineNotifier::class);
    (new NotifySupplyDeadlinesJob)->handle($notifier);
    expect($this->bot->messagesTo($this->owner->telegram_id))->toHaveCount(1);
    (new NotifyOverdueRequestsJob)->handle($notifier);
    expect($this->bot->messagesTo($this->owner->telegram_id))->toHaveCount(2);
    expect(ApprovalRequest::overdueSupply()->pluck('id')->all())->toBe([$late->id]);
    $this->actingAs(makeUserWithRole('admin'))->get(route('requests', ['attention' => 'overdue-supply']))
        ->assertInertia(fn ($page) => $page->has('requests.data', 1)->where('requests.data.0.id', $late->id));
});

test('second approver time starts when the previous approver finishes and dashboard ignores reminder cooldown', function () {
    $first = makeUserWithRole('approver');
    $second = makeUserWithRole('approver');
    $request = metricRequest(['status' => 'pending_approval', 'expected_approval_level' => 2]);
    $second->projects()->attach($request->project_id);
    $request->approvals()->create(['approver_id' => $first->id, 'level' => 1, 'status' => 'approved', 'action_date' => now()->subHour()]);
    $approval = $request->approvals()->create(['approver_id' => $second->id, 'level' => 2, 'status' => 'pending']);
    $approval->forceFill(['created_at' => now()->subDays(5)])->saveQuietly();
    expect(RequestApproval::stuck()->count())->toBe(0);
    (new NotifyStuckApprovalsJob)->handle(app(DeadlineNotifier::class));
    expect($this->bot->messages)->toBeEmpty();
    $this->travel(25)->hours();
    (new NotifyStuckApprovalsJob)->handle(app(DeadlineNotifier::class));
    expect($this->bot->messagesTo($second->telegram_id))->toHaveCount(1);
    $metrics = app(DashboardMetrics::class)->forUser(makeUserWithRole('admin'));
    expect($metrics['attention']['stuckApproval'])->toBe(1);
    (new NotifyStuckApprovalsJob)->handle(app(DeadlineNotifier::class));
    expect($this->bot->messagesTo($second->telegram_id))->toHaveCount(1);
});

test('payment writes a dedicated date while historic paid rows remain undated', function () {
    $admin = makeUserWithRole('admin');
    $request = metricRequest();
    $old = $request->tranches()->create(['amount' => 40, 'status' => 'paid']);
    $new = $request->tranches()->create(['amount' => 60, 'status' => 'pending']);
    app(TrancheWorkflow::class)->update($admin, $request->id, $new->id, ['status' => 'paid']);
    expect($new->fresh()->paid_at->equalTo(now()))->toBeTrue()
        ->and($old->fresh()->paid_at)->toBeNull()->and($request->fresh()->status)->toBe('paid');
});

test('overdue schedule generates one notice per tranche only for assigned finance users', function () {
    $related = makeUserWithRole('accountant');
    $related->projects()->attach($this->project);
    $unrelated = makeUserWithRole('accountant');
    $request = metricRequest(['deadline_date' => today()->subDay()]);
    $request->tranches()->create(['amount' => 100, 'status' => 'pending', 'due_date' => today()->subDay()]);
    (new NotifyOverdueRequestsJob)->handle(app(DeadlineNotifier::class));
    expect($this->bot->messagesTo($related->telegram_id))->toHaveCount(1)
        ->and($this->bot->messagesTo($unrelated->telegram_id))->toBeEmpty();
});

test('inconsistent legacy paid records are flagged rather than inventing tranche payments', function () {
    metricRequest(['status' => 'paid']);
    $metrics = app(DashboardMetrics::class)->forUser(makeUserWithRole('admin'));
    expect($metrics['paymentDataIssues'])->toBe(1)
        ->and($metrics['financialSummary']['UZS']['paid'])->toEqual(0);
});

test('dashboard filters scope every metric and allow an end date without a start date', function () {
    $admin = makeUserWithRole('admin');
    $r = metricRequest();
    $r->forceFill(['created_at' => '2026-09-01 12:00:00'])->save();
    metricRequest()->forceFill(['created_at' => '2026-08-01 12:00:00'])->save();
    metricRequest(['project_id' => makeProject('Other')->id]);
    $this->actingAs($admin)->get('/dashboard?project_id='.$this->project->id.'&date_from=2026-09-01&date_to=2026-09-08')->assertOk()->assertInertia(fn ($p) => $p->where('stats.totalRequests', 1)->where('financialSummary.UZS.approved', 100)->where('projectStats.0.total_count', 1)->has('recentRequests', 1));
    $this->get('/dashboard?date_to=2026-09-08')->assertOk();
    $this->getJson('/dashboard?date_from=2026-09-08&date_to=2026-01-01')->assertUnprocessable();
});

test('payment activity uses paid date independently of request creation and reports unknown dates', function () {
    $r = metricRequest();
    $r->forceFill(['created_at' => '2026-01-01'])->save();
    $r->tranches()->create(['amount' => 30, 'status' => 'paid', 'paid_at' => '2026-09-02 10:00:00']);
    $r->tranches()->create(['amount' => 20, 'status' => 'paid', 'paid_at' => '2026-08-02 10:00:00']);
    $r->tranches()->create(['amount' => 10, 'status' => 'paid']);
    $data = app(DashboardMetrics::class)->forUser(makeUserWithRole('admin'), ['date_from' => '2026-09-01', 'date_to' => '2026-09-08']);
    expect($data['financialSummary'])->toBe([])->and((float) $data['paymentActivity']['UZS'])->toBe(30.0)->and($data['undatedPayments'])->toBe(1);
});

test('empty dashboard dates are omitted from drill down filters', function () {
    $this->actingAs(makeUserWithRole('admin'))->get('/dashboard?project_id='.$this->project->id.'&date_from=&date_to=')->assertOk()->assertInertia(fn ($p) => $p->where('filters', ['project_id' => (string) $this->project->id]));
});


test('missing currency has the same readable key in obligations and actual payments', function () {
    $request = metricRequest(['currency' => null, 'status' => 'paid', 'total_price' => 100]);
    $request->tranches()->create(['amount' => 100, 'percent' => 100, 'status' => 'paid', 'paid_at' => now()]);
    $metrics = app(DashboardMetrics::class)->forUser(makeUserWithRole('admin'));
    expect(array_keys($metrics['financialSummary']))->toBe(['Без валюты'])
        ->and(array_keys($metrics['paymentActivity']))->toBe(['Без валюты']);
});
