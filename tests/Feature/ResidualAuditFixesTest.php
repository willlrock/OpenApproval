<?php

use App\Models\ApprovalRequest;
use App\Models\User;
use App\Services\PhoneIdentity;
use App\Services\TelegramFileCache;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Media\Document;
use SergiX44\Nutgram\Telegram\Types\Message\Message;

beforeEach(function () {
    seedRoles();
    $this->withoutVite();
    Storage::fake('local');
    $this->botSpy = fakeBot();
});

test('ordinary admin cannot disable or demote a peer through alternate account endpoints', function () {
    $actor = User::factory()->role('admin')->create();
    $peer = User::factory()->role('admin')->create(['telegram_id' => 991123]);
    $this->actingAs($actor)->patch(route('users.activation', $peer), ['is_active' => false])->assertForbidden();
    $this->post(route('registrations.reject', $peer))->assertForbidden();
    $this->put(route('users.update', $peer), ['role' => 'requester'])->assertForbidden();
    $this->put(route('users.update', $peer), ['project_ids' => []])->assertForbidden();
    expect($peer->fresh()->isApproved())->toBeTrue()->and($peer->fresh()->role)->toBe('admin');
});

test('registration rejection cannot disable an approved employee or replay a processed decision', function () {
    $actor = User::factory()->role('superadmin')->create();
    $employee = User::factory()->role('requester')->create(['telegram_id' => 991124]);
    $this->actingAs($actor)->post(route('registrations.reject', $employee))->assertSessionHasErrors('error');
    expect($employee->fresh()->isApproved())->toBeTrue();
    $pending = User::factory()->role('requester')->create(['account_status' => 'pending', 'telegram_id' => 991125]);
    $this->post(route('registrations.reject', $pending))->assertSessionHasNoErrors();
    expect($pending->fresh()->account_status)->toBe('rejected');
    $this->post(route('registrations.reject', $pending))->assertSessionHasErrors('error');
    expect($this->botSpy->messagesTo($pending->telegram_id))->toHaveCount(1);
});

test('profile cannot claim another accounts phone through formatting', function (string $variant) {
    User::factory()->role('superadmin')->create(['phone' => '+998900008887']);
    $other = User::factory()->role('requester')->create();
    $oldPhone = $other->phone;
    $this->actingAs($other)->patch(route('profile.update'), ['full_name' => $other->full_name, 'phone' => $variant])
        ->assertSessionHasErrors('phone');
    expect($other->fresh()->phone)->toBe($oldPhone);
})->with(['998900008887', '+998 90 000 88 87', '998(90)000-88-87']);

test('formatted phone remains usable for sign in and recovery', function () {
    $user = User::factory()->role('requester')->create(['phone' => '+998 (90) 000-88-85', 'telegram_id' => 998885]);
    expect($user->phone_normalized)->toBe('998900008885');
    $this->post(route('login.store'), ['phone' => '998900008885', 'password' => 'password'])->assertSessionHasNoErrors();
    $this->assertAuthenticatedAs($user);
    $this->post(route('logout'));
    $this->post(route('password.email'), ['phone' => '+998 90 000 88 85'])->assertSessionHasNoErrors();
    expect($this->botSpy->messagesTo($user->telegram_id))->toHaveCount(1);
});

test('phone formatting cannot bypass the sign in rate limit', function () {
    User::factory()->role('requester')->create(['phone' => '+998900008884']);
    foreach (['998900008884', '+998900008884', '+998 90 000 88 84', '998(90)0008884', '998-90-000-88-84'] as $phone) {
        $this->post(route('login.store'), ['phone' => $phone, 'password' => 'wrong'])->assertSessionHasErrors('phone');
    }
    $this->post(route('login.store'), ['phone' => '+998 (90) 000-88-84', 'password' => 'wrong'])->assertStatus(429);
});

test('duplicate phone at user creation is a field error and leaves no partial account', function () {
    $actor = User::factory()->role('superadmin')->create();
    $existing = User::factory()->role('requester')->create(['phone' => '998900008886']);
    $this->actingAs($actor)->post(route('users.store'), ['full_name' => 'Duplicate Account', 'phone' => '+998 90 000 88 86', 'role' => 'requester'])
        ->assertSessionHasErrors('phone');
    expect(User::count())->toBe(2)->and($existing->fresh()->phone)->toBe('998900008886');
    $this->postJson(route('users.store'), ['full_name' => 'Duplicate Account', 'phone' => $existing->phone, 'role' => 'requester'])
        ->assertUnprocessable()->assertJsonValidationErrors('phone');
});

test('a database phone uniqueness race becomes validation and rolls back the transaction', function () {
    User::factory()->role('requester')->create(['phone' => '+998900008883']);
    $role = \App\Models\Role::where('name', 'requester')->firstOrFail();
    expect(fn () => app(PhoneIdentity::class)->persist(function () use ($role) {
        User::create(['phone' => '+998900008882', 'role_id' => $role->id]);
        User::create(['phone' => '998(90)000-88-83', 'role_id' => $role->id]);
    }))->toThrow(ValidationException::class);
    expect(User::count())->toBe(1);
});

test('phone migration preserves legacy identifiers and reports collisions without choosing an owner', function () {
    $one = User::factory()->role('requester')->create(['phone' => '+998 90 000 88 81']);
    $two = User::factory()->role('requester')->create(['phone' => '+998900008880']);
    $unique = User::factory()->role('requester')->create(['phone' => '+998 (90) 000-88-79']);
    $migration = require database_path('migrations/2026_10_13_000001_add_canonical_phone_identity.php');
    $migration->down();
    DB::table('users')->where('id', $two->id)->update(['phone' => '998900008881']);
    $migration->up();
    expect($one->fresh()->phone)->toBe('+998 90 000 88 81')->and($one->fresh()->phone_normalized)->toBeNull()
        ->and($two->fresh()->phone_normalized)->toBeNull()->and($unique->fresh()->phone_normalized)->toBe('998900008879');
    expect(app(PhoneIdentity::class)->matches('998900008881'))->toHaveCount(2);
    expect(Artisan::call('accounts:audit-phones'))->toBe(1);
    expect(Artisan::output())->toContain('Ambiguous phone');
    $this->post(route('password.email'), ['phone' => '998900008881'])->assertSessionHas('status');
    expect($this->botSpy->messages)->toBeEmpty();
});

test('deactivation pauses current and future unfinished approvals and identifies the signer', function (int $level) {
    $admin = User::factory()->role('superadmin')->create();
    $applicant = User::factory()->role('requester')->create();
    $signer = User::factory()->role('approver')->create();
    $request = ApprovalRequest::create(['applicant_id' => $applicant->id, 'project_id' => makeProject()->id,
        'type' => 'approval', 'name' => 'Pending', 'status' => 'pending_approval', 'expected_approval_level' => 1]);
    $request->approvals()->create(['approver_id' => $signer->id, 'level' => $level, 'status' => 'pending']);
    $this->actingAs($admin)->patch(route('users.activation', $signer), ['is_active' => false])
        ->assertSessionHasNoErrors();
    expect($signer->fresh()->is_active)->toBeFalse()->and($request->fresh()->status)->toBe('approval_paused')
        ->and($request->fresh()->route_unavailable[0]['id'])->toBe($signer->id);
    $request->update(['status' => 'rejected']);
    $this->patch(route('users.activation', $signer), ['is_active' => false])->assertSessionHasNoErrors();
    expect($signer->fresh()->is_active)->toBeFalse()->and($request->approvals()->count())->toBe(1);
})->with([1, 2]);

test('failed Word downloads produce no secret in the application log', function () {
    $document = new Document;
    $document->file_id = 'audit-file-id';
    $document->file_name = 'audit.docx';
    $document->file_size = 10;
    $message = new Message;
    $message->document = $document;
    $bot = Mockery::mock(Nutgram::class);
    $bot->shouldReceive('message')->andReturn($message);
    $bot->shouldReceive('userId')->andReturn(991111);
    $bot->shouldReceive('sendMessage')->andReturnNull();
    $cache = Mockery::mock(TelegramFileCache::class);
    $cache->shouldReceive('resolve')->once()->with($document->file_id)->andThrow(new RuntimeException('https://api.telegram.org/bot123456:AUDIT_ONLY_TOKEN/file'));
    app()->instance(TelegramFileCache::class, $cache);
    Log::spy();
    (new \App\Telegram\Conversations\CreateManagementDocument)->handleUpload($bot);
    Log::shouldHaveReceived('error')->once()->with('Management document upload failed', ['exception_type' => RuntimeException::class]);
});

test('imports use an independent temporary copy and retain the shared private cache', function () {
    $id = 'audit_file_1234567';
    $cached = 'telegram-cache/'.hash('sha256', $id);
    Storage::disk('local')->put($cached, 'cached-file');
    $copy = app(TelegramFileCache::class)->temporaryCopy($id);
    try {
        expect(file_get_contents($copy))->toBe('cached-file');
        file_put_contents($copy, 'changed-import');
        expect(Storage::disk('local')->get($cached))->toBe('cached-file');
    } finally {
        unlink($copy);
    }
    Storage::disk('local')->assertExists($cached);
});

class ResidualExpenseImportProbe extends \App\Telegram\Conversations\CreateApprovalRequest
{
    public bool $ready = false;

    protected function askSupplierAfterItems(Nutgram $bot)
    {
        $this->ready = true;
    }

    protected function askNextItem(Nutgram $bot) {}
}

class ResidualSupplyImportProbe extends \App\Telegram\Conversations\CreateSupplyRequest
{
    public bool $ready = false;

    protected function askDeadline(Nutgram $bot)
    {
        $this->ready = true;
    }

    protected function askNextItem(Nutgram $bot) {}
}

test('expense and supply imports read cached spreadsheets and clean up temporary files', function (string $flow, string $extension) {
    $temporary = tempnam(sys_get_temp_dir(), 'teg-test-');
    if ($extension === 'csv') {
        file_put_contents($temporary, "name,quantity,price,description\nOffice chair,2,15.50,imported\n");
    } else {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([['name', 'quantity', 'price', 'description'], ['Office chair', 2, 15.50, 'imported']]);
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($temporary);
    }
    $document = new Document;
    $document->file_id = 'audit_excel_1234567';
    $document->file_name = '../untrusted-name.'.$extension;
    $message = new Message;
    $message->document = $document;
    $bot = Mockery::mock(Nutgram::class);
    $bot->shouldReceive('message')->andReturn($message);
    $bot->shouldReceive('userId')->andReturn(991222);
    $bot->shouldReceive('sendMessage')->andReturnNull();
    $cache = Mockery::mock(TelegramFileCache::class);
    $cache->shouldReceive('temporaryCopy')->once()->with($document->file_id)->andReturn($temporary);
    app()->instance(TelegramFileCache::class, $cache);
    $conversation = $flow === 'expense' ? new ResidualExpenseImportProbe : new ResidualSupplyImportProbe;
    try {
        $conversation->handleExcelUpload($bot);
        expect($conversation->ready)->toBeTrue()->and($conversation->items)->toHaveCount(1)
            ->and($conversation->items[0]['name'])->toBe('Office chair')->and($conversation->items[0]['quantity'])->toBe(2.0);
        expect(is_file($temporary))->toBeFalse();
    } finally {
        if (is_file($temporary)) {
            unlink($temporary);
        }
    }
})->with([['expense', 'csv'], ['expense', 'xlsx'], ['supply', 'csv'], ['supply', 'xlsx']]);
