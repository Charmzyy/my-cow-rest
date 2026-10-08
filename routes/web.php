<?php

use Illuminate\Support\Facades\Route;

/*
| This backend is API-only: everything lives in routes/api.php under /api.
| The web UI is the separate Vue app (my-cow-client).
*/

Route::get('/', fn () => response()->json(['name' => 'MyCow API', 'docs' => '/api']));
