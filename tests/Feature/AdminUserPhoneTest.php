<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    seedRoles();
    $this->withoutVite();
    $this->admin = User::factory()->role('superadmin')->create();
});

test('user cards include the registered login phone', function () {
    $employee = User::factory()->create(['phone' => '+998 (97) 005-40-90']);

    $this->actingAs($this->admin)->get(route('users'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Users')
            ->where('users.data', fn ($users) => collect($users)->contains(
                fn ($user) => $user['id'] === $employee->id && $user['phone'] === $employee->phone
            )));
});

test('an admin can correct a login phone while preserving Telegram and password', function () {
    $employee = User::factory()->create(['phone' => '+998970054091', 'telegram_id' => 970054090]);
    $passwordHash = $employee->password;
    $spy = fakeBot();

    $this->actingAs($this->admin)->put(route('users.update', $employee), ['phone' => '+998 (97) 005-40-90'])
        ->assertRedirect()->assertSessionHasNoErrors();

    $employee->refresh();
    expect($employee->phone)->toBe('+998 (97) 005-40-90')
        ->and($employee->phone_normalized)->toBe('998970054090')
        ->and($employee->telegram_id)->toBe(970054090)
        ->and($employee->password)->toBe($passwordHash)
        ->and($employee->role)->toBe('requester');

    $this->post(route('logout'));
    $this->post(route('login.store'), ['phone' => '+998970054090', 'password' => 'password'])
        ->assertSessionHasNoErrors();
    $this->assertAuthenticatedAs($employee);
    $this->post(route('logout'));
    $this->post(route('password.email'), ['phone' => '+998970054091'])->assertSessionHas('status');
    expect($spy->messages)->toBeEmpty();
    $this->post(route('password.email'), ['phone' => '+998970054090'])->assertSessionHas('status');
    expect($spy->messagesTo($employee->telegram_id))->toHaveCount(1);
});

test('phone edits reject another accounts number regardless of formatting', function (string $phone) {
    User::factory()->create(['phone' => '+998970054090']);
    $employee = User::factory()->create(['phone' => '+998970054091']);

    $this->actingAs($this->admin)->put(route('users.update', $employee), ['phone' => $phone])
        ->assertSessionHasErrors('phone');

    expect($employee->fresh()->phone)->toBe('+998970054091')
        ->and($employee->fresh()->phone_normalized)->toBe('998970054091');
})->with(['998970054090', '+998 (97) 005-40-90']);

test('a user can retain their own phone in a different format', function () {
    $employee = User::factory()->create(['phone' => '+998970054090']);

    $this->actingAs($this->admin)->put(route('users.update', $employee), ['phone' => '998 97 005 40 90'])
        ->assertSessionHasNoErrors();

    expect($employee->fresh()->phone_normalized)->toBe('998970054090');
});

test('invalid phone edits leave the account unchanged', function (mixed $phone) {
    $employee = User::factory()->create(['phone' => '+998970054090']);

    $this->actingAs($this->admin)->put(route('users.update', $employee), ['phone' => $phone])
        ->assertSessionHasErrors('phone');

    expect($employee->fresh()->phone)->toBe('+998970054090');
})->with(['', '123', '+998970054090abc', null]);

test('ordinary admins can correct employees phones but cannot change administrative phones', function () {
    $actor = User::factory()->role('admin')->create();
    $employee = User::factory()->create();

    $this->actingAs($actor)->put(route('users.update', $employee), ['phone' => '+998970054090'])
        ->assertSessionHasNoErrors();
    expect($employee->fresh()->phone_normalized)->toBe('998970054090');

    foreach (['admin', 'superadmin'] as $role) {
        $target = User::factory()->role($role)->create();
        $originalPhone = $target->phone;
        $this->put(route('users.update', $target), ['phone' => '+998970054092'])->assertForbidden();
        expect($target->fresh()->phone)->toBe($originalPhone);
    }
});

test('updating other user fields preserves a missing phone', function () {
    $employee = User::factory()->create(['phone' => null]);

    $this->actingAs($this->admin)->put(route('users.update', $employee), ['title' => 'Manager'])
        ->assertSessionHasNoErrors();

    expect($employee->fresh()->phone)->toBeNull()->and($employee->fresh()->title)->toBe('Manager');
});
