<?php

use App\Livewire\Activity;
use App\Livewire\Board;
use App\Livewire\Overview;
use Illuminate\Support\Facades\Route;

Route::get('/', Overview::class)->name('overview');
Route::get('/profiles/{profile:slug}/board', Board::class)->name('profiles.board');
Route::get('/profiles/{profile:slug}/activity', Activity::class)->name('profiles.activity');
