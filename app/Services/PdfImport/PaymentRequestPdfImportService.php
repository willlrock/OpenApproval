<?php

namespace App\Services\PdfImport;

use Illuminate\Support\Collection;

class PaymentRequestPdfImportService
{
    public function __construct(
        private readonly PdfTextExtractor $extractor,
        private readonly PaymentRequestPdfParser $parser,
        private readonly PaymentRequestPdfMatcher $matcher,
    ) {
    }

    public function analyze(string $path, Collection $projects): array
    {
        $parsed = $this->parser->parseText($this->extractor->extract($path));

        return [
            'parsed' => $parsed,
            'match' => $this->matcher->matchProjectAndSupplier($parsed, $projects),
        ];
    }
}
