<?php
test('the unused email verification notification endpoint is unavailable', function () {
    $this->post('/email/verification-notification')->assertNotFound();
});
