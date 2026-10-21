<?php

namespace App\Support;

use RuntimeException;

class OnlineMigrationPolicy
{
    public static function check(array $pending, string $policyFile): void
    {
        $policy = json_decode(file_get_contents($policyFile), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($policy)) {
            throw new RuntimeException('Invalid online migration policy.');
        }
        foreach ($pending as $name => $file) {
            if (! isset($policy[$name]) || ! is_string($policy[$name])
                || ! hash_equals($policy[$name], hash_file('sha256', $file))) {
                throw new RuntimeException("Migration {$name} requires compatibility review or DEPLOY_MAINTENANCE=1. The live release was not changed.");
            }
        }
    }
}
