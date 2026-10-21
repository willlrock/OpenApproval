<?php

use App\Models\ApprovalRequest;
use App\Services\RequestCreationNotifier;
use App\Telegram\Conversations\CreateApprovalRequest;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Illuminate\Support\Facades\Log;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Inline\CallbackQuery;

beforeEach(function () {
    seedRoles();
    $this->spy = fakeBot();
    $this->bot = app(Nutgram::class);
    $this->author = makeUserWithRole('requester');
    $this->approver = makeUserWithRole('approver');
    $this->project = makeProject();
    $this->author->projects()->attach($this->project);
    $this->approver->projects()->attach($this->project);
    $this->bot->shouldReceive('userId')->andReturn($this->author->telegram_id);
    $this->bot->shouldReceive('isCallbackQuery')->andReturn(true);
    $this->callback = new CallbackQuery;
    $this->bot->shouldReceive('callbackQuery')->andReturn($this->callback);
    $this->conversation = new class extends CreateApprovalRequest
    {
        public ?string $nextStep = null;

        protected function end(): void {}

        protected function next(string $step, mixed $type = null): static
        {
            $this->nextStep = $step;

            return $this;
        }

        public function acknowledgeForTest(Nutgram $bot, mixed ...$options): void
        {
            $this->acknowledgeCallback($bot, ...$options);
        }
    };
});

function callbackTimeout(): ConnectException
{
    return new ConnectException('private bot URL', new Request('POST', 'https://api.telegram.org/botPRIVATE/answerCallbackQuery'), null, ['errno' => 28]);
}

test('callback timeout does not prevent confirmation and repeated webhook does not duplicate the request', function () {
    $this->mock(RequestCreationNotifier::class)->shouldReceive('schedule')->once();
    $this->callback->data = 'confirm:yes';
    $this->bot->shouldReceive('answerCallbackQuery')->twice()->andThrow(callbackTimeout());
    $this->bot->shouldReceive('editMessageReplyMarkup')->once()->andReturnNull();
    Log::spy();
    $this->conversation->projectId = $this->project->id;
    $this->conversation->currency = 'UZS';
    $this->conversation->transaction_type = 'bank';
    $this->conversation->supplier_name = 'Test supplier';
    $this->conversation->approvers = [$this->approver->id];
    $this->conversation->items = [['name' => 'Cable', 'quantity' => 2, 'price_per_unit' => 10, 'total_price' => 20]];

    $this->conversation->handleConfirm($this->bot);
    $this->conversation->handleConfirm($this->bot);

    expect(ApprovalRequest::count())->toBe(1)
        ->and((float) ApprovalRequest::first()->total_price)->toBe(20.0);
    Log::shouldHaveReceived('warning')->with('telegram.callback_ack_failed', ['operation' => 'answerCallbackQuery', 'curl_errno' => 28])->twice();
});

test('callback timeout does not bypass project access checks', function () {
    $this->callback->data = 'confirm:yes';
    $this->bot->shouldReceive('answerCallbackQuery')->once()->andThrow(callbackTimeout());
    $this->bot->shouldReceive('editMessageReplyMarkup')->once()->andReturnNull();
    $this->conversation->projectId = makeProject('Forbidden')->id;
    $this->conversation->handleConfirm($this->bot);

    expect(ApprovalRequest::count())->toBe(0);
    expect($this->spy->messages[0]['text'])->toBe(__('bot.app_save_failed', [], 'ru'));
});

test('callback acknowledgement preserves alert parameters and propagates unrelated failures', function () {
    $this->bot->shouldReceive('answerCallbackQuery')->once()->andReturnUsing(function ($callback_query_id = null, $text = null, $show_alert = null) {
        expect($text)->toBe('Access denied')->and($show_alert)->toBeTrue();
        throw new RuntimeException('Unexpected failure');
    });
    expect(fn () => $this->conversation->acknowledgeForTest($this->bot, text: 'Access denied', show_alert: true))
        ->toThrow(RuntimeException::class, 'Unexpected failure');
});

test('selection continues after a callback timeout', function (string $data, string $method, string $nextStep) {
    $this->conversation->projectId = $this->project->id;
    $this->callback->data = str_replace('{approver}', (string) $this->approver->id, $data);
    $this->bot->shouldReceive('answerCallbackQuery')->once()->andThrow(callbackTimeout());
    $this->conversation->$method($this->bot);

    expect($this->conversation->nextStep)->toBe($nextStep);
    if ($method === 'handleApprover') {
        expect($this->conversation->approvers)->toBe([$this->approver->id]);
    } else {
        expect($this->conversation->transaction_type)->toBe('bank');
    }
})->with([
    ['tx:bank', 'handleTransactionType', 'handleSupplier'],
    ['apr:{approver}', 'handleApprover', 'handleApprover'],
]);

test('Nutgram receives the configured timeout instead of its five second default', function () {
    expect(config('nutgram.config.timeout'))->toBeGreaterThan(0);
    $this->app->forgetInstance(Nutgram::class);
    config(['nutgram.config.timeout' => 13]);
    expect(app(Nutgram::class)->getConfig()->clientTimeout)->toBe(13);
});
