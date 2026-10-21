<?php
test('public web self-registration remains disabled', function () {
    $this->get('/register')->assertNotFound();
    $this->post('/register', ['phone' => '+998900000000'])->assertNotFound();
});
