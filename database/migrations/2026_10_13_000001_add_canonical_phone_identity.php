<?php

use App\Support\PhoneNumber;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->string('phone_normalized', 15)->nullable()->unique());
        $counts = [];
        DB::table('users')->select('id', 'phone')->orderBy('id')->chunkById(1000, function ($users) use (&$counts) {
            foreach ($users as $user) {
                $canonical = PhoneNumber::canonical($user->phone);
                if ($canonical !== null) {
                    $counts[$canonical] = ($counts[$canonical] ?? 0) + 1;
                }
            }
        });
        DB::table('users')->select('id', 'phone')->orderBy('id')->chunkById(1000, function ($users) use ($counts) {
            foreach ($users as $user) {
                $canonical = PhoneNumber::canonical($user->phone);
                if ($canonical !== null && $counts[$canonical] === 1) {
                    DB::table('users')->where('id', $user->id)->update(['phone_normalized' => $canonical]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['phone_normalized']);
            $table->dropColumn('phone_normalized');
        });
    }
};
