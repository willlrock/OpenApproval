<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ApprovalRouteRecovery;
use App\Services\UserAdministration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class SignatureAdminController extends Controller
{
    public function show(Request $request, int $id)
    {
        $user = User::findOrFail($id);
        app(UserAdministration::class)->assertCanManage($request->user(), $user);
        abort_unless($user->signature_path && Storage::disk('local')->exists($user->signature_path), 404);

        return response()->file(Storage::disk('local')->path($user->signature_path), [
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ])->setPrivate();
    }

    public function uploadSignature(Request $request, int $id)
    {
        $request->validate(['signature' => 'required|image|mimes:png,jpg,jpeg|max:2048']);
        app(UserAdministration::class)->transaction($request->user(), $id, function (User $user) use ($request) {
            $path = $request->file('signature')->store('signatures', 'local');
            $oldPath = $user->signature_path;
            try {
                $user->update(['signature_path' => $path]);
            } catch (\Throwable $e) {
                Storage::disk('local')->delete($path);
                throw $e;
            }
            if ($oldPath) {
                DB::afterCommit(fn () => Storage::disk('local')->delete($oldPath));
            }
        });

        return back()->with('success', 'Подпись загружена');
    }

    public function deleteSignature(Request $request, int $id)
    {
        app(UserAdministration::class)->transaction($request->user(), $id, function (User $user) use ($request) {
            $path = $user->signature_path;
            $user->update(['signature_path' => null]);
            app(ApprovalRouteRecovery::class)->accessChanged($user->fresh(), $request->user());
            if ($path) {
                DB::afterCommit(fn () => Storage::disk('local')->delete($path));
            }
        });

        return back()->with('success', 'Подпись удалена');
    }
}
