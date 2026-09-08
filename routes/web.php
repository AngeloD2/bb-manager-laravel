<?php

use Illuminate\Support\Facades\Route;

// The billboard player SPA. It does its own screen switching in App.jsx (config
// vs. player) off the persisted billboard session, so a single entry point is all
// it needs — there is no client-side router to catch sub-paths for.
Route::view('/player', 'player')->name('player');
