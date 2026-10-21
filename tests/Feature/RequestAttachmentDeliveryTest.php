<?php

use App\Models\ApprovalRequest;
use App\Services\RequestCreationNotifier;
use App\Services\RequestEffects;
use Illuminate\Support\Facades\DB;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use SergiX44\Nutgram\Telegram\Types\Media\File;

beforeEach(function () {
    seedRoles();
    $this->author = makeUserWithRole('requester');
    $this->approver = makeUserWithRole('approver');
    $project = makeProject();
    $this->author->projects()->attach($project);
    $this->approver->projects()->attach($project);
    $this->request = ApprovalRequest::create([
        'applicant_id' => $this->author->id, 'project_id' => $project->id,
        'type' => 'approval', 'status' => 'pending_approval', 'name' => 'Image document',
        'expected_approval_level' => 1,
    ]);
    $this->request->approvals()->create(['approver_id' => $this->approver->id, 'level' => 1, 'status' => 'pending']);
    $this->attachment = $this->request->attachments()->create(['file_type' => 'jpg', 'telegram_file_id' => 'image-document']);
    $this->bot = Mockery::mock(Nutgram::class);
    app()->instance(Nutgram::class, $this->bot);
});

test('legacy jpg documents complete delivery without repeating notifications', function () {
    $this->bot->shouldReceive('sendMessage')->twice();
    $this->bot->shouldReceive('sendPhoto')->once()->andThrow(new TelegramException('Bad Request: document cannot be sent as photo', 400));
    $metadata = new File;
    $metadata->file_path = 'documents/file_1.jpg';
    $this->bot->shouldReceive('getFile')->once()->with('image-document')->andReturn($metadata);
    $this->bot->shouldReceive('sendDocument')->once()->with('image-document', $this->approver->telegram_id, null, null, '📎 Вложение к заявке #'.$this->request->id);
    $effects = app(RequestEffects::class);
    $effects->record($this->request, 'approved:0');
    $id = DB::table('request_effects')->value('id');
    $effects->deliver($id);
    $effects->deliver($id);
    $effect = DB::table('request_effects')->find($id);
    expect($effect->completed_at)->not->toBeNull()
        ->and(json_decode($effect->completed_steps))->toContain('file:'.$this->approver->id.':'.$this->attachment->id);
});

test('photo failures do not switch to document sending on transport or recipient errors', function (int $code) {
    $this->bot->shouldReceive('sendPhoto')->once()->andThrow(new TelegramException('Unavailable', $code));
    $this->bot->shouldNotReceive('getFile');
    $this->bot->shouldNotReceive('sendDocument');
    expect(fn () => app(RequestCreationNotifier::class)->forwardAttachments($this->request, $this->approver, true))
        ->toThrow(TelegramException::class);
})->with([403, 429, 500]);

test('a rejected actual photo remains pending instead of being sent as a document', function () {
    $this->bot->shouldReceive('sendPhoto')->once()->andThrow(new TelegramException('Bad Request', 400));
    $metadata = new File;
    $metadata->file_path = 'photos/file_1.jpg';
    $this->bot->shouldReceive('getFile')->once()->with('image-document')->andReturn($metadata);
    $this->bot->shouldNotReceive('sendDocument');
    expect(fn () => app(RequestCreationNotifier::class)->forwardAttachments($this->request, $this->approver, true))
        ->toThrow(TelegramException::class);
});

test('Telegram creation keeps image documents distinct from photos', function (string $conversationClass, bool $photo) {
    $message = new \SergiX44\Nutgram\Telegram\Types\Message\Message;
    if ($photo) {
        $file = new \SergiX44\Nutgram\Telegram\Types\Media\PhotoSize;
        $file->file_id = 'uploaded-photo';
        $message->photo = [$file];
    } else {
        $file = new \SergiX44\Nutgram\Telegram\Types\Media\Document;
        $file->file_id = 'uploaded-document';
        $file->mime_type = 'image/jpeg';
        $message->document = $file;
    }
    $this->bot->shouldReceive('userId')->andReturn($this->author->telegram_id);
    $this->bot->shouldReceive('isCallbackQuery')->andReturn(false);
    $this->bot->shouldReceive('message')->andReturn($message);
    $this->bot->shouldReceive('sendMessage')->once();
    $conversation = Mockery::mock($conversationClass)->makePartial()->shouldAllowMockingProtectedMethods();
    $conversation->shouldReceive('isCancelled')->andReturn(false);
    $conversation->shouldReceive('next')->andReturnSelf();
    $conversation->handleAttachment($this->bot);
    expect($conversation->attachmentFileIds)->toHaveCount(1);
    expect($conversation->attachmentFileIds[0])->toMatchArray([
        'file_id' => $photo ? 'uploaded-photo' : 'uploaded-document', 'file_type' => $photo ? 'jpg' : 'document',
    ]);
})->with([
    [\App\Telegram\Conversations\CreateApprovalRequest::class, false],
    [\App\Telegram\Conversations\CreateApprovalRequest::class, true],
    [\App\Telegram\Conversations\CreateSupplyRequest::class, false],
    [\App\Telegram\Conversations\CreateSupplyRequest::class, true],
]);
