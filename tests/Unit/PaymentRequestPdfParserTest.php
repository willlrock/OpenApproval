<?php

use App\Models\Project;
use App\Services\PdfImport\PaymentRequestPdfMatcher;
use App\Services\PdfImport\PaymentRequestPdfParser;
use Illuminate\Support\Collection;

test('parser extracts didox contract payment fields', function () {
    $text = <<<'TEXT'
Договор
Договор №172/26
08.07.2026
"INNOSTORE" MCHJ (именуемое в дальнейшем - Исполнитель) в лице директора, с одной стороны, и
""RESTORAN N" MAS'ULIYATI CHEKLANGAN JAMIYAT" (именуемое в дальнейшем - Заказчик) в лице директора, с другой стороны,
заключили настоящий договор о следующем:
Итого 8 174 214.29 980 905.71 9 155 120.00
Общая сумма договора составляет Девять миллионов сто пятьдесят пять тысяч сто двадцать сум 00 тийин (9 155 120.00)
2.1. Покупатель производит 70% предоплату за каждую партию товара путем перечисления денежных средств.
10. Юридические адреса и реквизиты сторон
Исполнитель Заказчик
Наименование: "INNOSTORE" MCHJ Наименование: "RESTORAN N" MAS'ULIYATI CHEKLANGAN JAMIYAT
ИНН: 311738371
ИНН: 310841894
Р/С: 20208000107169738001
Р/С: 20208000505707022001
МФО: 00445
МФО: 01018
TEXT;

    $parsed = (new PaymentRequestPdfParser())->parseText($text);

    expect($parsed->documentType)->toBe('Договор')
        ->and($parsed->documentNumber)->toBe('172/26')
        ->and($parsed->documentDate)->toBe('08.07.2026')
        ->and($parsed->currency)->toBe('UZS')
        ->and($parsed->totalAmount)->toBe(9155120.0)
        ->and($parsed->prepaymentPercent)->toBe(70.0)
        ->and($parsed->suggestedPaymentAmount)->toBe(6408584.0)
        ->and($parsed->inns)->toBe(['311738371', '310841894'])
        ->and($parsed->bankAccounts)->toBe(['20208000107169738001', '20208000505707022001'])
        ->and($parsed->mfos)->toBe(['00445', '01018'])
        ->and($parsed->parties[0]['name'])->toBe('"INNOSTORE" MCHJ')
        ->and($parsed->parties[1]['inn'])->toBe('310841894');
});

test('matcher selects project by inn and returns the other party as supplier', function () {
    $parsed = (new PaymentRequestPdfParser())->parseText(<<<'TEXT'
"INNOSTORE" MCHJ (именуемое в дальнейшем - Исполнитель) и
""RESTORAN N" MAS'ULIYATI CHEKLANGAN JAMIYAT" (именуемое в дальнейшем - Заказчик)
ИНН: 311738371
ИНН: 310841894
TEXT);

    $project = new Project([
        'name' => 'Restaurant',
        'company_name' => 'RESTORAN N',
        'inn' => '310841894',
    ]);

    $match = (new PaymentRequestPdfMatcher())->matchProjectAndSupplier(
        $parsed,
        new Collection([$project])
    );

    expect($match['project'])->toBe($project)
        ->and($match['confidence'])->toBe('high')
        ->and($match['supplier_party']['inn'])->toBe('311738371')
        ->and($match['supplier_party']['name'])->toBe('"INNOSTORE" MCHJ');
});

test('parser prefers a specific payment marker and does not select the largest nearby number', function () {
    $parsed = (new PaymentRequestPdfParser())->parseText(<<<'TEXT'
Итого 9 999 999.00
Всего к оплате: 125 000.00
Банковская комиссия 900 000.00
TEXT);

    expect($parsed->totalAmount)->toBe(125000.0);
});

test('parser does not attach an incomplete requisites column to the wrong party', function () {
    $parsed = (new PaymentRequestPdfParser())->parseText(<<<'TEXT'
"SUPPLIER" LLC (именуемое в дальнейшем - Исполнитель) и
"CUSTOMER" LLC (именуемое в дальнейшем - Заказчик)
ИНН: 123456789
Р/С: 20208000107169738001
Р/С: 20208000505707022001
TEXT);

    expect($parsed->inns)->toBe(['123456789'])
        ->and($parsed->parties)->toHaveCount(2)
        ->and($parsed->parties[0]['inn'])->toBeNull()
        ->and($parsed->parties[1]['inn'])->toBeNull()
        ->and($parsed->parties[0]['bank_account'])->toBe('20208000107169738001')
        ->and($parsed->parties[1]['bank_account'])->toBe('20208000505707022001');
});

test('name fallback is never reported as a high confidence inn match', function () {
    $parsed = (new PaymentRequestPdfParser())->parseText(<<<'TEXT'
"SUPPLIER" LLC (именуемое в дальнейшем - Исполнитель) и
"CUSTOMER" LLC (именуемое в дальнейшем - Заказчик)
ИНН: 123456789
ИНН: 987654321
TEXT);

    $project = new Project([
        'name' => 'Customer',
        'company_name' => 'CUSTOMER LLC',
        'inn' => null,
    ]);

    $match = (new PaymentRequestPdfMatcher())->matchProjectAndSupplier(
        $parsed,
        new Collection([$project])
    );

    expect($match['project'])->toBe($project)
        ->and($match['confidence'])->toBe('medium');
});
