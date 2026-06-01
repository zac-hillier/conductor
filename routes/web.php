<?php

use App\Livewire\Activity;
use App\Livewire\Board;
use App\Livewire\Inbox;
use App\Livewire\Overview;
use App\Livewire\Settings;
use Illuminate\Support\Facades\Route;

Route::get('/', Overview::class)->name('overview');
Route::get('/profiles/{profile:slug}/board', Board::class)->name('profiles.board');
Route::get('/profiles/{profile:slug}/inbox', Inbox::class)->name('profiles.inbox');
Route::get('/profiles/{profile:slug}/activity', Activity::class)->name('profiles.activity');
Route::get('/profiles/{profile:slug}/settings', Settings::class)->name('profiles.settings');
