<?php

use App\Models\User;

test('the home page sends guests to sign in', function () {
    $this->get(route('home'))->assertRedirect(route('login'));
});

test('the home page opens the primary workspace for each role', function (string $role, string $destination) {
    seedRoles();
    $this->actingAs(User::factory()->role($role)->create())
        ->get(route('home'))
        ->assertRedirect($destination);
})->with([
    ['supplier', '/supply'],
    ['requester', '/dashboard'],
    ['admin', '/dashboard'],
]);
