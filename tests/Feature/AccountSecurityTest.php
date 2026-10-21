<?php

use App\Models\Role;
use App\Models\User;
use App\Services\TelegramAccountLink;
use App\Services\UserAdministration;
use App\Telegram\Conversations\RegisterUser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Media\Contact;
use SergiX44\Nutgram\Telegram\Types\Message\Message;

beforeEach(function () {
    seedRoles();
    $this->withoutVite();
    Storage::fake('public');
    Storage::fake('local');
});

test('a typed phone cannot link or modify an existing privileged account', function () {
    $victim = makeUserWithRole('superadmin', ['telegram_id' => null, 'phone' => '+998900005501']);
    $attacker = makeUserWithRole('requester', ['account_status' => 'new']);
    $message = new Message;
    $message->text = '998900005501';
    $bot = Mockery::mock(Nutgram::class);
    $bot->shouldReceive('message')->andReturn($message);
    $bot->shouldReceive('userId')->andReturn($attacker->telegram_id);
    $bot->shouldReceive('sendMessage')->once()->with(Mockery::on(fn ($text) => str_contains($text, 'код привязки')));
    (new RegisterUser)->handlePhone($bot);
    expect($victim->fresh()->telegram_id)->toBeNull()->and($victim->fresh()->role)->toBe('superadmin')
        ->and($attacker->fresh())->not->toBeNull();
});

test('registration rejects somebody elses contact before using their phone', function () {
    $contact = new Contact;
    $contact->phone_number = '998900005502';
    $contact->user_id = 200;
    $message = new Message;
    $message->contact = $contact;
    $bot = Mockery::mock(Nutgram::class);
    $bot->shouldReceive('message')->andReturn($message);
    $bot->shouldReceive('userId')->andReturn(100);
    $bot->shouldReceive('sendMessage')->once()->with('Отправьте свой контакт кнопкой ниже.');
    $conversation = new RegisterUser;
    $conversation->handlePhone($bot);
    expect($conversation->phone)->toBeNull();
});

test('website link codes are expiring single use and cannot replace another account', function () {
    $user = makeUserWithRole('superadmin', ['telegram_id' => null]);
    $links = app(TelegramAccountLink::class);
    $token = $links->issue($user);
    expect($user->fresh()->telegram_link_token_hash)->not->toBe($token);
    $links->consume($token, 123456, 'verified');
    expect($user->fresh()->telegram_id)->toBe(123456)->and($user->fresh()->telegram_link_token_hash)->toBeNull();
    expect(fn () => $links->consume($token, 654321, 'replay'))->toThrow(ValidationException::class);
    $other = makeUserWithRole('requester', ['telegram_id' => null]);
    $otherToken = $links->issue($other);
    expect(fn () => $links->consume($otherToken, 123456, 'takeover'))->toThrow(ValidationException::class);
    $this->travel(11)->minutes();
    expect(fn () => $links->consume($otherToken, 654321, 'expired'))->toThrow(ValidationException::class);
});

test('a link may remove only an untouched start placeholder', function () {
    $target = makeUserWithRole('requester', ['telegram_id' => null]);
    $placeholder = User::create(['role_id' => Role::where('name', 'requester')->value('id'), 'telegram_id' => 123456, 'account_status' => 'new']);
    $links = app(TelegramAccountLink::class);
    $links->consume($links->issue($target), 123456, 'linked');
    expect($placeholder->fresh())->toBeNull()->and($target->fresh()->telegram_id)->toBe(123456);
});

test('link creation requires an approved session and recent password confirmation', function () {
    $user = makeUserWithRole('requester', ['telegram_id' => null, 'password' => 'Personal2026']);
    $this->post(route('telegram-link.store'))->assertRedirect(route('login'));
    $this->actingAs($user)->from(route('profile.edit'))->post(route('telegram-link.store'))->assertRedirect(route('password.confirm'));
    $this->post(route('password.confirm.store'), ['password' => 'Personal2026'])->assertRedirect(route('profile.edit'));
    $this->post(route('telegram-link.store'))
        ->assertSessionHas('telegram_link_code')->assertSessionHasNoErrors();
});

test('default seeding creates no accounts and bootstrap creates one unique credential', function () {
    $this->seed();
    expect(User::count())->toBe(0);
    expect(Artisan::call('admin:bootstrap', ['--phone' => '+998900005503', '--name' => 'First Admin']))->toBe(0);
    preg_match('/Temporary password: (\S+)/', Artisan::output(), $matches);
    $user = User::where('phone', '998900005503')->firstOrFail();
    expect($user->role)->toBe('superadmin')->and($user->password_change_required)->toBeTrue()
        ->and(Hash::check($matches[1], $user->password))->toBeTrue()->and($matches[1])->not->toBe('admin123');
    expect(Artisan::call('admin:bootstrap', ['--phone' => '998900005504', '--name' => 'Second Admin']))->toBe(1);
    expect(User::count())->toBe(1);
});

test('an ordinary admin cannot demote deactivate or replace a superadmin signature', function () {
    $admin = makeUserWithRole('admin');
    $super = makeUserWithRole('superadmin', ['signature_path' => 'signatures/original.png']);
    Storage::disk('local')->put($super->signature_path, 'original');
    $this->actingAs($admin)->put(route('users.update', $super->id), ['role' => 'requester'])->assertForbidden();
    $this->patch(route('users.activation', $super->id), ['is_active' => false])->assertForbidden();
    $this->post(route('users.signature.upload', $super->id), ['signature' => UploadedFile::fake()->image('sig.png')])->assertForbidden();
    $this->delete(route('users.signature.delete', $super->id))->assertForbidden();
    expect($super->fresh()->role)->toBe('superadmin')->and(Storage::disk('local')->get($super->signature_path))->toBe('original');
});

test('the last approved superadmin cannot be demoted rejected or deactivated', function () {
    $super = makeUserWithRole('superadmin');
    $this->actingAs($super)->put(route('users.update', $super->id), ['role' => 'requester'])->assertSessionHasErrors('role');
    $this->patch(route('users.activation', $super->id), ['is_active' => false])->assertSessionHasErrors('is_active');
    expect(fn () => app(UserAdministration::class)->update($super, $super->id, ['account_status' => 'rejected']))->toThrow(ValidationException::class);
    $replacement = makeUserWithRole('superadmin');
    $this->actingAs($replacement)->put(route('users.update', $super->id), ['role' => 'requester'])->assertSessionHasNoErrors();
    expect($super->fresh()->role)->toBe('requester');
});

test('current signatures have private authenticated URLs and migration removes public copies', function () {
    $admin = makeUserWithRole('superadmin');
    $user = makeUserWithRole('approver', ['signature_path' => 'signatures/legacy.png']);
    Storage::disk('public')->put($user->signature_path, 'legacy-signature');
    $this->artisan('requests:privatize-files')->assertExitCode(0);
    Storage::disk('public')->assertMissing($user->signature_path);
    $this->get(route('users.signature.show', $user->id))->assertRedirect(route('login'));
    $this->actingAs($user)->get(route('users.signature.show', $user->id))->assertForbidden();
    $this->actingAs($admin)->get(route('users.signature.show', $user->id))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $this->post(route('users.signature.upload', $user->id), ['signature' => UploadedFile::fake()->image('new.png')])->assertSessionHasNoErrors();
    Storage::disk('local')->assertExists($user->fresh()->signature_path);
    Storage::disk('public')->assertMissing($user->fresh()->signature_path);
});

test('a conflicting private signature is never overwritten by migration', function () {
    Storage::disk('public')->put('signatures/conflict.png', 'public');
    Storage::disk('local')->put('signatures/conflict.png', 'private');
    $this->artisan('requests:privatize-files')->assertExitCode(1);
    expect(Storage::disk('local')->get('signatures/conflict.png'))->toBe('private');
    Storage::disk('public')->assertExists('signatures/conflict.png');
});

test('console password recovery revokes web credentials while preserving account privileges', function () {
    $user = makeUserWithRole('superadmin', ['phone' => '998900005506', 'password' => 'admin123', 'remember_token' => 'old-remember']);
    DB::table('sessions')->insert(['id' => 'old-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
    DB::table('web_password_recoveries')->insert(['user_id' => $user->id, 'token_hash' => str_repeat('a', 64), 'expires_at' => now()->addMinutes(30)]);
    expect(Artisan::call('account:reset-password', ['--phone' => '+998900005506']))->toBe(0);
    preg_match('/Temporary password: (\S+)/', Artisan::output(), $matches);
    expect(Hash::check($matches[1], $user->fresh()->password))->toBeTrue()
        ->and($user->fresh()->role)->toBe('superadmin')->and($user->fresh()->telegram_id)->toBe($user->telegram_id)
        ->and($user->fresh()->password_change_required)->toBeTrue()->and($user->fresh()->remember_token)->not->toBe('old-remember');
    expect(DB::table('sessions')->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(DB::table('web_password_recoveries')->where('user_id', $user->id)->exists())->toBeFalse();
    $this->post(route('login'), ['phone' => $user->phone, 'password' => $matches[1]])->assertRedirect(route('user-password.edit'));
});

test('changing a web password revokes an outstanding Telegram link code', function () {
    $user = makeUserWithRole('requester', ['telegram_id' => null, 'password' => 'Personal2026']);
    $token = app(TelegramAccountLink::class)->issue($user);
    $this->actingAs($user)->put(route('user-password.update'), [
        'current_password' => 'Personal2026', 'password' => 'NewPersonal2026!', 'password_confirmation' => 'NewPersonal2026!',
    ])->assertSessionHasNoErrors();
    expect($user->fresh()->telegram_link_token_hash)->toBeNull();
    expect(fn () => app(TelegramAccountLink::class)->consume($token, 999123, null))->toThrow(ValidationException::class);
});

test('the real Telegram link command consumes a code and the removed bootstrap grants no role', function () {
    $user = makeUserWithRole('requester', ['telegram_id' => null]);
    $token = app(TelegramAccountLink::class)->issue($user);
    $bot = app(Nutgram::class);
    $bot->hearMessage(['text' => '/link '.$token, 'from' => ['id' => 123456, 'username' => 'linked'], 'chat' => ['id' => 123456, 'type' => 'private']])->reply();
    expect($user->fresh()->telegram_id)->toBe(123456);
    $bot->hearMessage(['text' => '/make_me_superadmin', 'from' => ['id' => 123456], 'chat' => ['id' => 123456, 'type' => 'private']])->reply();
    expect($user->fresh()->role)->toBe('requester')->and(User::whereHas('roleRelation', fn ($q) => $q->where('name', 'superadmin'))->count())->toBe(0);
});
