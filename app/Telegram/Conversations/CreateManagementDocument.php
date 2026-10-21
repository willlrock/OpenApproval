<?php

namespace App\Telegram\Conversations;

use App\Concerns\ConversationHelpers;
use App\Helpers\BotLang;
use App\Models\Project;
use App\Models\User;
use App\Services\ManagementDocumentService;
use App\Telegram\Menu;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Internal\InputFile;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class CreateManagementDocument extends Conversation
{
    use ConversationHelpers;

    public ?int $projectId = null;

    public ?string $documentKind = null;

    public ?string $title = null;

    public ?string $effectiveDateMode = null;

    public ?string $effectiveDate = null;

    public ?string $submissionKey = null;

    public ?string $sourceDocxPath = null;

    public ?string $previewPdfPath = null;

    public ?string $sourceSha256 = null;

    public array $approvers = [];

    public function start(Nutgram $bot): void
    {
        $user = User::where('telegram_id', $bot->userId())->first();
        if (! $user) {
            $this->end();

            return;
        }

        if (! $user->signature_path || ! Storage::disk('local')->exists($user->signature_path)) {
            $bot->sendMessage(__('bot.md_applicant_signature_missing', [], $user->lang ?? 'ru'));
            $this->end();

            return;
        }

        $lang = $user->lang ?? 'ru';
        $projects = $this->availableProjectsForUser($user);
        if ($projects->isEmpty()) {
            $bot->sendMessage(__('bot.no_projects_access', [], $lang));
            $this->end();

            return;
        }

        $keyboard = InlineKeyboardMarkup::make();
        foreach ($projects as $project) {
            $keyboard->addRow(InlineKeyboardButton::make($project->name, callback_data: "md_project:{$project->id}"));
        }
        $this->withCancelButton($keyboard, $bot);

        $bot->sendMessage(__('bot.md_select_project', [], $lang), parse_mode: 'HTML', reply_markup: $keyboard);
        $this->next('handleProject');
    }

    public function handleProject(Nutgram $bot): void
    {
        $lang = BotLang::getLang($bot);
        if (! $bot->isCallbackQuery()) {
            if ($this->isCancelled($bot)) {
                return;
            }
            $bot->sendMessage(__('bot.select_project_list', [], $lang));

            return;
        }

        $data = $bot->callbackQuery()->data;
        if ($data === 'global:cancel' || ! str_starts_with($data, 'md_project:')) {
            return;
        }
        $user = User::where('telegram_id', $bot->userId())->first();
        $projectId = (int) explode(':', $data)[1];
        if (! $user || ! $this->availableProjectsForUser($user)->contains('id', $projectId)) {
            $bot->answerCallbackQuery(text: __('bot.no_projects_access', [], $lang), show_alert: true);

            return;
        }

        $this->projectId = $projectId;
        $bot->answerCallbackQuery();

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make(__('bot.md_kind_order', [], $lang), callback_data: 'md_kind:order'))
            ->addRow(InlineKeyboardButton::make(__('bot.md_kind_resolution', [], $lang), callback_data: 'md_kind:resolution'));
        $this->withCancelButton($keyboard, $bot);

        $bot->sendMessage(__('bot.md_select_kind', [], $lang), reply_markup: $keyboard);
        $this->next('handleKind');
    }

    public function handleKind(Nutgram $bot): void
    {
        $lang = BotLang::getLang($bot);
        if (! $bot->isCallbackQuery()) {
            if ($this->isCancelled($bot)) {
                return;
            }
            $bot->sendMessage(__('bot.select_from_btns', [], $lang));

            return;
        }

        $data = $bot->callbackQuery()->data;
        if ($data === 'global:cancel' || ! str_starts_with($data, 'md_kind:')) {
            return;
        }
        $kind = explode(':', $data)[1];
        if (! in_array($kind, ['order', 'resolution'], true)) {
            return;
        }

        $this->documentKind = $kind;
        $bot->answerCallbackQuery();
        $bot->sendMessage(__('bot.md_enter_title', [], $lang), parse_mode: 'HTML', reply_markup: $this->cancelButton($bot));
        $this->next('handleTitle');
    }

    public function handleTitle(Nutgram $bot): void
    {
        if ($this->isCancelled($bot)) {
            return;
        }
        $lang = BotLang::getLang($bot);
        $title = $this->validateText($bot, $bot->message()?->text, __('bot.md_title_field', [], $lang), 255);
        if ($title === null) {
            return;
        }

        $this->title = $title;

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make(__('bot.md_effective_on_approval', [], $lang), callback_data: 'md_effective:approval_date'))
            ->addRow(InlineKeyboardButton::make(__('bot.md_effective_specific', [], $lang), callback_data: 'md_effective:specific_date'));
        $this->withCancelButton($keyboard, $bot);

        $bot->sendMessage(__('bot.md_select_effective_date', [], $lang), parse_mode: 'HTML', reply_markup: $keyboard);
        $this->next('handleEffectiveDateMode');
    }

    public function handleEffectiveDateMode(Nutgram $bot): void
    {
        $lang = BotLang::getLang($bot);
        if (! $bot->isCallbackQuery()) {
            if ($this->isCancelled($bot)) {
                return;
            }
            $bot->sendMessage(__('bot.select_from_btns', [], $lang));

            return;
        }

        $data = $bot->callbackQuery()->data;
        if ($data === 'global:cancel' || ! str_starts_with($data, 'md_effective:')) {
            return;
        }

        $mode = explode(':', $data)[1];
        if (! in_array($mode, ['approval_date', 'specific_date'], true)) {
            return;
        }

        $this->effectiveDateMode = $mode;
        $bot->answerCallbackQuery();

        if ($mode === 'specific_date') {
            $bot->sendMessage(__('bot.md_enter_effective_date', [], $lang), parse_mode: 'HTML', reply_markup: $this->cancelButton($bot));
            $this->next('handleSpecificEffectiveDate');

            return;
        }

        $this->effectiveDate = null;
        $this->sendTemplate($bot);
    }

    public function handleSpecificEffectiveDate(Nutgram $bot): void
    {
        if ($this->isCancelled($bot)) {
            return;
        }
        $lang = BotLang::getLang($bot);
        $value = trim((string) $bot->message()?->text);

        try {
            $date = Carbon::createFromFormat('d.m.Y', $value)->startOfDay();
            if ($date->format('d.m.Y') !== $value) {
                throw new \RuntimeException;
            }
        } catch (\Throwable) {
            $bot->sendMessage(__('bot.md_effective_date_invalid', [], $lang));

            return;
        }

        $this->effectiveDate = $date->format('Y-m-d');
        $this->sendTemplate($bot);
    }

    protected function sendTemplate(Nutgram $bot): void
    {
        $lang = BotLang::getLang($bot);
        $templatePath = app(ManagementDocumentService::class)->templatePath($this->documentKind);

        if (! is_file($templatePath)) {
            $bot->sendMessage(__('bot.md_template_missing', [], $lang));
            $this->end();

            return;
        }

        $bot->sendDocument(
            InputFile::make(fopen($templatePath, 'r')),
            caption: __('bot.md_template_caption', [
                'kind' => app(ManagementDocumentService::class)->kindLabel($this->documentKind),
            ], $lang),
            parse_mode: 'HTML'
        );
        $bot->sendMessage(__('bot.md_upload_prompt', [], $lang), parse_mode: 'HTML', reply_markup: $this->cancelButton($bot));
        $this->next('handleUpload');
    }

    public function handleUpload(Nutgram $bot): void
    {
        if ($this->isCancelled($bot)) {
            return;
        }
        $lang = BotLang::getLang($bot);
        $document = $bot->message()?->document;
        $extension = strtolower(pathinfo($document?->file_name ?? '', PATHINFO_EXTENSION));

        if (! $document || $extension !== 'docx') {
            $bot->sendMessage(__('bot.md_docx_only', [], $lang), parse_mode: 'HTML');

            return;
        }

        $maxBytes = config('management_documents.max_upload_kb') * 1024;
        if (($document->file_size ?? 0) > $maxBytes) {
            $bot->sendMessage(__('bot.md_file_too_large', ['mb' => (int) ceil($maxBytes / 1048576)], $lang));

            return;
        }

        $bot->sendMessage(__('bot.md_processing', [], $lang));

        $stored = null;
        $previewPath = null;
        try {
            $cached = app(\App\Services\TelegramFileCache::class)->resolve($document->file_id);
            $contents = Storage::disk('local')->get($cached);

            $service = app(ManagementDocumentService::class);
            $stored = $service->storeSource($contents, $document->file_name ?? 'document.docx');
            $project = Project::find($this->projectId);
            $previewPath = $service->createPreview($stored['path'], $service->previewSystemValues(
                $this->documentKind ?? 'order',
                $this->title ?? '',
                $project?->name ?? '—',
                $this->effectiveDateMode ?? 'approval_date',
                $this->effectiveDate ? Carbon::parse($this->effectiveDate) : null
            ));

            $keyboard = InlineKeyboardMarkup::make()
                ->addRow(
                    InlineKeyboardButton::make(__('bot.md_preview_confirm', [], $lang), callback_data: 'md_preview:confirm'),
                    InlineKeyboardButton::make(__('bot.md_preview_replace', [], $lang), callback_data: 'md_preview:replace')
                );
            $this->withCancelButton($keyboard, $bot);

            $bot->sendDocument(
                InputFile::make(fopen($service->privatePath($previewPath), 'r')),
                caption: __('bot.md_preview_caption', [], $lang),
                parse_mode: 'HTML',
                reply_markup: $keyboard
            );

            $service->deletePrivatePaths([$this->sourceDocxPath, $this->previewPdfPath]);
            $this->sourceDocxPath = $stored['path'];
            $this->sourceSha256 = $stored['sha256'];
            $this->previewPdfPath = $previewPath;
            $this->next('handlePreview');
        } catch (\Throwable $e) {
            app(ManagementDocumentService::class)->deletePrivatePaths([
                $stored['path'] ?? null,
                $previewPath,
            ]);
            Log::error('Management document upload failed', ['exception_type' => get_class($e)]);
            $message = str_contains($e->getMessage(), 'шаблон') || str_contains($e->getMessage(), 'незаполненные')
                ? __('bot.md_template_invalid', [], $lang)
                : __('bot.md_conversion_failed', [], $lang);
            $bot->sendMessage($message, parse_mode: 'HTML');
        }
    }

    public function handlePreview(Nutgram $bot): void
    {
        $lang = BotLang::getLang($bot);
        if (! $bot->isCallbackQuery()) {
            if ($this->isCancelled($bot)) {
                return;
            }
            $bot->sendMessage(__('bot.select_from_btns', [], $lang));

            return;
        }

        $data = $bot->callbackQuery()->data;
        if ($data === 'global:cancel') {
            return;
        }
        if ($data === 'md_preview:replace') {
            $bot->answerCallbackQuery();
            $bot->sendMessage(__('bot.md_upload_prompt', [], $lang), parse_mode: 'HTML');
            $this->next('handleUpload');

            return;
        }
        if ($data !== 'md_preview:confirm') {
            return;
        }

        $bot->answerCallbackQuery();
        $this->sendApproversKeyboard($bot);
    }

    protected function sendApproversKeyboard(Nutgram $bot): void
    {
        $lang = BotLang::getLang($bot);
        $author = User::where('telegram_id', $bot->userId())->firstOrFail();
        $approvers = app(\App\Services\ManagementDocumentCreation::class)->signers($this->projectId, $author->id)->whereNotIn('id', $this->approvers);

        if ($approvers->isEmpty() && empty($this->approvers)) {
            $bot->sendMessage(__('bot.md_no_signers', [], $lang), parse_mode: 'HTML');
            $this->finish($bot);

            return;
        }

        $keyboard = InlineKeyboardMarkup::make();
        foreach ($approvers as $user) {
            $label = $user->full_name ?? '';
            if ($user->title) {
                $label .= " ({$user->title})";
            }
            $keyboard->addRow(InlineKeyboardButton::make($label, callback_data: "md_signer:{$user->id}"));
        }
        if (! empty($this->approvers)) {
            $keyboard->addRow(InlineKeyboardButton::make(__('bot.md_signers_done', [], $lang), callback_data: 'md_signer:done'));
        }
        $this->withCancelButton($keyboard, $bot);

        $selected = '';
        foreach ($this->approvers as $index => $id) {
            $user = User::find($id);
            $selected .= '\n'.($index + 1).'. '.($user?->full_name ?? '—');
        }

        $bot->sendMessage(
            __('bot.md_select_signer', ['level' => count($this->approvers) + 1, 'selected' => $selected], $lang),
            parse_mode: 'HTML',
            reply_markup: $keyboard
        );
        $this->next('handleSigner');
    }

    public function handleSigner(Nutgram $bot): void
    {
        $lang = BotLang::getLang($bot);
        if (! $bot->isCallbackQuery()) {
            if ($this->isCancelled($bot)) {
                return;
            }
            $bot->sendMessage(__('bot.select_from_btns', [], $lang));

            return;
        }

        $data = $bot->callbackQuery()->data;
        if ($data === 'global:cancel' || ! str_starts_with($data, 'md_signer:')) {
            return;
        }
        $value = explode(':', $data)[1];
        $bot->answerCallbackQuery();

        if ($value === 'done') {
            if (empty($this->approvers)) {
                return;
            }
            $this->showSummary($bot);

            return;
        }

        $id = (int) $value;
        $eligible = $this->allowedSignerIds()->contains($id);
        if (! $eligible || in_array($id, $this->approvers, true)) {
            $bot->sendMessage(__('bot.md_signer_invalid', [], $lang));
            $this->sendApproversKeyboard($bot);

            return;
        }

        $this->approvers[] = $id;
        $this->sendApproversKeyboard($bot);
    }

    protected function showSummary(Nutgram $bot): void
    {
        $lang = BotLang::getLang($bot);
        $project = Project::find($this->projectId);
        $service = app(ManagementDocumentService::class);

        $message = __('bot.md_summary', [
            'kind' => $service->kindLabel($this->documentKind),
            'title' => htmlspecialchars($this->title ?? ''),
            'project' => htmlspecialchars($project?->name ?? '—'),
            'effective_date' => $service->effectiveDateLabel(
                $this->effectiveDateMode,
                $this->effectiveDate ? Carbon::parse($this->effectiveDate) : null
            ),
        ], $lang)."\n\n".__('bot.md_route_label', [], $lang)."\n";

        foreach ($this->approvers as $index => $id) {
            $user = User::find($id);
            $role = $index === count($this->approvers) - 1
                ? __('bot.md_role_final', [], $lang)
                : __('bot.md_role_approver', [], $lang);
            $message .= ($index + 1).'. '.htmlspecialchars($user?->full_name ?? '—')." — {$role}\n";
        }

        $keyboard = InlineKeyboardMarkup::make()->addRow(
            InlineKeyboardButton::make(__('bot.md_create_confirm', [], $lang), callback_data: 'md_create:confirm'),
            InlineKeyboardButton::make(__('bot.app_cancel_btn', [], $lang), callback_data: 'global:cancel')
        );

        $bot->sendMessage($message, parse_mode: 'HTML', reply_markup: $keyboard);
        $this->submissionKey ??= (string) \Illuminate\Support\Str::uuid();
        $this->next('handleConfirm');
    }

    public function handleConfirm(Nutgram $bot): void
    {
        if (! $bot->isCallbackQuery() || $bot->callbackQuery()->data !== 'md_create:confirm') {
            return;
        }
        $bot->answerCallbackQuery();
        if (! $this->submissionKey) {
            $bot->sendMessage('Обновите предпросмотр документа перед подтверждением.');

            return;
        }
        try {
            $actor = User::where('telegram_id', $bot->userId())->firstOrFail();
            $request = app(\App\Services\ManagementDocumentCreation::class)->createFrozen($actor, [
                'title' => $this->title, 'project_id' => $this->projectId, 'document_kind' => $this->documentKind,
                'effective_date_mode' => $this->effectiveDateMode, 'effective_date' => $this->effectiveDate,
                'approvers' => $this->approvers,
            ], (string) $this->sourceDocxPath, (string) $this->previewPdfPath, $this->submissionKey);
        } catch (\Throwable $e) {
            Log::warning('Management document submission failed', ['user_id' => $actor->id ?? null]);
            $bot->sendMessage('Не удалось сохранить документ. Проверьте проект, подписи и предпросмотр.');

            return;
        }
        $this->end();
        $bot->sendMessage(__('bot.md_created', ['id' => $request->id], $actor->lang ?? 'ru'), parse_mode: 'HTML', reply_markup: Menu::main($actor));
    }

    protected function finish(Nutgram $bot): void
    {
        $user = User::where('telegram_id', $bot->userId())->first();
        if ($user) {
            $bot->sendMessage(__('bot.main_menu_label', [], $user->lang ?? 'ru'), reply_markup: Menu::main($user));
        }
        $this->end();
    }

    protected function availableProjectsForUser(User $user): \Illuminate\Support\Collection
    {
        return in_array($user->role, ['admin', 'superadmin'], true)
            ? Project::where('is_active', true)->get()
            : $user->projects()->where('projects.is_active', true)->get();
    }

    protected function eligibleSignersQuery()
    {
        return User::query()
            ->where('account_status', 'approved')->where('is_active', true)
            ->whereNotNull('signature_path')
            ->whereHas('roleRelation', fn ($q) => $q->whereIn('name', ['admin', 'approver', 'supplier', 'superadmin']));
    }

    protected function allowedSignerIds(): \Illuminate\Support\Collection
    {
        return app(\App\Services\ManagementDocumentCreation::class)->signers((int) $this->projectId)->pluck('id');
    }
}
