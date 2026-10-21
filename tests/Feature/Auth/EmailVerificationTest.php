<?php
use Illuminate\Support\Facades\Route;
test('phone accounts do not expose an email verification flow', function () {
    expect(Route::has('verification.notice'))->toBeFalse()->and(Route::has('verification.verify'))->toBeFalse();
});
