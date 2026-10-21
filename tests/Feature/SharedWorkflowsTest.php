<?php

use App\Models\ApprovalRequest;
use App\Services\ApprovalRequestAccess;
use App\Services\ManagementDocumentCreation;
use App\Services\RequestEffects;
use App\Services\SupplyWorkflow;
use App\Services\TrancheWorkflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use SergiX44\Nutgram\Nutgram;

beforeEach(function () {
    seedRoles();
    fakeBot();
    $this->withoutVite();
    Storage::fake('local');
    Storage::fake('public');
    $this->author = makeUserWithRole('requester');
    $this->director = makeUserWithRole('supplier');
    $this->signer = makeUserWithRole('approver');
    $this->project = makeProject();
    foreach ([$this->author, $this->director, $this->signer] as $actor) {
        $actor->projects()->attach($this->project);
    }
    $this->request = ApprovalRequest::create(['applicant_id' => $this->author->id, 'project_id' => $this->project->id,
        'type' => 'supply', 'status' => 'pending_supply', 'name' => 'Supply', 'expected_approval_level' => 1]);
    $this->item = $this->request->items()->create(['name' => 'Cable', 'quantity' => 1.5]);
    $this->data = ['revision' => 1, 'currency' => 'UZS', 'supplier_name' => 'Vendor', 'transaction_type' => 'bank',
        'approvers' => [$this->signer->id], 'deadline_from_supplier' => today()->addDays(10)->toDateString(),
        'deadline_date' => today()->addDays(5)->toDateString(),
        'items' => [['id' => $this->item->id, 'quantity' => 1.5, 'price_per_unit' => 100]],
        'tranches' => [['amount' => 50, 'due_date' => today()->addDay()->toDateString()], ['amount' => 100, 'due_date' => today()->addDays(5)->toDateString()]]];
});

function supplyDialog(): object
{
    return new class extends App\Telegram\Conversations\ConvertSupplyRequest
    {
        protected function end(): void {}

        protected function isCancelled(Nutgram $bot): bool
        {
            return false;
        }

        protected function askPrices(Nutgram $bot) {}

        protected function showItemsForEdit(Nutgram $bot, ApprovalRequest $request) {}

        protected function releaseCallbackClaim(Nutgram $bot, string $action): void {}

        public function submit(Nutgram $bot): void
        {
            $this->convert($bot);
        }
    };
}

test('supply director can read unassigned projects but cannot change their requests', function () {
    $outsider = makeUserWithRole('supplier');
    expect(app(ApprovalRequestAccess::class)->canView($this->request, $outsider))->toBeTrue();
    $this->actingAs($outsider)->get(route('supply.requests.detail', $this->request))
        ->assertRedirect(route('requests.detail', ['id' => $this->request->id, 'section' => 'supply', 'back' => '/supply/requests']));
    $this->get(route('supply.requests.export-items', $this->request))->assertOk();
    $this->postJson(route('supply.requests.cancel', $this->request), ['revision' => 1, 'reason' => 'No'])->assertForbidden();
    $this->postJson(route('supply.requests.convert', $this->request), $this->data)->assertForbidden();
    expect(fn () => app(SupplyWorkflow::class)->updateQuantity($this->request->id, $this->item->id, $outsider,
        ['revision' => 1, 'quantity' => 9]))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($this->request->fresh()->status)->toBe('pending_supply')
        ->and($this->request->fresh()->revision)->toBe(1)
        ->and((float) $this->item->fresh()->quantity)->toBe(1.5);
});

test('supply director dashboard counts include requests from unassigned projects', function () {
    $outsider = makeUserWithRole('supplier');
    $this->request->update(['type' => 'approval', 'supply_applicant_id' => $this->author->id]);
    foreach (['pending_approval' => 'forwardedCount', 'approved' => 'approvedCount', 'rejected' => 'rejectedCount'] as $status => $metric) {
        $this->request->update(['status' => $status]);
        $this->actingAs($outsider)->get(route('supply.dashboard'))
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page->where('stats.'.$metric, 1));
    }
});

test('Telegram price edits stay in the dialog and never alter processed requests', function ($status) {
    $this->request->update(['status' => $status, 'total_price' => 150]);
    $bot = app(Nutgram::class);
    $bot->shouldReceive('userId')->andReturn($this->director->telegram_id);
    $message = new SergiX44\Nutgram\Telegram\Types\Message\Message;
    $message->text = '250';
    $bot->shouldReceive('message')->andReturn($message);
    $dialog = supplyDialog();
    $dialog->requestId = $this->request->id;
    $dialog->revision = 1;
    $dialog->pricingItemId = $this->item->id;
    $dialog->handleItemPrice($bot);
    expect($this->item->fresh()->price_per_unit)->toBeNull()->and((float) $this->request->fresh()->total_price)->toBe(150.0);
    expect($dialog->itemPrices)->toBe($status === 'pending_supply' ? [$this->item->id => 250.0] : []);
})->with(['pending_supply', 'pending_approval', 'approved', 'paid', 'cancelled']);

test('quantity updates invalidate the old conversion and preserve a snapshot', function () {
    app(SupplyWorkflow::class)->updateQuantity($this->request->id, $this->item->id, $this->director, ['quantity' => 2, 'revision' => 1]);
    expect(fn () => app(SupplyWorkflow::class)->convert($this->request->id, $this->director, $this->data))->toThrow(ValidationException::class);
    expect($this->request->fresh()->revision)->toBe(2)->and(DB::table('request_revisions')->count())->toBe(1);
});

test('conversion preserves old decisions and replaces the author signature', function () {
    $this->request->update(['applicant_signature_snapshot_path' => 'original.png', 'applicant_signature_sha256' => hash('sha256', 'original')]);
    Storage::disk('local')->put('original.png', 'original');
    $this->director->update(['signature_path' => 'director.png']);
    Storage::disk('local')->put('director.png', 'director-signature');
    $this->request->approvals()->create(['approver_id' => $this->signer->id, 'level' => 1, 'status' => 'approved', 'comment' => 'Supply approved']);
    $this->actingAs($this->director)->postJson(route('supply.requests.convert', $this->request), $this->data)->assertRedirect();
    $r = $this->request->fresh();
    $snapshot = json_decode(DB::table('request_revisions')->where('approval_request_id', $r->id)->value('snapshot'), true);
    expect($snapshot['approvals'][0]['comment'])->toBe('Supply approved')->and($snapshot['applicant_signature_snapshot_path'])->toBe('original.png');
    expect($r->revision)->toBe(2)->and($r->applicant_id)->toBe($this->director->id)
        ->and($r->applicant_signature_sha256)->toBe(hash('sha256', 'director-signature'))
        ->and($r->tranches()->count())->toBe(2)->and((float) $r->tranches()->sum('amount'))->toBe(150.0);
    expect($r->deadline_from_supplier->toDateString())->toBe($this->data['deadline_from_supplier']);
    expect(fn () => app(SupplyWorkflow::class)->convert($r->id, $this->director, $this->data))->toThrow(ValidationException::class);
});

test('Telegram conversion uses the same schedule and delivery date contract', function () {
    $bot = app(Nutgram::class);
    $bot->shouldReceive('userId')->andReturn($this->director->telegram_id);
    $dialog = supplyDialog();
    $dialog->requestId = $this->request->id;
    $dialog->revision = 1;
    $dialog->currency = 'UZS';
    $dialog->supplierName = 'Vendor';
    $dialog->transactionType = 'bank';
    $dialog->deadlineDate = $this->data['deadline_from_supplier'];
    $dialog->approvers = [$this->signer->id];
    $dialog->itemPrices = [$this->item->id => 100];
    $dialog->isPrepayment = true;
    $dialog->tranches = $this->data['tranches'];
    $dialog->submit($bot);
    $r = $this->request->fresh();
    expect($r->status)->toBe('pending_approval')->and($r->revision)->toBe(2)
        ->and($r->deadline_from_supplier->toDateString())->toBe($this->data['deadline_from_supplier'])
        ->and($r->tranches->pluck('amount')->all())->toBe(['50.00', '100.00']);
});

test('cancellation records actor reason time and refuses a converted request', function () {
    app(SupplyWorkflow::class)->cancel($this->request->id, $this->director, 'No longer needed', 1);
    $r = $this->request->fresh();
    expect($r->cancellation_reason)->toBe('No longer needed')->and($r->cancelled_by)->toBe($this->director->id)->and($r->cancelled_at)->not->toBeNull();
    expect(fn () => app(SupplyWorkflow::class)->convert($r->id, $this->director, $this->data))->toThrow(ValidationException::class);
});

test('project deletion and author deactivation preserve paid data at HTTP and database boundaries', function ($target) {
    $this->request->update(['status' => 'paid', 'type' => 'approval', 'total_price' => 150]);
    $this->request->tranches()->create(['status' => 'paid', 'amount' => 150]);
    $id = $target === 'projects' ? $this->project->id : $this->author->id;
    $this->actingAs(makeUserWithRole('admin'));
    if ($target === 'users') {
        $this->patch(route('users.activation', $id), ['is_active' => false])->assertSessionHasNoErrors();
        expect($this->author->fresh()->is_active)->toBeFalse();
    } else {
        $this->delete(route('projects.delete', $id))->assertSessionHasErrors('error');
    }
    expect(fn () => DB::transaction(fn () => DB::table($target)->where('id', $id)->delete()))->toThrow(Illuminate\Database\QueryException::class);
    expect(ApprovalRequest::withTrashed()->find($this->request->id))->not->toBeNull()->and($this->request->tranches()->count())->toBe(1);
})->with(['projects', 'users']);

test('database prevents duplicate approval levels and duplicate participants', function ($column) {
    $other = makeUserWithRole('approver');
    $this->request->approvals()->create(['approver_id' => $this->signer->id, 'level' => 1]);
    expect(fn () => DB::transaction(fn () => $this->request->approvals()->create([
        'approver_id' => $column === 'level' ? $other->id : $this->signer->id, 'level' => $column === 'level' ? 1 : 2,
    ])))->toThrow(Illuminate\Database\QueryException::class);
})->with(['level', 'participant']);

test('management creation remains idempotent across delivery failures and role revocation is checked', function () {
    foreach ([$this->author, $this->signer] as $actor) {
        $actor->update(['signature_path' => $actor->id.'.png']);
        Storage::disk('local')->put($actor->signature_path, 'signature-'.$actor->id);
    }
    Storage::disk('local')->put('source.docx', 'source');
    Storage::disk('local')->put('preview.pdf', 'preview');
    $key = (string) Illuminate\Support\Str::uuid();
    $data = ['title' => 'Order', 'project_id' => $this->project->id, 'document_kind' => 'order', 'effective_date_mode' => 'approval_date', 'approvers' => [$this->signer->id]];
    $service = app(ManagementDocumentCreation::class);
    $r = $service->createFrozen($this->author, $data, 'source.docx', 'preview.pdf', $key);
    $bot = Mockery::mock(Nutgram::class);
    $bot->shouldReceive('sendMessage')->andThrow(new RuntimeException('Telegram unavailable'));
    app()->instance(Nutgram::class, $bot);
    app(App\Services\RequestCreationNotifier::class)->deliver($r->id);
    expect(DB::table('request_creation_notifications')->where('approval_request_id', $r->id)->value('delivered_at'))->toBeNull();
    expect($service->createFrozen($this->author, $data, 'source.docx', 'preview.pdf', $key)->id)->toBe($r->id)
        ->and(ApprovalRequest::where('type', 'management_document')->count())->toBe(1);
    $this->author->update(['account_status' => 'rejected']);
    expect(fn () => $service->createFrozen($this->author, $data, 'source.docx', 'preview.pdf', $key))->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
});

test('paid protocol delivery checks current access and checkpoints successful recipients', function () {
    $this->request->update(['type' => 'approval', 'status' => 'approved', 'total_price' => 150, 'currency' => 'UZS', 'transaction_type' => 'bank']);
    $revoked = makeUserWithRole('approver', ['account_status' => 'rejected']);
    $this->request->approvals()->create(['approver_id' => $revoked->id, 'level' => 1, 'status' => 'approved']);
    $tranche = $this->request->tranches()->create(['amount' => 150, 'status' => 'pending']);
    app(TrancheWorkflow::class)->update(makeUserWithRole('admin'), $this->request->id, $tranche->id, ['status' => 'paid']);
    $effectId = DB::table('request_effects')->where('event', 'paid')->value('id');
    $this->mock(App\Services\PdfGeneratorService::class)->shouldReceive('generateProtocol')->once()->andReturnUsing(function ($r) {
        Storage::disk('local')->put('protocols/test.pdf', 'pdf');
        $r->update(['pdf_url' => 'protocols/test.pdf']);

        return 'protocols/test.pdf';
    });
    $sent = [];
    app(Nutgram::class)->shouldReceive('sendDocument')->andReturnUsing(function ($document, $chat_id = null, $caption = null) use (&$sent) {
        $sent[] = $chat_id;
    });
    app(RequestEffects::class)->deliver($effectId);
    app(RequestEffects::class)->deliver($effectId);
    expect($sent)->toBe([$this->author->telegram_id])->and($sent)->not->toContain($revoked->telegram_id);
});

test('Telegram proofs resolve through authorized web endpoints without exposing tokens', function () {
    $this->request->update(['type' => 'approval', 'status' => 'paid']);
    $tranche = $this->request->tranches()->create(['amount' => 150, 'status' => 'paid', 'payment_proof' => 'telegram-file-id', 'payment_proof_telegram_id' => 'telegram-file-id']);
    Storage::disk('local')->put('cached-proof.pdf', 'proof');
    $this->mock(App\Services\TelegramFileCache::class)->shouldReceive('download')->once()->with(null, 'telegram-file-id', 'payment-proof-'.$tranche->id)
        ->andReturnUsing(fn () => Storage::disk('local')->download('cached-proof.pdf'));
    $url = route('requests.proof', [$this->request->id, $tranche->id]);
    $this->actingAs(makeUserWithRole('requester'))->get($url)->assertNotFound();
    $this->actingAs($this->author)->get($url)->assertDownload();
});

test('payment promises share tranche history and leave approved dates unchanged', function () {
    $this->request->update(['type' => 'approval', 'status' => 'approved', 'total_price' => 150]);
    $tranche = $this->request->tranches()->create(['amount' => 150, 'status' => 'pending', 'due_date' => today()->toDateString()]);
    $admin = makeUserWithRole('admin');
    app(TrancheWorkflow::class)->promise($admin, $this->request->id, today()->addDays(3)->toDateString(), 'Bank delay');
    expect($tranche->fresh()->due_date->toDateString())->toBe(today()->toDateString())
        ->and(DB::table('tranche_deadline_changes')->value('reason'))->toBe('Bank delay');
});

test('Telegram payment refusal marks only pending tranches and records the reason once', function () {
    $this->request->update(['type' => 'approval', 'status' => 'approved', 'total_price' => 150, 'currency' => 'UZS', 'transaction_type' => 'bank']);
    $paid = $this->request->tranches()->create(['amount' => 50, 'status' => 'paid']);
    $pending = $this->request->tranches()->create(['amount' => 100, 'status' => 'pending']);
    $accountant = makeUserWithRole('accountant');
    $accountant->projects()->attach($this->project);
    $bot = app(Nutgram::class);
    $bot->shouldReceive('userId')->andReturn($accountant->telegram_id);
    $message = new \SergiX44\Nutgram\Telegram\Types\Message\Message;
    $message->text = 'Банк отказал';
    $bot->shouldReceive('message')->andReturn($message);
    $dialog = new class extends \App\Telegram\Conversations\ManagePayment
    {
        protected function isCancelled(Nutgram $bot): bool
        {
            return false;
        }

        protected function showTranches(Nutgram $bot, ApprovalRequest $request) {}
    };
    $dialog->requestId = $this->request->id;
    $dialog->handleRejectPaymentReason($bot);
    $dialog->handleRejectPaymentReason($bot);

    expect($paid->fresh()->status)->toBe('paid')
        ->and($pending->fresh()->status)->toBe('failed')
        ->and($pending->fresh()->failure_reason)->toBe('Банк отказал')
        ->and($this->request->fresh()->status)->toBe('approved')
        ->and(DB::table('request_effects')->count())->toBe(1);
});

test('web cannot reject a tranche without a reason or outside its project', function () {
    $this->request->update(['type' => 'approval', 'status' => 'approved', 'total_price' => 150, 'currency' => 'UZS', 'transaction_type' => 'bank']);
    $tranche = $this->request->tranches()->create(['amount' => 150, 'status' => 'pending']);
    $accountant = makeUserWithRole('accountant');
    $this->actingAs($accountant)->putJson(route('finance.tranches.update', [$this->request->id, $tranche->id]),
        ['status' => 'failed', 'failure_reason' => 'No funds'])->assertNotFound();
    $accountant->projects()->attach($this->project);
    $this->putJson(route('finance.tranches.update', [$this->request->id, $tranche->id]), ['status' => 'failed'])
        ->assertJsonValidationErrors('failure_reason');
    $this->putJson(route('finance.tranches.update', [$this->request->id, $tranche->id]),
        ['status' => 'failed', 'failure_reason' => 'No funds'])->assertRedirect();
    expect($tranche->fresh()->failure_reason)->toBe('No funds');
});

test('archive updates work without mass assignment and cast as a date', function () {
    $file = $this->request->attachments()->create(['file_type' => 'pdf', 'telegram_file_id' => 'file']);
    $this->request->attachments()->update(['archived_at' => now()]);
    expect($file->fresh()->archived_at)->toBeInstanceOf(DateTimeInterface::class)->and($this->request->attachments()->count())->toBe(0);
});

test('failed Sheets delivery does not block messages and successful steps are not repeated', function () {
    Log::spy();
    $this->request->update(['status' => 'rejected']);
    config(['google_sheets.spreadsheet_id' => 'test-sheet']);
    \Revolution\Google\Sheets\Facades\Sheets::shouldReceive('spreadsheet')->twice()->with('test-sheet')->andReturnSelf();
    \Revolution\Google\Sheets\Facades\Sheets::shouldReceive('sheet')->twice()->andReturnSelf();
    $attempt = 0;
    \Revolution\Google\Sheets\Facades\Sheets::shouldReceive('append')->twice()->andReturnUsing(function () use (&$attempt) {
        if (++$attempt === 1) {
            throw new RuntimeException('Secret transport URL');
        }

        return new \Google\Service\Sheets\AppendValuesResponse;
    });
    $bot = Mockery::mock(Nutgram::class);
    $bot->shouldReceive('sendMessage')->times(3);
    app()->instance(Nutgram::class, $bot);
    $effects = app(RequestEffects::class);
    $effects->record($this->request, 'rejected', ['reason' => str_repeat('Я', 4500)]);
    $id = DB::table('request_effects')->value('id');
    expect(fn () => $effects->deliver($id))->toThrow(RuntimeException::class, 'has unfinished delivery steps');
    $effect = DB::table('request_effects')->find($id);
    expect($effect->completed_at)->toBeNull()->and(json_decode($effect->completed_steps))->toContain(
        'message-page:'.$this->author->id.':0', 'message-page:'.$this->author->id.':1', 'message:'.$this->author->id
    );
    Log::shouldHaveReceived('warning')->once()->with('Request effect delivery step failed', Mockery::on(fn ($context) => $context['effect_id'] === $id && $context['request_id'] === $this->request->id
        && $context['event'] === 'rejected' && $context['step'] === 'sheets'
        && $context['error_type'] === RuntimeException::class && $context['error_code'] === 0
        && ! str_contains(json_encode($context), 'Secret transport URL')
    ));
    $effects->deliver($id);
    expect(DB::table('request_effects')->value('completed_at'))->not->toBeNull();
});

test('a failed recipient does not truncate other recipients or the next approver card', function () {
    Log::spy();
    $this->request->update(['type' => 'approval', 'status' => 'pending_approval', 'expected_approval_level' => 2,
        'supply_applicant_id' => $this->director->id]);
    $this->item->update(['description' => str_repeat('Описание ', 1000)]);
    $previous = $this->request->approvals()->create(['approver_id' => $this->director->id, 'level' => 1, 'status' => 'approved']);
    $approval = $this->request->approvals()->create(['approver_id' => $this->signer->id, 'level' => 2, 'status' => 'pending']);
    $expectedPages = app(App\Services\ApprovalNotificationMessage::class)->pages($this->request, 'ru');
    expect(count($expectedPages))->toBeGreaterThan(1);
    $sent = [];
    $fail = true;
    $bot = Mockery::mock(Nutgram::class);
    $bot->shouldReceive('sendMessage')->andReturnUsing(function ($text, $chat_id) use (&$sent, &$fail) {
        if ($chat_id === $this->author->telegram_id && $fail) {
            $fail = false;
            throw new \SergiX44\Nutgram\Telegram\Exceptions\TelegramException('Secret transport URL', 403);
        }
        $sent[$chat_id][] = $text;
    });
    app()->instance(Nutgram::class, $bot);
    $effects = app(RequestEffects::class);
    $effects->record($this->request, 'approved:'.$previous->id, ['reason' => str_repeat('Я', 4500)]);
    $id = DB::table('request_effects')->value('id');
    try {
        $effects->deliver($id);
        $this->fail('Delivery must remain pending for the failed recipient.');
    } catch (RuntimeException $error) {
        expect($error->getMessage())->toContain('message-page:'.$this->author->id.':0', 'TelegramException (code 403)')
            ->not->toContain('Secret transport URL');
        expect($error->getPrevious())->toBeNull();
    }
    $effect = DB::table('request_effects')->find($id);
    expect($effect->completed_at)->toBeNull()
        ->and(json_decode($effect->completed_steps))->toContain('message:'.$this->director->id, 'next:'.$this->signer->id)
        ->and($sent[$this->signer->telegram_id])->toBe($expectedPages)
        ->and($approval->fresh()->notified_at)->not->toBeNull();
    $firstDelivery = $sent;
    $effects->deliver($id);
    expect(DB::table('request_effects')->value('completed_at'))->not->toBeNull()
        ->and($sent[$this->director->telegram_id])->toBe($firstDelivery[$this->director->telegram_id])
        ->and($sent[$this->signer->telegram_id])->toBe($firstDelivery[$this->signer->telegram_id])
        ->and($sent[$this->author->telegram_id])->toHaveCount(3);
});

test('next approver receives full request details and revision buttons only once', function () {
    $sent = [];
    $bot = Mockery::mock(Nutgram::class);
    $bot->shouldReceive('sendMessage')->andReturnUsing(function (...$args) use (&$sent) {
        $sent[] = ['text' => $args[0], 'chat_id' => $args[1], 'parse_mode' => $args[3] ?? null, 'reply_markup' => $args[12] ?? null];
    });
    app()->instance(Nutgram::class, $bot);
    $this->author->update(['phone' => '998901234567']);
    $this->request->update(['type' => 'approval', 'status' => 'pending_approval', 'expected_approval_level' => 2,
        'name' => 'Meat & <food>', 'currency' => 'UZS', 'total_price' => 425000, 'deadline_date' => '2026-09-17']);
    $this->item->update(['name' => 'Meat', 'description' => 'Invoice <1903>', 'quantity' => 1, 'price_per_unit' => 425000, 'total_price' => 425000]);
    $approval = $this->request->approvals()->create(['approver_id' => $this->signer->id, 'level' => 2, 'status' => 'pending']);
    $effects = app(RequestEffects::class);
    $effects->record($this->request, 'approved:1');
    $id = DB::table('request_effects')->value('id');
    $effects->deliver($id);
    $effects->deliver($id);

    $messages = array_values(array_filter($sent, fn ($message) => $message['chat_id'] === $this->signer->telegram_id));
    expect($messages)->toHaveCount(1);
    expect($messages[0]['text'])->toContain('Уровень 2', $this->project->name, $this->author->full_name,
        '998901234567', 'Meat &amp; &lt;food&gt;', 'Invoice &lt;1903&gt;', '425 000.00', '17.09.2026');
    expect($messages[0]['parse_mode'])->toBe('HTML');
    expect(json_encode($messages[0]['reply_markup']))->toContain('approve:'.$this->request->id.'_1', 'reject:'.$this->request->id.'_1');
    expect($approval->fresh()->notified_at)->not->toBeNull();
});

test('long approval cards preserve all items and escape user supplied markup', function () {
    $this->item->update(['description' => str_repeat('<b>&Test</b>', 1000)]);
    $this->request->items()->create(['name' => 'Last item', 'quantity' => 2]);
    $pages = app(App\Services\ApprovalNotificationMessage::class)->pages($this->request, 'ru');
    expect(count($pages))->toBeGreaterThan(1);
    foreach ($pages as $page) {
        expect(mb_strlen($page))->toBeLessThanOrEqual(3500);
        expect($page)->not->toContain('<b>&Test</b>');
    }
    expect(implode('', $pages))->toContain('Last item');
    $decoded = html_entity_decode(implode('', $pages), ENT_QUOTES, 'UTF-8');
    expect(str_replace("\n", '', $decoded))->toContain(str_repeat('<b>&Test</b>', 1000));
});

test('approval update tells the author who decided and what happens next', function () {
    $sent = [];
    $bot = Mockery::mock(Nutgram::class);
    $bot->shouldReceive('sendMessage')->andReturnUsing(function (...$args) use (&$sent) {
        $sent[] = ['text' => $args[0], 'chat_id' => $args[1], 'reply_markup' => $args[12] ?? null];
    });
    app()->instance(Nutgram::class, $bot);
    $this->request->update(['type' => 'approval', 'status' => 'pending_approval', 'name' => 'VPS хостинг', 'currency' => 'UZS', 'total_price' => 660000]);
    $this->signer->update(['full_name' => 'Ольга', 'title' => 'CMO']);
    $next = makeUserWithRole('approver', ['full_name' => 'Мухаммадали', 'title' => 'Финансист']);
    $next->projects()->attach($this->project);
    $this->request->approvals()->create(['approver_id' => $this->signer->id, 'level' => 1, 'status' => 'pending']);
    $this->request->approvals()->create(['approver_id' => $next->id, 'level' => 2, 'status' => 'pending']);
    app(App\Services\ApprovalWorkflow::class)->approve($this->request->id, $this->signer, 'Согласовано');
    $this->signer->update(['full_name' => 'Переименован']);
    $effects = app(RequestEffects::class);
    $id = DB::table('request_effects')->value('id');
    $effects->deliver($id);
    $effects->deliver($id);
    $messages = array_values(array_filter($sent, fn ($m) => $m['chat_id'] === $this->author->telegram_id));
    expect($messages)->toHaveCount(1);
    expect(html_entity_decode(strip_tags($messages[0]['text']), ENT_QUOTES, 'UTF-8'))->toContain('VPS хостинг', '660 000.00 UZS', 'Одобрил: Ольга (CMO)', 'Этап: 1 из 2', 'Комментарий: Согласовано', 'Мухаммадали (Финансист)')
        ->not->toContain('Переименован', '/history', 'http');
    $keyboard = json_decode(json_encode($messages[0]['reply_markup']), true);
    expect($keyboard['inline_keyboard'][0][0]['url'])->toBe(route('requests.detail', $this->request->id));
});

test('final approval describes the approving person and the next business stage', function () {
    $sent = [];
    $bot = Mockery::mock(Nutgram::class);
    $bot->shouldReceive('sendMessage')->andReturnUsing(function (...$args) use (&$sent) {
        $sent[] = ['text' => $args[0], 'chat_id' => $args[1], 'reply_markup' => $args[12] ?? null];
    });
    app()->instance(Nutgram::class, $bot);
    $this->request->update(['status' => 'pending_approval']);
    $this->request->approvals()->create(['approver_id' => $this->signer->id, 'level' => 1, 'status' => 'pending']);
    app(App\Services\ApprovalWorkflow::class)->approve($this->request->id, $this->signer, null);
    app(RequestEffects::class)->deliver(DB::table('request_effects')->value('id'));
    $author = collect($sent)->firstWhere('chat_id', $this->author->telegram_id);
    expect(html_entity_decode(strip_tags($author['text']), ENT_QUOTES, 'UTF-8'))->toContain('Согласование завершено', $this->signer->full_name, 'Этап: 1 из 1', 'Заявка передана в снабжение.')
        ->not->toContain('Следующий согласующий', 'Комментарий:', '/history');
    $supply = collect($sent)->firstWhere('chat_id', $this->director->telegram_id);
    $rows = json_decode(json_encode($supply['reply_markup']), true)['inline_keyboard'];
    expect($rows[0][0]['callback_data'])->toBe('convert_supply:'.$this->request->id)
        ->and($rows[1][0]['url'])->toBe(route('requests.detail', $this->request->id));
});

test('legacy approval effects still identify the recorded approving participant', function () {
    $spy = fakeBot();
    $this->request->update(['status' => 'pending_approval', 'expected_approval_level' => 2]);
    $approval = $this->request->approvals()->create(['approver_id' => $this->signer->id, 'level' => 1, 'status' => 'approved', 'comment' => 'Legacy comment']);
    $this->request->approvals()->create(['approver_id' => $this->director->id, 'level' => 2, 'status' => 'pending']);
    app(RequestEffects::class)->record($this->request, 'approved:'.$approval->id);
    app(RequestEffects::class)->deliver(DB::table('request_effects')->value('id'));
    $message = $spy->messagesTo($this->author->telegram_id)[0];
    expect(html_entity_decode(strip_tags($message['text']), ENT_QUOTES, 'UTF-8'))->toContain($this->signer->full_name, 'Legacy comment', 'Этап: 1 из 2', $this->director->full_name);
});

test('final expense approval is self contained and keeps the site link below the details', function () {
    $sent = [];
    $bot = Mockery::mock(Nutgram::class);
    $bot->shouldReceive('sendMessage')->andReturnUsing(function (...$args) use (&$sent) {
        $sent[] = ['text' => $args[0], 'chat_id' => $args[1], 'reply_markup' => $args[12] ?? null];
    });
    app()->instance(Nutgram::class, $bot);
    config(['google_sheets.spreadsheet_id' => null]);
    $this->request->update(['type' => 'approval', 'status' => 'pending_approval', 'name' => 'VPS', 'currency' => 'UZS', 'total_price' => 660000]);
    $this->request->approvals()->create(['approver_id' => $this->signer->id, 'level' => 1, 'status' => 'pending']);
    $this->mock(App\Services\PdfGeneratorService::class)->shouldReceive('generateProtocol')->once()->andReturn('protocols/test.pdf');
    app(App\Services\ApprovalWorkflow::class)->approve($this->request->id, $this->signer, 'Можно оплачивать');
    app(RequestEffects::class)->deliver(DB::table('request_effects')->value('id'));
    $message = collect($sent)->firstWhere('chat_id', $this->author->telegram_id);
    expect(html_entity_decode(strip_tags($message['text']), ENT_QUOTES, 'UTF-8'))->toContain('Согласование завершено', 'VPS', '660 000.00 UZS', $this->signer->full_name, 'Можно оплачивать', 'Заявка утверждена и ожидает оплаты.')
        ->not->toContain('Следующий согласующий', '/history');
    $rows = json_decode(json_encode($message['reply_markup']), true)['inline_keyboard'];
    expect($rows[array_key_last($rows)][0]['url'])->toBe(route('requests.detail', $this->request->id));
});

test('long decision comments keep all information with the site button only on the last page', function () {
    $sent = [];
    $bot = Mockery::mock(Nutgram::class);
    $bot->shouldReceive('sendMessage')->andReturnUsing(function (...$args) use (&$sent) {
        $sent[] = ['text' => $args[0], 'chat_id' => $args[1], 'reply_markup' => $args[12] ?? null];
    });
    app()->instance(Nutgram::class, $bot);
    $this->request->update(['status' => 'pending_approval', 'expected_approval_level' => 2]);
    $comment = str_repeat('Подтверждаю 😀 ', 200);
    $approval = $this->request->approvals()->create(['approver_id' => $this->signer->id, 'level' => 1, 'status' => 'approved', 'comment' => $comment]);
    app(RequestEffects::class)->record($this->request, 'approved:'.$approval->id);
    $id = DB::table('request_effects')->value('id');
    app(RequestEffects::class)->deliver($id);
    app(RequestEffects::class)->deliver($id);
    $messages = array_values(array_filter($sent, fn ($m) => $m['chat_id'] === $this->author->telegram_id));
    expect(count($messages))->toBeGreaterThan(1);
    expect(implode('', array_column($messages, 'text')))->toContain($comment);
    foreach ($messages as $index => $message) {
        expect(mb_strlen($message['text']))->toBeLessThanOrEqual(2000);
        if ($index !== array_key_last($messages)) {
            expect($message['reply_markup'])->toBeNull();
        } else {
            expect(json_decode(json_encode($message['reply_markup']), true)['inline_keyboard'][0][0]['text'])->toBe('Открыть заявку на сайте');
        }
    }
});

test('outdated effect never sends a notification for a newer revision', function () {
    app(RequestEffects::class)->record($this->request, 'rejected');
    $this->request->update(['revision' => 2]);
    $bot = Mockery::mock(Nutgram::class);
    $bot->shouldNotReceive('sendMessage');
    app()->instance(Nutgram::class, $bot);
    app(RequestEffects::class)->deliver(DB::table('request_effects')->value('id'));
    expect(DB::table('request_effects')->value('completed_at'))->not->toBeNull();
});

test('manual payment text cannot become an arbitrary Telegram download', function () {
    $this->request->update(['type' => 'approval', 'status' => 'approved', 'total_price' => 150]);
    $tranche = $this->request->tranches()->create(['amount' => 150, 'status' => 'pending']);
    app(TrancheWorkflow::class)->update(makeUserWithRole('admin'), $this->request->id, $tranche->id,
        ['status' => 'pending', 'payment_proof' => 'some_other_telegram_file_id']);
    $this->mock(App\Services\TelegramFileCache::class)->shouldNotReceive('download');
    $this->actingAs($this->author)->get(route('requests.proof', [$this->request->id, $tranche->id]))->assertNotFound();
    expect($tranche->fresh()->payment_proof_telegram_id)->toBeNull();
});

test('Telegram file downloads stream once then reuse the cached file', function () {
    $file = new \SergiX44\Nutgram\Telegram\Types\Media\File;
    $file->file_path = 'documents/proof.pdf';
    $file->file_size = 5;
    app(Nutgram::class)->shouldReceive('getFile')->once()->with('telegram_file_123')->andReturn($file);
    \Illuminate\Support\Facades\Http::fake(function ($request, $options) {
        expect($options)->toHaveKeys(['sink', 'progress']);

        return \Illuminate\Support\Facades\Http::response('proof');
    });
    $cache = app(App\Services\TelegramFileCache::class);
    $path = $cache->resolve('telegram_file_123');
    expect($cache->resolve('telegram_file_123'))->toBe($path)->and(Storage::disk('local')->get($path))->toBe('proof');
    \Illuminate\Support\Facades\Http::assertSentCount(1);
});

test('oversized Telegram streams are aborted cleaned up and errors are sanitized', function () {
    $file = new \SergiX44\Nutgram\Telegram\Types\Media\File;
    $file->file_path = 'documents/proof.pdf';
    app(Nutgram::class)->shouldReceive('getFile')->once()->andReturn($file);
    config(['nutgram.token' => 'SECRET_TEST_TOKEN']);
    \Illuminate\Support\Facades\Http::fake(function ($request, $options) {
        file_put_contents($options['sink'], 'partial');
        $options['progress'](0, 21 * 1024 * 1024);

        return \Illuminate\Support\Facades\Http::response('');
    });
    try {
        app(App\Services\TelegramFileCache::class)->resolve('telegram_file_123');
        $this->fail('Oversized download was accepted');
    } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
        expect($error->getStatusCode())->toBe(502)->and($error->getMessage())->not->toContain('SECRET_TEST_TOKEN');
    }
    expect(Storage::disk('local')->allFiles('telegram-cache'))->toBe([]);
});
