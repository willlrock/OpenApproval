<?php

use App\Logging\Redactor;
use App\Logging\SanitizeLogs;
use App\Logging\ScrubSentryEvent;
use App\Services\SystemLogReader;
use Illuminate\Log\LogManager;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Monolog\Formatter\JsonFormatter;
use Sentry\Event as SentryEvent;
use Sentry\ExceptionDataBag;
use Sentry\Frame;
use Sentry\Stacktrace;

uses(Tests\TestCase::class);

beforeEach(function () {
    $this->logPath = sys_get_temp_dir().'/teg-observability-'.bin2hex(random_bytes(8)).'.log';
    config([
        'logging.default' => 'observability_test',
        'logging.channels.observability_test' => [
            'driver' => 'single', 'path' => $this->logPath, 'level' => 'debug',
            'tap' => [SanitizeLogs::class], 'formatter' => JsonFormatter::class,
            'replace_placeholders' => true,
        ],
    ]);
    Log::swap(new LogManager(app()));
    Context::flush();
});

afterEach(function () {
    Log::channel()->getLogger()->close();
    if (is_file($this->logPath)) {
        unlink($this->logPath);
    }
});

test('logs redact interpolated secrets nested payloads and exception arguments after context is added', function () {
    config(['nutgram.token' => '123456789:abcdefghijklmnopqrstuvwxyz123456']);
    Context::add(['correlation_id' => 'operation-1', 'password' => 'context-secret']);
    $exception = (function (string $password) {
        return new RuntimeException('Request failed at https://api.telegram.org/bot'.config('nutgram.token').'/sendMessage');
    })('argument-secret');
    Log::error('Delivery failed {token} {password}', [
        'token' => config('nutgram.token'), 'request_id' => 42,
        'password' => 'placeholder-secret',
        'payload' => ['document' => 'private-document'], 'exception' => $exception,
        'credentials' => ['password' => 'nested-secret'],
        'model' => (object) ['email' => 'private@example.com'],
    ]);
    $text = file_get_contents($this->logPath);
    $record = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
    foreach ([config('nutgram.token'), 'placeholder-secret', 'context-secret', 'argument-secret', 'nested-secret', 'private-document', 'private@example.com'] as $secret) {
        expect($text)->not->toContain($secret);
    }
    expect($record['context']['request_id'])->toBe(42)
        ->and($record['extra']['correlation_id'])->toBe('operation-1')
        ->and($record['context']['exception']['class'])->toBe(RuntimeException::class)
        ->and($record['context']['exception']['line'])->toBeInt();
    expect(app(Redactor::class)->text('SQLSTATE[23505]: duplicate value private@example.com (SQL: insert ...)'))
        ->toBe('Database operation failed. SQLSTATE[23505]');
    expect(app(Redactor::class)->text("transport failed\r\nforged log entry"))
        ->toBe('transport failed\\r\\nforged log entry');
});

test('file logs remain readable by the existing administration log reader', function () {
    config(['logging.channels.observability_test.formatter' => null]);
    Log::forgetChannel('observability_test');
    Log::warning('telegram.delivery_failed', ['request_id' => 42, 'password' => 'private-value']);
    $result = app(SystemLogReader::class)->read($this->logPath);
    expect($result['entries'])->toHaveCount(1)
        ->and($result['entries'][0]['level'])->toBe('WARNING')
        ->and($result['entries'][0]['message'])->toContain('telegram.delivery_failed')
        ->and(file_get_contents($this->logPath))->not->toContain('private-value');
});

test('http requests have separate server generated ids without logging raw urls or input', function () {
    Route::post('/_observability/{id}', function () {
        Log::info('operation.started');

        return response()->json(['ok' => true]);
    })->name('observability.test');
    $first = $this->postJson('/_observability/private-hash?token=query-secret', ['password' => 'body-secret'], ['X-Correlation-ID' => 'forged']);
    $first->assertOk()->assertHeader('X-Correlation-ID');
    $id = $first->headers->get('X-Correlation-ID');
    $second = $this->postJson('/_observability/other-private-hash');
    expect($id)->not->toBe('forged')->not->toBe($second->headers->get('X-Correlation-ID'));
    $text = file_get_contents($this->logPath);
    foreach (['private-hash', 'query-secret', 'body-secret'] as $secret) {
        expect($text)->not->toContain($secret);
    }
    $records = array_map(fn ($line) => json_decode($line, true), array_filter(explode("\n", trim($text))));
    expect($records[0]['extra']['correlation_id'])->toBe($id)
        ->and($records[0]['extra']['route'])->toBe('observability.test')
        ->and($records[1]['message'])->toBe('http.request_completed')
        ->and($records[1]['extra']['route_template'])->toBe('_observability/{id}');
});

test('unhandled http errors keep their correlation id and safe exception log', function () {
    config(['app.debug' => false]);
    Route::get('/_observability-failure', fn () => throw new RuntimeException('password=private-value'));
    $response = $this->getJson('/_observability-failure');
    $response->assertStatus(500)->assertHeader('X-Correlation-ID');
    expect(file_get_contents($this->logPath))->toContain($response->headers->get('X-Correlation-ID'))
        ->not->toContain('private-value');
});

test('queued jobs inherit correlation and inline jobs restore their parent context', function () {
    Context::add(['correlation_id' => 'parent-operation', 'source' => 'http', 'actor_id' => 7]);
    Queue::connection('sync')->push(new ObservabilityProbeJob);
    expect(ObservabilityProbeJob::$context['correlation_id'])->toBe('parent-operation')
        ->and(ObservabilityProbeJob::$context['source'])->toBe('queue')
        ->and(ObservabilityProbeJob::$context['actor_id'])->toBe(7)
        ->and(Context::get('source'))->toBe('http')
        ->and(Context::has('job_class'))->toBeFalse();

    $job = new SyncJob(app(), json_encode(['job' => 'Illuminate\\Queue\\CallQueuedHandler@call', 'displayName' => ObservabilityProbeJob::class, 'uuid' => 'job-2']), 'sync', 'default');
    Event::dispatch(new JobProcessing('sync', $job));
    expect(Context::get('correlation_id'))->not->toBe('parent-operation')
        ->and(Context::has('actor_id'))->toBeFalse();
    Event::dispatch(new JobFailed('sync', $job, new RuntimeException('password=job-secret')));
    expect(file_get_contents($this->logPath))->toContain('queue.job_failed')->not->toContain('job-secret');
});

test('sentry envelope keeps safe stack frames and correlation but excludes collected private data', function () {
    Context::add(['correlation_id' => 'operation-9', 'source' => 'http', 'route' => 'requests.detail']);
    $event = SentryEvent::createEvent();
    $event->setRequest(['url' => 'https://app/verify/private-hash', 'data' => ['password' => 'private-body']]);
    $event->setExtra(['document' => 'private-document']);
    $event->setContext('arbitrary', ['secret' => 'private-context']);
    $frame = new Frame('send', 'app/Service.php', 25, null, null, ['password' => 'private-variable']);
    $frame->setContextLine('private-source');
    $event->setExceptions([new ExceptionDataBag(new RuntimeException('password=private-message'), new Stacktrace([$frame]))]);
    $clean = ScrubSentryEvent::beforeSend($event);
    expect($clean->getId())->toBe($event->getId())
        ->and($clean->getRequest())->toBe([])
        ->and($clean->getExtra())->toBe([])
        ->and($clean->getContexts())->toBe([])
        ->and($clean->getUser())->toBeNull()
        ->and($clean->getBreadcrumbs())->toBe([])
        ->and($clean->getTags()['correlation_id'])->toBe('operation-9')
        ->and($clean->getExceptions()[0]->getValue())->not->toContain('private-message')
        ->and($clean->getExceptions()[0]->getStacktrace()->getFrames()[0]->getLine())->toBe(25)
        ->and($clean->getExceptions()[0]->getStacktrace()->getFrames()[0]->getVars())->toBe([])
        ->and($clean->getExceptions()[0]->getStacktrace()->getFrames()[0]->getContextLine())->toBeNull();
});

test('diagnostic command is local by default and fails clearly without a configured dsn', function () {
    config(['sentry.dsn' => null]);
    $this->artisan('observability:check')->assertSuccessful();
    $this->artisan('observability:check --send')->assertFailed();
});

test('diagnostic command checks the SDK transport result instead of the void flush helper', function () {
    config(['sentry.dsn' => 'https://public@example.com/1']);
    $client = Mockery::mock(Sentry\ClientInterface::class);
    $client->shouldReceive('flush')->once()->with(5)->andReturn(new Sentry\Transport\Result(Sentry\Transport\ResultStatus::success()));
    $hub = Mockery::mock(Sentry\State\HubInterface::class);
    $hub->shouldReceive('captureMessage')->once()->with('observability.check', Mockery::type(Sentry\Severity::class))->andReturn(new Sentry\EventId('a'.str_repeat('1', 31)));
    $hub->shouldReceive('getClient')->once()->andReturn($client);
    app()->instance(Sentry\State\HubInterface::class, $hub);
    $this->artisan('observability:check --send')->expectsOutputToContain('Sentry transport completed.')->assertSuccessful();
});

test('installed sentry sdk invokes the configured scrubber before the transport', function () {
    $transport = new class implements Sentry\Transport\TransportInterface
    {
        public ?SentryEvent $event = null;

        public function send(SentryEvent $event): Sentry\Transport\Result
        {
            $this->event = $event;

            return new Sentry\Transport\Result(Sentry\Transport\ResultStatus::success(), $event);
        }

        public function close(?int $timeout = null): Sentry\Transport\Result
        {
            return new Sentry\Transport\Result(Sentry\Transport\ResultStatus::success());
        }
    };
    $options = array_diff_key(config('sentry'), array_flip(['breadcrumbs', 'tracing']));
    $options['dsn'] = 'https://public@example.com/1';
    $client = Sentry\ClientBuilder::create($options)->setTransport($transport)->getClient();
    Context::add('correlation_id', 'sdk-operation');
    $id = $client->captureException(new RuntimeException('password=transport-secret'));
    expect($id)->not->toBeNull()
        ->and($transport->event)->not->toBeNull()
        ->and($transport->event->getExceptions()[0]->getValue())->not->toContain('transport-secret')
        ->and($transport->event->getTags()['correlation_id'])->toBe('sdk-operation')
        ->and($transport->event->getRequest())->toBe([]);
});

class ObservabilityProbeJob implements Illuminate\Contracts\Queue\ShouldQueue
{
    public static array $context = [];

    public function handle(): void
    {
        self::$context = Context::all();
    }
}
