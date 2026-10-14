<?php

use Illuminate\Support\Facades\Route;

// Every non-API URL is handled by the Vue router.
Route::view('/{any?}', 'app')->where('any', '^(?!api|up).*$');
