<?php

namespace App\Http\Controllers;

use App\Models\Library;
use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * The library resolved by the ResolveLibrary ("library") middleware.
     */
    protected function library(Request $request): Library
    {
        return $request->attributes->get('library');
    }
}
