<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Console\Command;

class AuditPhoneIdentities extends Command
{
    protected $signature = 'accounts:audit-phones';

    protected $description = 'Report legacy ambiguous or invalid phone identifiers without changing any account';

    public function handle(): int
    {
        $groups = [];
        $invalid = [];
        foreach (User::whereNotNull('phone')->cursor() as $user) {
            $canonical = PhoneNumber::canonical($user->phone);
            if ($canonical === null) {
                $invalid[] = [$user->id, $user->phone, 'Invalid phone'];
            } else {
                $groups[$canonical][] = $user->id;
            }
        }
        $rows = $invalid;
        foreach ($groups as $phone => $ids) {
            if (count($ids) > 1) {
                $rows[] = [implode(', ', $ids), $phone, 'Ambiguous phone'];
            }
        }
        if ($rows === []) {
            $this->info('No invalid or ambiguous phone identifiers.');

            return self::SUCCESS;
        }
        $this->table(['User IDs', 'Phone', 'Problem'], $rows);
        $this->warn('Verify ownership and correct the affected accounts individually. No identities were merged or changed.');

        return self::FAILURE;
    }
}
