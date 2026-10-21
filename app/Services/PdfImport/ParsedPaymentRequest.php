<?php

namespace App\Services\PdfImport;

class ParsedPaymentRequest
{
    public function __construct(
        public readonly ?string $documentType,
        public readonly ?string $documentNumber,
        public readonly ?string $documentDate,
        public readonly ?float $totalAmount,
        public readonly ?float $suggestedPaymentAmount,
        public readonly ?string $currency,
        public readonly ?float $prepaymentPercent,
        public readonly array $parties,
        public readonly array $inns,
        public readonly array $bankAccounts,
        public readonly array $mfos,
    ) {
    }

    public function toArray(): array
    {
        return [
            'document_type' => $this->documentType,
            'document_number' => $this->documentNumber,
            'document_date' => $this->documentDate,
            'total_amount' => $this->totalAmount,
            'suggested_payment_amount' => $this->suggestedPaymentAmount,
            'currency' => $this->currency,
            'prepayment_percent' => $this->prepaymentPercent,
            'parties' => $this->parties,
            'inns' => $this->inns,
            'bank_accounts' => $this->bankAccounts,
            'mfos' => $this->mfos,
        ];
    }
}
