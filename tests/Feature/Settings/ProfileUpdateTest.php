<?php

use App\Models\User;

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('profile.edit'));

    $response->assertOk();
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'full_name' => 'Test User',
            'phone' => '+998900006601',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    $user->refresh();

    expect($user->full_name)->toBe('Test User');
    expect($user->phone)->toBe('+998900006601');

});

test('the existing phone can be preserved while changing the name', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'full_name' => 'Test User',
            'phone' => $user->phone,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->full_name)->toBe('Test User');
});

test('account deletion is unavailable from profile settings', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->delete('/settings/profile', ['password' => 'password'])->assertStatus(405);
    expect($user->fresh()->is_active)->toBeTrue();
    $this->assertAuthenticatedAs($user);
});
