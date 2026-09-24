<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('scramble.docs.ui'));
