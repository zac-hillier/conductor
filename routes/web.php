<?php

use App\Livewire\Activity;
use App\Livewire\Board;
use App\Livewire\Inbox;
use App\Livewire\Overview;
use App\Livewire\PlanDetail;
use App\Livewire\Plans;
use App\Livewire\Projects;
use App\Livewire\Settings;
use Illuminate\Support\Facades\Route;

Route::get('/', Overview::class)->name('overview');
Route::get('/profiles/{profile:slug}/board', Board::class)->name('profiles.board');
Route::get('/profiles/{profile:slug}/projects', Projects::class)->name('profiles.projects');
Route::get('/profiles/{profile:slug}/plans', Plans::class)->name('profiles.plans');
Route::get('/profiles/{profile:slug}/plans/{plan}', PlanDetail::class)->name('profiles.plans.show');
Route::get('/profiles/{profile:slug}/inbox', Inbox::class)->name('profiles.inbox');
Route::get('/profiles/{profile:slug}/activity', Activity::class)->name('profiles.activity');
Route::get('/profiles/{profile:slug}/settings', Settings::class)->name('profiles.settings');
