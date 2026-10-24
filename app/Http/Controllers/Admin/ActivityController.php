<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminActivity;

/** Everything changed in the admin panel, newest first. */
class ActivityController extends Controller
{
    public function index()
    {
        return view('admin.activity', [
            'activity' => AdminActivity::with(['user', 'library'])->latest()->paginate(50),
        ]);
    }
}
