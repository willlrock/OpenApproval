<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\TelegramAccountLink;
use Illuminate\Http\Request;

class TelegramLinkController extends Controller
{
    public function store(Request $request, TelegramAccountLink $links)
    {
        return back()->with('telegram_link_code', $links->issue($request->user()));
    }
}
