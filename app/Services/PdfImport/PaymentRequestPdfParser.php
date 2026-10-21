<?php

namespace App\Services\PdfImport;

class PaymentRequestPdfParser
{
    public function parseText(string $text): ParsedPaymentRequest
    {
        $text = $this->normalizeText($text);
        $documentType = $this->extractDocumentType($text);
        $documentNumber = $this->extractDocumentNumber($text);
        $documentDate = $this->extractDocumentDate($text, $documentNumber);
        $inns = $this->extractInns($text);
        $bankAccounts = $this->extractBankAccounts($text);
        $mfos = $this->extractMfos($text);
        $totalAmount = $this->extractTotalAmount($text);
        $prepaymentPercent = $this->extractPrepaymentPercent($text);
        $suggestedPaymentAmount = $this->suggestPaymentAmount($totalAmount, $prepaymentPercent);

        return new ParsedPaymentRequest(
            documentType: $documentType,
            documentNumber: $documentNumber,
            documentDate: $documentDate,
            totalAmount: $totalAmount,
            suggestedPaymentAmount: $suggestedPaymentAmount,
            currency: $this->extractCurrency($text),
            prepaymentPercent: $prepaymentPercent,
            parties: $this->extractParties($text, $inns, $bankAccounts, $mfos),
            inns: $inns,
            bankAccounts: $bankAccounts,
            mfos: $mfos,
        );
    }

    private function normalizeText(string $text): string
    {
        $text = str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;

        return trim(preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text);
    }

    private function extractDocumentType(string $text): ?string
    {
        if (preg_match('/(Договор|Контракт|Сч[её]т(?:\s+на\s+оплату)?|Invoice)/iu', $text, $match)) {
            return trim($match[1]);
        }

        return null;
    }

    private function extractDocumentNumber(string $text): ?string
    {
        $patterns = [
            '/(?:Договор|Контракт)\s*№\s*([A-ZА-ЯЁ0-9\/._-]+)/iu',
            '/(?:Сч[её]т(?:\s+на\s+оплату)?|Invoice)\s*№?\s*([A-ZА-ЯЁ0-9\/._-]+)/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $match)) {
                return trim($match[1]);
            }
        }

        return null;
    }

    private function extractDocumentDate(string $text, ?string $documentNumber): ?string
    {
        if ($documentNumber) {
            $quotedNumber = preg_quote($documentNumber, '/');
            if (preg_match('/' . $quotedNumber . '.{0,80}?(\d{2}\.\d{2}\.\d{4})/u', $text, $match)) {
                return $match[1];
            }
        }

        if (preg_match('/\b(\d{2}\.\d{2}\.\d{4})\b/u', $text, $match)) {
            return $match[1];
        }

        return null;
    }

    private function extractInns(string $text): array
    {
        preg_match_all('/(?:ИНН|INN)\s*:?\s*(\d{9,14})/iu', $text, $matches);

        return $this->uniqueValues($matches[1] ?? []);
    }

    private function extractBankAccounts(string $text): array
    {
        preg_match_all('/Р\/С\s*:?\s*(\d{10,30})/iu', $text, $matches);

        return $this->uniqueValues($matches[1] ?? []);
    }

    private function extractMfos(string $text): array
    {
        preg_match_all('/МФО\s*:?\s*(\d{3,10})/iu', $text, $matches);

        return $this->uniqueValues($matches[1] ?? []);
    }

    private function extractParties(string $text, array $inns, array $bankAccounts, array $mfos): array
    {
        $names = $this->extractPartyNames($text);
        $roles = ['executor', 'customer'];
        $count = max(count($names), count($inns), count($bankAccounts), count($mfos));
        $alignedInns = $this->alignPartyField($inns, $count);
        $alignedBankAccounts = $this->alignPartyField($bankAccounts, $count);
        $alignedMfos = $this->alignPartyField($mfos, $count);
        $parties = [];

        for ($i = 0; $i < $count; $i++) {
            $parties[] = [
                'role' => $roles[$i] ?? null,
                'name' => $names[$i] ?? null,
                'inn' => $alignedInns[$i],
                'bank_account' => $alignedBankAccounts[$i],
                'mfo' => $alignedMfos[$i],
            ];
        }

        return $parties;
    }

    private function extractPartyNames(string $text): array
    {
        if (preg_match(
            '/(?<executor>"[^"\n]+"[^\n()]{0,160})\s+\(именуем[^\n]+Исполнитель\).*?и\s+(?<customer>"{1,2}[^()]{2,220}?)\s+\(именуем[^\n]+Заказчик\)/isu',
            $text,
            $match
        )) {
            return $this->uniqueValues([
                $this->cleanName($match['executor']),
                $this->cleanName($match['customer']),
            ]);
        }

        preg_match_all('/Наименование:\s*(.+)$/imu', $text, $matches);
        $names = [];

        foreach ($matches[1] ?? [] as $line) {
            foreach (preg_split('/\s+Наименование:\s*/u', $line) ?: [] as $name) {
                $name = preg_split('/\s+(?:Адрес|Телефон|Факс|ИНН|ОКЭД|Р\/С|Банк|МФО):/u', $name)[0] ?? $name;
                $name = $this->cleanName($name);
                if ($name !== '') {
                    $names[] = $name;
                }
            }
        }

        return array_slice($this->uniqueValues($names), 0, 2);
    }

    private function extractTotalAmount(string $text): ?float
    {
        // Specific payment markers take precedence over a generic contract total.
        // Within a marker we use the first adjacent amount instead of the largest
        // number in a wide text window (which could be an account or another sum).
        $markers = [
            'Всего к оплате',
            'Итого к оплате',
            'Общая сумма договора составляет',
            'Итого',
        ];

        foreach ($markers as $marker) {
            $pos = mb_stripos($text, $marker);
            if ($pos === false) {
                continue;
            }

            $segment = mb_substr($text, $pos + mb_strlen($marker), 300);
            $patterns = [
                '/\(\s*(\d[\d\s]*(?:[,.]\d{2}))\s*\)/u',
                '/^\s*(?:[:\-–—]\s*)?(\d[\d\s]*(?:[,.]\d{2}))\b/u',
                '/\b(\d[\d\s]*(?:[,.]\d{2}))\b/u',
            ];

            foreach ($patterns as $pattern) {
                if (! preg_match($pattern, $segment, $match)) {
                    continue;
                }

                $amount = $this->parseMoney($match[1]);
                if ($amount !== null && $amount > 0) {
                    return $amount;
                }
            }
        }

        return null;
    }

    private function extractCurrency(string $text): ?string
    {
        if (preg_match('/\b(USD|EUR|RUB|UZS)\b/i', $text, $match)) {
            return strtoupper($match[1]);
        }

        if (preg_match('/(?:сум|сўм|sum|тийин|tiyin)/iu', $text)) {
            return 'UZS';
        }

        return null;
    }

    private function extractPrepaymentPercent(string $text): ?float
    {
        if (preg_match('/(\d{1,3}(?:[,.]\d+)?)\s*%\s*(?:предоплат|аванс)/iu', $text, $match)) {
            $percent = (float) str_replace(',', '.', $match[1]);

            return $percent > 0 && $percent <= 100 ? $percent : null;
        }

        return null;
    }

    private function suggestPaymentAmount(?float $totalAmount, ?float $prepaymentPercent): ?float
    {
        if ($totalAmount === null) {
            return null;
        }

        if ($prepaymentPercent === null) {
            return $totalAmount;
        }

        return round($totalAmount * $prepaymentPercent / 100, 2);
    }

    private function parseMoney(string $value): ?float
    {
        $normalized = str_replace([' ', "\u{00A0}"], '', $value);
        $normalized = str_replace(',', '.', $normalized);

        return is_numeric($normalized) ? (float) $normalized : null;
    }

    private function cleanName(string $name): string
    {
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
        $name = preg_replace('/^(?:и\s+)?/iu', '', trim($name)) ?? $name;

        return trim($name, " \t\n\r\0\x0B,.;");
    }

    /**
     * Parallel PDF columns are only safe to join when every party has a value.
     * A partial column is ambiguous, so keep the raw values on ParsedPaymentRequest
     * and leave the per-party field empty instead of assigning it to the wrong party.
     */
    private function alignPartyField(array $values, int $partyCount): array
    {
        if ($partyCount === 0) {
            return [];
        }

        return count($values) === $partyCount
            ? array_values($values)
            : array_fill(0, $partyCount, null);
    }

    private function uniqueValues(array $values): array
    {
        $seen = [];
        $result = [];

        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($value === '' || isset($seen[$value])) {
                continue;
            }

            $seen[$value] = true;
            $result[] = $value;
        }

        return $result;
    }
}
