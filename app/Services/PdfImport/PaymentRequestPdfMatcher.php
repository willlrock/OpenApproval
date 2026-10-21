<?php

namespace App\Services\PdfImport;

use Illuminate\Support\Collection;

class PaymentRequestPdfMatcher
{
    public function matchProjectAndSupplier(ParsedPaymentRequest $parsed, Collection $projects): array
    {
        foreach ($parsed->parties as $index => $party) {
            $match = $this->findProjectByParty($party, $projects);
            if (!$match) {
                continue;
            }

            return [
                'project' => $match['project'],
                'project_party' => $party,
                'supplier_party' => $this->findSupplierParty($parsed->parties, $index),
                'confidence' => $match['basis'] === 'inn' ? 'high' : 'medium',
            ];
        }

        return [
            'project' => null,
            'project_party' => null,
            'supplier_party' => null,
            'confidence' => 'none',
        ];
    }

    private function findProjectByParty(array $party, Collection $projects): mixed
    {
        $partyInn = $this->digits($party['inn'] ?? null);
        if ($partyInn !== null) {
            $project = $projects->first(fn ($project) => $this->digits($project->inn) === $partyInn);
            if ($project) {
                return ['project' => $project, 'basis' => 'inn'];
            }
        }

        $partyName = $this->normalizeName($party['name'] ?? null);
        if ($partyName === null) {
            return null;
        }

        $project = $projects->first(function ($project) use ($partyName) {
            $aliases = preg_split('/\s*,\s*/u', (string) $project->aliases) ?: [];
            $names = array_filter([
                $project->name,
                $project->company_name,
                ...$aliases,
            ]);

            foreach ($names as $name) {
                $candidate = $this->normalizeName($name);
                if ($candidate !== null && (str_contains($partyName, $candidate) || str_contains($candidate, $partyName))) {
                    return true;
                }
            }

            return false;
        });

        return $project ? ['project' => $project, 'basis' => 'name'] : null;
    }

    private function findSupplierParty(array $parties, int $projectPartyIndex): ?array
    {
        foreach ($parties as $index => $party) {
            if ($index !== $projectPartyIndex && (!empty($party['inn']) || !empty($party['name']))) {
                return $party;
            }
        }

        return null;
    }

    private function digits(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value);

        return $digits !== '' ? $digits : null;
    }

    private function normalizeName(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = mb_strtolower($value);
        $value = preg_replace('/[^a-zа-яё0-9]+/u', ' ', $value) ?? $value;
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);

        return $value !== '' ? $value : null;
    }
}
