<?php

namespace App\Console\Commands;

use App\Models\ApprovalRequest;
use App\Models\Project;
use App\Models\RequestApproval;
use App\Models\RequestItem;
use App\Models\RequestTranche;
use App\Models\Supplier;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/**
 * Ручное создание заявки на расход — для случаев, когда диалог в боте «умер»
 * (перезапуск/очистка кеша) и данные заявки известны, но кнопка «Подтвердить»
 * уже не работает.
 *
 * Пример:
 *  php artisan request:create-manual \
 *    --applicant="Иванов" --project="Demo project" --supplier="Рынок" \
 *    --currency=UZS --tx=cash --deadline="11.06.2026" \
 *    --item="ягода ежевика свежая|№ 2293 от 6 июня 2026 г.|250000|3.5" \
 *    --approver="Зияев" --approver="Covino" --approver="Шукуров" --approver="Эгамбердиев" \
 *    --notify
 */
class CreateManualRequest extends Command
{
    protected $signature = 'request:create-manual
        {--applicant=  : Заявитель — id, telegram_id или часть имени}
        {--project=    : Проект — id или часть названия}
        {--supplier=   : Поставщик (будет создан, если не найден)}
        {--currency=UZS : Валюта (USD|EUR|RUB|UZS)}
        {--tx=cash     : Способ оплаты (cash|bank)}
        {--deadline=   : Дедлайн в формате дд.мм.гггг}
        {--item=*      : Позиция "Название|Описание|Цена|Кол-во" (повторяемо)}
        {--approver=*  : Согласующие по порядку — id или часть имени (повторяемо)}
        {--notify      : Отправить уведомление первому согласующему в Telegram}
        {--force       : Не спрашивать подтверждение}';

    protected $description = 'Вручную создать заявку на расход (восстановление зависшего диалога бота)';

    public function handle(Nutgram $bot): int
    {
        $applicant = $this->resolveUser((string) $this->option('applicant'), 'заявитель');
        if (! $applicant) {
            return self::FAILURE;
        }

        $project = $this->resolveProject((string) $this->option('project'));
        if (! $project) {
            return self::FAILURE;
        }

        $currency = strtoupper((string) $this->option('currency'));
        if (! in_array($currency, ['USD', 'EUR', 'RUB', 'UZS'])) {
            $this->error("Недопустимая валюта: {$currency}");

            return self::FAILURE;
        }

        $tx = (string) $this->option('tx');
        if (! in_array($tx, ['cash', 'bank'])) {
            $this->error("Недопустимый способ оплаты: {$tx} (ожидается cash или bank)");

            return self::FAILURE;
        }

        try {
            $deadline = Carbon::createFromFormat('d.m.Y', (string) $this->option('deadline'))->startOfDay();
        } catch (\Throwable) {
            $this->error('Укажите дедлайн в формате дд.мм.гггг, например --deadline="11.06.2026"');

            return self::FAILURE;
        }

        $items = [];
        foreach ((array) $this->option('item') as $raw) {
            $parts = array_map('trim', explode('|', $raw));
            if (count($parts) !== 4 || $parts[0] === '' || ! is_numeric($parts[2]) || ! is_numeric($parts[3])) {
                $this->error("Позиция должна иметь вид \"Название|Описание|Цена|Кол-во\": {$raw}");

                return self::FAILURE;
            }
            $items[] = [
                'name' => $parts[0],
                'description' => $parts[1] !== '' ? $parts[1] : null,
                'price_per_unit' => (float) $parts[2],
                'quantity' => (float) $parts[3],
                'total_price' => round((float) $parts[2] * (float) $parts[3], 2),
            ];
        }
        if (empty($items)) {
            $this->error('Добавьте хотя бы одну позицию через --item="Название|Описание|Цена|Кол-во"');

            return self::FAILURE;
        }

        $approvers = [];
        foreach ((array) $this->option('approver') as $needle) {
            $approver = $this->resolveUser($needle, 'согласующий');
            if (! $approver) {
                return self::FAILURE;
            }
            $approvers[] = $approver;
        }
        if (empty($approvers)) {
            $this->error('Укажите хотя бы одного согласующего через --approver=');

            return self::FAILURE;
        }

        $supplierName = trim((string) $this->option('supplier'));
        $supplierRecord = $supplierName !== '' ? Supplier::firstOrCreate(['name' => $supplierName]) : null;

        $grandTotal = array_sum(array_column($items, 'total_price'));

        $this->info('Будет создана заявка:');
        $this->table(['Поле', 'Значение'], [
            ['Заявитель',  "{$applicant->full_name} (id {$applicant->id})"],
            ['Проект',     $project->name],
            ['Поставщик',  $supplierName !== '' ? $supplierName : '—'],
            ['Валюта',     $currency],
            ['Оплата',     $tx],
            ['Дедлайн',    $deadline->format('d.m.Y')],
            ['Итого',      number_format($grandTotal, 2, '.', ' ')." {$currency}"],
        ]);
        $this->table(
            ['Позиция', 'Цена', 'Кол-во', 'Сумма'],
            array_map(fn ($i) => [$i['name'], $i['price_per_unit'], $i['quantity'], $i['total_price']], $items)
        );
        $this->table(
            ['Уровень', 'Согласующий'],
            collect($approvers)->map(fn ($u, $i) => [$i + 1, "{$u->full_name}".($u->title ? " ({$u->title})" : '')])->all()
        );

        if (! $this->option('force') && ! $this->confirm('Создать заявку?', true)) {
            $this->warn('Отменено.');

            return self::SUCCESS;
        }

        $request = DB::transaction(function () use ($applicant, $project, $currency, $tx, $deadline, $items, $approvers, $supplierName, $supplierRecord, $grandTotal) {
            $request = ApprovalRequest::create([
                'type' => 'approval',
                'project_id' => $project->id,
                'applicant_id' => $applicant->id,
                'name' => $items[0]['name'],
                'currency' => $currency,
                'total_price' => $grandTotal,
                'transaction_type' => $tx,
                'supplier_name' => $supplierName !== '' ? $supplierName : null,
                'supplier_inn' => $supplierRecord?->inn,
                'supplier_id' => $supplierRecord?->id,
                'status' => 'pending_approval',
                'expected_approval_level' => 1,
                'deadline_date' => $deadline,
            ]);

            foreach ($items as $item) {
                RequestItem::create(['approval_request_id' => $request->id] + $item);
            }

            foreach ($approvers as $index => $approver) {
                RequestApproval::create([
                    'approval_request_id' => $request->id,
                    'approver_id' => $approver->id,
                    'level' => $index + 1,
                    'status' => 'pending',
                ]);
            }

            RequestTranche::create([
                'approval_request_id' => $request->id,
                'amount' => $grandTotal,
                'percent' => 100,
                'due_date' => now(),
                'comment' => 'Единовременный платёж',
                'status' => 'pending',
            ]);

            return $request;
        });

        $this->info("✅ Заявка #{$request->id} создана.");

        if ($this->option('notify')) {
            $this->notifyFirstApprover($bot, $request, $applicant, $approvers[0], $items, $grandTotal);
        } else {
            $this->warn('Уведомление согласующему НЕ отправлено (добавьте --notify).');
        }

        return self::SUCCESS;
    }

    protected function notifyFirstApprover(Nutgram $bot, ApprovalRequest $request, User $applicant, User $approver, array $items, float $grandTotal): void
    {
        if (! $approver->telegram_id) {
            $this->warn("У согласующего {$approver->full_name} не указан telegram_id — уведомление не отправлено.");

            return;
        }

        $lang = $approver->lang ?? 'ru';
        $pages = app(\App\Services\ApprovalNotificationMessage::class)->pages($request, $lang, true);

        $keyboard = InlineKeyboardMarkup::make()->addRow(
            InlineKeyboardButton::make(__('bot.notify_approve_btn', [], $lang), callback_data: "approve:{$request->id}_{$request->revision}"),
            InlineKeyboardButton::make(__('bot.notify_reject_btn', [], $lang), callback_data: "reject:{$request->id}_{$request->revision}")
        )->addRow(InlineKeyboardButton::make(__('notifications.open_request', [], $lang), url: route('requests.detail', $request->id)));

        try {
            foreach ($pages as $index => $page) {
                $bot->sendMessage($page, chat_id: $approver->telegram_id, parse_mode: 'HTML', reply_markup: $index === array_key_last($pages) ? $keyboard : null);
            }
            $request->approvals()->where('level', 1)->update(['notified_at' => now()]);
            $this->info("📨 Уведомление отправлено: {$approver->full_name}");
        } catch (\Throwable $e) {
            $this->error("Не удалось отправить уведомление: {$e->getMessage()}");
        }
    }

    protected function resolveUser(string $needle, string $label): ?User
    {
        $needle = trim($needle);
        if ($needle === '') {
            $this->error("Не указан {$label}.");

            return null;
        }

        $query = is_numeric($needle)
            ? User::where('id', (int) $needle)->orWhere('telegram_id', (int) $needle)
            : User::where('full_name', 'ILIKE', "%{$needle}%");

        $matches = $query->get();

        if ($matches->isEmpty()) {
            $this->error("Не найден {$label}: «{$needle}»");

            return null;
        }
        if ($matches->count() > 1) {
            $this->error("Найдено несколько пользователей по «{$needle}» — уточните запрос:");
            $this->table(['id', 'Имя', 'Должность'], $matches->map(fn ($u) => [$u->id, $u->full_name, $u->title])->all());

            return null;
        }

        return $matches->first();
    }

    protected function resolveProject(string $needle): ?Project
    {
        $needle = trim($needle);
        if ($needle === '') {
            $this->error('Не указан проект.');

            return null;
        }

        $query = is_numeric($needle)
            ? Project::where('id', (int) $needle)
            : Project::where('name', 'ILIKE', "%{$needle}%");

        $matches = $query->get();

        if ($matches->isEmpty()) {
            $this->error("Проект не найден: «{$needle}»");

            return null;
        }
        if ($matches->count() > 1) {
            $this->error("Найдено несколько проектов по «{$needle}» — уточните запрос:");
            $this->table(['id', 'Название'], $matches->map(fn ($p) => [$p->id, $p->name])->all());

            return null;
        }

        return $matches->first();
    }
}
