<?php

namespace App\Console\Commands;

use App\Models\ApprovalRequest;
use Illuminate\Console\Command;

/**
 * Диагностика заявок, побитых багом сохранения (qty 3.5 → integer):
 * каждое нажатие «Подтвердить» создавало заявку-сироту без позиций и
 * согласующих, плюс возможны полные дубли от двойного нажатия.
 */
class RequestsDoctor extends Command
{
    protected $signature = 'requests:doctor
        {--fix : Отменить (status=cancelled) заявки-сироты без позиций и согласующих, а также точные дубли}';

    protected $description = 'Найти зависшие заявки без согласующих и дубли; с --fix отменить явно битые';

    public function handle(): int
    {
        $fix = (bool) $this->option('fix');

        // ── 1. Заявки в ожидании согласования без единого согласующего ─────────
        $noApprovals = ApprovalRequest::with('applicant')
            ->where('status', 'pending_approval')
            ->whereDoesntHave('approvals')
            ->orderBy('id')
            ->get();

        if ($noApprovals->isNotEmpty()) {
            $this->warn("Заявки в статусе pending_approval БЕЗ согласующих: {$noApprovals->count()}");
            $this->table(
                ['id', 'Тип', 'Название', 'Заявитель', 'Позиций', 'Создана'],
                $noApprovals->map(fn($r) => [
                    $r->id, $r->type, $r->name,
                    $r->applicant?->full_name ?? '—',
                    $r->items()->count(),
                    $r->created_at->format('d.m.Y H:i'),
                ])->all()
            );
        } else {
            $this->info('Заявок без согласующих не найдено. ✅');
        }

        // ── 2. Заявки, у которых текущий уровень не указывает на согласующего ──
        $brokenLevel = ApprovalRequest::with('applicant')
            ->where('status', 'pending_approval')
            ->whereHas('approvals')
            ->whereDoesntHave('approvals', function ($q) {
                $q->whereColumn('level', 'approval_requests.expected_approval_level');
            })
            ->orderBy('id')
            ->get();

        if ($brokenLevel->isNotEmpty()) {
            $this->warn("Заявки с битым уровнем согласования (expected_approval_level без строки): {$brokenLevel->count()}");
            $this->table(
                ['id', 'Название', 'Ожидаемый уровень', 'Заявитель'],
                $brokenLevel->map(fn($r) => [
                    $r->id, $r->name, $r->expected_approval_level, $r->applicant?->full_name ?? '—',
                ])->all()
            );
            $this->line('  → Такие заявки чинятся вручную (нужно решить, на каком уровне продолжить).');
        } else {
            $this->info('Заявок с битым уровнем согласования не найдено. ✅');
        }

        // ── 3. Дубли: одинаковые applicant+name+total в pending за короткий срок ──
        $pending = ApprovalRequest::where('status', 'pending_approval')
            ->orderBy('created_at')
            ->get()
            ->groupBy(fn($r) => "{$r->applicant_id}|{$r->type}|{$r->name}|{$r->total_price}");

        $duplicateGroups = $pending->filter(fn($group) => $group->count() > 1);

        if ($duplicateGroups->isNotEmpty()) {
            $this->warn("Групп потенциальных дублей: {$duplicateGroups->count()}");
            foreach ($duplicateGroups as $group) {
                $this->table(
                    ['id', 'Название', 'Сумма', 'Создана', ''],
                    $group->values()->map(fn($r, $i) => [
                        $r->id, $r->name, $r->total_price,
                        $r->created_at->format('d.m.Y H:i:s'),
                        $i === 0 ? '← оригинал' : 'дубль',
                    ])->all()
                );
            }
        } else {
            $this->info('Дублей не найдено. ✅');
        }

        if (!$fix) {
            $this->line('');
            $this->line('Запустите с флагом --fix, чтобы отменить заявки-сироты (без позиций и согласующих) и дубли.');
            return self::SUCCESS;
        }

        // ── FIX ────────────────────────────────────────────────────────────────
        $cancelled = 0;

        // Сироты от бага: нет согласующих И нет позиций — заявка заведомо неработоспособна.
        foreach ($noApprovals as $r) {
            if ($r->items()->count() === 0) {
                $r->update(['status' => 'cancelled']);
                $cancelled++;
                $this->line("  🗑 #{$r->id} «{$r->name}» отменена (сирота без позиций и согласующих).");
            } else {
                $this->line("  ⏭ #{$r->id} пропущена — есть позиции, разберитесь вручную (или удалите через бота).");
            }
        }

        // Дубли: оставляем самую раннюю, остальные отменяем.
        foreach ($duplicateGroups as $group) {
            foreach ($group->values()->slice(1) as $dup) {
                if ($dup->status === 'pending_approval') {
                    $dup->update(['status' => 'cancelled']);
                    $cancelled++;
                    $this->line("  🗑 #{$dup->id} отменена как дубль (оригинал #{$group->first()->id}).");
                }
            }
        }

        $this->info("Готово. Отменено заявок: {$cancelled}.");

        return self::SUCCESS;
    }
}
