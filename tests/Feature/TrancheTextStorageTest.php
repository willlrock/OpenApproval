<?php

use App\Models\ApprovalRequest;
use App\Services\ApprovalRequestCreation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    seedRoles();
    $this->actor = makeUserWithRole('admin');
    $this->project = makeProject();
    $this->request = ApprovalRequest::create(['applicant_id' => $this->actor->id, 'project_id' => $this->project->id,
        'name' => 'Long payment text', 'type' => 'approval', 'status' => 'approved', 'currency' => 'USD',
        'transaction_type' => 'bank', 'total_price' => 100]);
    $this->tranche = $this->request->tranches()->create(['amount' => 100, 'status' => 'pending']);
});

test('payment text columns support the text accepted by validation', function (string $column) {
    expect(Schema::getColumnType('request_tranches', $column))->toBe('text');
})->with(['comment', 'payment_proof']);

test('paying with the maximum web comment and proof preserves every character', function () {
    $comment = str_repeat('Я', 500);
    $proof = str_repeat('Б', 500);
    $this->actingAs($this->actor)->putJson(route('finance.tranches.update', [$this->request->id, $this->tranche->id]), [
        'status' => 'paid', 'comment' => $comment, 'payment_proof' => $proof,
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect($this->tranche->fresh()->comment)->toBe($comment)->and($this->tranche->fresh()->payment_proof)->toBe($proof)
        ->and($this->request->fresh()->status)->toBe('paid');
});

test('creation preserves a maximum length payment schedule comment', function () {
    $signer = makeUserWithRole('approver');
    $signer->projects()->attach($this->project);
    $comment = str_repeat('Ж', 1000);
    $request = app(ApprovalRequestCreation::class)->create($this->actor, [
        'type' => 'approval', 'project_id' => $this->project->id, 'currency' => 'USD', 'transaction_type' => 'bank',
        'supplier_name' => 'Vendor', 'approvers' => [$signer->id],
        'items' => [['name' => 'Item', 'quantity' => 1, 'price_per_unit' => 100]],
        'tranches' => [['amount' => 100, 'due_date' => today()->toDateString(), 'comment' => $comment]],
    ]);
    expect($request->tranches()->sole()->comment)->toBe($comment);
});

test('text migration preserves existing data and rollback never truncates accepted text', function () {
    $this->tranche->update(['comment' => 'Existing note', 'payment_proof' => 'Existing evidence']);
    Schema::table('request_tranches', function (Blueprint $table) {
        $table->string('comment')->nullable()->change();
        $table->string('payment_proof')->nullable()->change();
    });
    $storageNode = DB::getDriverName() === 'pgsql'
        ? DB::selectOne("SELECT relfilenode FROM pg_class WHERE oid = 'request_tranches'::regclass")->relfilenode
        : null;
    $migration = require database_path('migrations/2026_10_20_000001_expand_tranche_text_fields.php');
    $migration->up();
    if ($storageNode !== null) {
        // PostgreSQL must widen these columns without rewriting the table.
        expect(DB::selectOne("SELECT relfilenode FROM pg_class WHERE oid = 'request_tranches'::regclass")->relfilenode)->toBe($storageNode);
    }
    expect($this->tranche->fresh()->comment)->toBe('Existing note')->and($this->tranche->fresh()->payment_proof)->toBe('Existing evidence');
    $legacyId = DB::table('request_tranches')->insertGetId([
        'approval_request_id' => $this->request->id, 'amount' => 1, 'status' => 'pending',
        'comment' => null, 'payment_proof' => null,
    ]);
    expect(DB::table('request_tranches')->find($legacyId)->comment)->toBeNull();
    $comment = str_repeat('Я', 1000);
    $proof = str_repeat('Б', 500);
    $this->tranche->update(['comment' => $comment, 'payment_proof' => $proof]);
    $migration->down();
    expect($this->tranche->fresh()->comment)->toBe($comment)->and($this->tranche->fresh()->payment_proof)->toBe($proof);
    $migration->up();
    expect($this->tranche->fresh()->comment)->toBe($comment)->and($this->tranche->fresh()->payment_proof)->toBe($proof);
});
