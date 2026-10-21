<?php
test('phone password recovery form is available', function () {
    $this->get(route('password.request'))->assertOk();
});
test('password recovery rejects a fabricated token', function () {
    $this->post(route('password.update'), ['token' => str_repeat('x', 64), 'password' => 'Personal-Secret-2026', 'password_confirmation' => 'Personal-Secret-2026'])->assertSessionHasErrors('token');
});
