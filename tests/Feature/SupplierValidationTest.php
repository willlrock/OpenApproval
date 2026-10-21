<?php

use App\Models\Supplier;

test('supplier create and update return actionable errors for all text fields', function (string $operation) {
    seedRoles();
    $this->actingAs(makeUserWithRole('admin'));
    $supplier = Supplier::create(['name' => 'Existing supplier', 'inn' => '123', 'description' => 'Existing description']);
    $data = ['name' => 'Valid name', 'inn' => str_repeat('1', 21), 'description' => str_repeat('О', 501)];
    $response = $operation === 'create'
        ? $this->postJson(route('suppliers.store'), $data)
        : $this->putJson(route('suppliers.update', $supplier->id), $data);
    $response->assertUnprocessable()->assertJsonValidationErrors(['inn', 'description'])
        ->assertJsonPath('errors.inn.0', 'ИНН должен содержать не больше 20 символов.')
        ->assertJsonPath('errors.description.0', 'Описание должно содержать не больше 500 символов.');
    expect(Supplier::count())->toBe(1)->and($supplier->fresh()->name)->toBe('Existing supplier');
})->with(['create', 'update']);
