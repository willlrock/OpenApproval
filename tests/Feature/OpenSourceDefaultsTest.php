<?php

use App\Models\Department;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use SergiX44\Nutgram\Nutgram;

test('fresh installation seeds neutral reference data without accounts and can be repeated', function () {
    $this->seed(DatabaseSeeder::class);
    $project = Project::sole();
    expect($project->name)->toBe('Demo project')
        ->and(User::count())->toBe(0)
        ->and(Department::count())->toBeGreaterThan(0);

    $this->seed(DatabaseSeeder::class);
    expect(Project::count())->toBe(1)->and(User::count())->toBe(0);
});

test('registration approval notifies with an optional channel invitation', function (?string $invite) {
    seedRoles();
    $this->withoutVite();
    config(['telegram.channel_invite_url' => $invite]);
    $admin = makeUserWithRole('superadmin');
    $user = makeUserWithRole('requester', ['account_status' => 'new']);
    $project = makeProject();
    $department = Department::create(['name' => 'Demo department', 'project_id' => $project->id]);
    $messages = [];
    $bot = Mockery::mock(Nutgram::class);
    $bot->shouldReceive('sendMessage')->once()->andReturnUsing(function (...$args) use (&$messages) {
        $messages[] = $args;
    });
    app()->instance(Nutgram::class, $bot);

    $this->actingAs($admin)->post(route('registrations.approve', $user->id), [
        'role_id' => $user->role_id,
        'project_ids' => [$project->id],
        'department_ids' => [$department->id],
    ])->assertSessionHasNoErrors()->assertRedirect();

    expect($user->fresh()->account_status)->toBe('approved');
    expect($messages)->toHaveCount(1);
    expect($messages[0][1])->toBe($user->telegram_id);
    if ($invite === null || $invite === '') {
        expect($messages[0][12])->toBeNull();
    } else {
        expect($messages[0][12]->inline_keyboard[0][0]->url)->toBe($invite);
    }
})->with([null, '', 'https://t.me/example_channel']);
