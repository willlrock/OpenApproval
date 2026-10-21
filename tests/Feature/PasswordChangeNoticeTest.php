<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    seedRoles();
    $this->withoutVite();
});

test('legacy default password cannot sign in and an existing session must change it immediately', function () {
    $user = makeUserWithRole('accountant', ['password' => 'admin123']);
    $this->travelTo(now()->startOfSecond());
    $this->post(route('login.store'), ['phone' => $user->phone, 'password' => 'admin123'])->assertSessionHasErrors('phone');
    $this->assertGuest();
    $this->actingAs($user)->get(route('profile.edit'))->assertRedirect(route('user-password.edit'));
    $this->get(route('finance.requests'))->assertRedirect(route('user-password.edit'));
});

test('personal passwords do not trigger a notice or deadline', function () {
    $user = makeUserWithRole('accountant', ['password' => 'AlreadyPersonal-2026']);
    $this->actingAs($user)->get(route('profile.edit'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('passwordChangeNotice', null));
    expect($user->fresh()->password_change_due_at)->toBeNull();
});

test('an expired deadline allows password change and restores access afterwards', function () {
    $user = makeUserWithRole('accountant', [
        'password' => 'admin123', 'password_change_required' => true, 'password_change_due_at' => now()->subMinute(),
    ]);
    $this->actingAs($user)->get(route('finance.requests'))->assertRedirect(route('user-password.edit'));
    $this->get(route('user-password.edit'))->assertOk();
    $this->put(route('user-password.update'), [
        'current_password' => 'admin123', 'password' => 'Personal-2026-Secret', 'password_confirmation' => 'Personal-2026-Secret',
    ])->assertSessionHasNoErrors();
    expect($user->fresh()->password_change_required)->toBeFalse()
        ->and($user->fresh()->password_change_due_at)->toBeNull()
        ->and(Hash::check('Personal-2026-Secret', $user->fresh()->password))->toBeTrue();
    $this->get(route('finance.requests'))->assertOk();
});

test('a bad current password leaves the notice active and default password cannot be selected again', function () {
    $user = makeUserWithRole('accountant', ['password' => 'admin123', 'password_change_required' => true]);
    $this->actingAs($user)->put(route('user-password.update'), [
        'current_password' => 'wrong', 'password' => 'Personal-2026', 'password_confirmation' => 'Personal-2026',
    ])->assertSessionHasErrors('current_password');
    expect($user->fresh()->password_change_required)->toBeTrue();
    $this->put(route('user-password.update'), [
        'current_password' => 'admin123', 'password' => 'admin123', 'password_confirmation' => 'admin123',
    ])->assertSessionHasErrors('password');
});

test('new accounts receive a unique temporary password and a notice', function () {
    $admin = makeUserWithRole('superadmin');
    $response = $this->actingAs($admin)->post(route('users.store'), [
        'full_name' => 'New accountant', 'phone' => '+998900009901', 'role' => 'accountant',
    ])->assertSessionHasNoErrors()->assertSessionHas('temporary_credential');
    $password = session('temporary_credential.password');
    $user = User::where('phone', '+998900009901')->firstOrFail();
    expect($password)->not->toBe('admin123')->toHaveLength(20)
        ->and(Hash::check($password, $user->password))->toBeTrue()
        ->and($user->password_change_required)->toBeTrue();
    $this->actingAs($user)->get(route('profile.edit'))->assertRedirect(route('user-password.edit'));
    $this->get(route('user-password.edit'))->assertOk();
});

test('approving a requester enables web sign-in without changing their role', function () {
    $bot = fakeBot();
    $admin = makeUserWithRole('superadmin');
    $requester = makeUserWithRole('requester', [
        'phone' => '998900009902', 'password' => null, 'account_status' => 'pending',
    ]);
    $project = makeProject('Requester access');
    $department = \App\Models\Department::create(['name' => 'Office', 'project_id' => $project->id]);

    $this->actingAs($admin)->post(route('registrations.approve', $requester->id), [
        'role_id' => $requester->role_id,
        'project_ids' => [$project->id],
        'department_ids' => [$department->id],
    ])->assertSessionHasNoErrors()->assertSessionHas('temporary_credential');

    $password = session('temporary_credential.password');
    expect($requester->fresh()->role)->toBe('requester')
        ->and($requester->fresh()->isApproved())->toBeTrue()
        ->and(Hash::check($password, $requester->fresh()->password))->toBeTrue()
        ->and($requester->fresh()->password_change_required)->toBeTrue()
        ->and($bot->messagesTo($requester->telegram_id)[0]['text'])->toContain(route('password.request'));
});
