<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    seedRoles();
    $this->withoutVite();
    $this->botSpy = fakeBot();
});

test('recovery goes to the linked Telegram and the token is single use', function () {
    $user = makeUserWithRole('accountant', ['phone' => '+998900007701', 'password' => 'admin123', 'password_change_required' => true]);
    $this->post(route('password.email'), ['phone' => $user->phone])->assertSessionHasNoErrors();
    $messages = $this->botSpy->messagesTo($user->telegram_id);
    expect($messages)->toHaveCount(1);
    preg_match('~/reset-password/([a-zA-Z0-9]{64})~', $messages[0]['text'], $matches);
    $token = $matches[1];
    expect(DB::table('web_password_recoveries')->value('token_hash'))->toBe(hash('sha256', $token));
    $this->get(route('password.reset', $token))->assertOk();
    $payload = ['token' => $token, 'password' => 'New-Personal-2026', 'password_confirmation' => 'New-Personal-2026'];
    $this->post(route('password.update'), $payload)->assertRedirect(route('login'))->assertSessionHasNoErrors();
    expect(Hash::check($payload['password'], $user->fresh()->password))->toBeTrue()
        ->and($user->fresh()->password_change_required)->toBeFalse();
    $this->post(route('password.update'), $payload)->assertSessionHasErrors('token');
});

test('an approved requester without a web password can set one through Telegram', function () {
    $user = makeUserWithRole('requester', ['phone' => '998900007704', 'password' => null]);
    $this->post(route('password.email'), ['phone' => '+998 90 000 77 04'])->assertSessionHasNoErrors();
    $message = $this->botSpy->messagesTo($user->telegram_id)[0]['text'];
    preg_match('~/reset-password/([a-zA-Z0-9]{64})~', $message, $matches);

    $this->post(route('password.update'), [
        'token' => $matches[1], 'password' => 'newpass1',
        'password_confirmation' => 'newpass1',
    ])->assertRedirect(route('login'))->assertSessionHasNoErrors();
    $this->post(route('login.store'), [
        'phone' => $user->phone, 'password' => 'newpass1',
    ])->assertRedirect('/dashboard');
    $this->assertAuthenticatedAs($user);
});

test('phone formatting cannot bypass the recovery rate limit or select an ambiguous account', function () {
    $user = makeUserWithRole('requester', ['phone' => '998900007705']);
    $this->post(route('password.email'), ['phone' => '998900007705'])->assertSessionHas('status');
    $this->post(route('password.email'), ['phone' => '+998 90 000 77 05'])->assertSessionHas('status');
    expect($this->botSpy->messagesTo($user->telegram_id))->toHaveCount(1);

    $first = makeUserWithRole('requester', ['phone' => '998900007706']);
    // Simulate an ambiguity created before canonical identity was enforced.
    DB::table('users')->where('id', $first->id)->update(['phone_normalized' => null]);
    $second = makeUserWithRole('requester', ['phone' => '+998900007706']);
    DB::table('users')->where('id', $second->id)->update(['phone_normalized' => null]);
    $this->post(route('password.email'), ['phone' => '998900007706'])->assertSessionHas('status');
    expect($this->botSpy->messagesTo($first->telegram_id))->toBeEmpty()
        ->and($this->botSpy->messagesTo($second->telegram_id))->toBeEmpty();
});

test('unknown and rejected accounts receive the same neutral response and no recovery link', function () {
    $user = makeUserWithRole('accountant', ['phone' => '+998900007702', 'account_status' => 'rejected']);
    $this->post(route('password.email'), ['phone' => $user->phone])->assertSessionHas('status');
    $message = session('status');
    $this->post(route('password.email'), ['phone' => '+998900007703'])->assertSessionHas('status', $message);
    expect($this->botSpy->messages)->toBeEmpty();
    $this->assertDatabaseCount('web_password_recoveries', 0);
});

test('expired recovery tokens cannot change passwords', function () {
    $user = makeUserWithRole('accountant', ['password' => 'Personal-Original']);
    $token = str_repeat('a', 64);
    DB::table('web_password_recoveries')->insert(['user_id' => $user->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->subMinute()]);
    $this->post(route('password.update'), [
        'token' => $token, 'password' => 'Different-Password1', 'password_confirmation' => 'Different-Password1',
    ])->assertSessionHasErrors('token');
    expect(Hash::check('Personal-Original', $user->fresh()->password))->toBeTrue();
});
