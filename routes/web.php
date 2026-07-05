<?php

use App\Http\Controllers\FileBrowserController;
use App\Http\Controllers\ProjectController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Local single-user tool — no auth on the app itself.
Route::get('/', [ProjectController::class, 'index'])->name('home');
Route::get('browse', [FileBrowserController::class, 'browse'])->name('browse');
Route::post('uploads/init', [\App\Http\Controllers\UploadController::class, 'init'])->name('uploads.init');
Route::put('uploads/{id}/chunk', [\App\Http\Controllers\UploadController::class, 'chunk'])->name('uploads.chunk');
Route::post('uploads/{id}/finish', [\App\Http\Controllers\UploadController::class, 'finish'])->name('uploads.finish');
Route::get('projects/create', [ProjectController::class, 'create'])->name('projects.create');
Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');
Route::get('projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
Route::post('projects/{project}/videos', [ProjectController::class, 'storeVideos'])->name('projects.videos.store');
Route::get('projects/{project}/status', [ProjectController::class, 'status'])->name('projects.status');
Route::get('projects/{project}/videos/{video}/proxy', [ProjectController::class, 'proxy'])->name('projects.proxy');
Route::get('projects/{project}/videos/{video}/thumb', [ProjectController::class, 'thumb'])->name('projects.thumb');
Route::post('projects/{project}/videos/{video}/segments', [ProjectController::class, 'storeSegment'])->name('projects.segments.store');
Route::patch('projects/{project}/segments/{segment}', [ProjectController::class, 'updateSegment'])->name('projects.segments.update');
Route::delete('projects/{project}/segments/{segment}', [ProjectController::class, 'destroySegment'])->name('projects.segments.destroy');
Route::post('projects/{project}/music', [ProjectController::class, 'updateMusic'])->name('projects.music');
Route::post('projects/{project}/render', [ProjectController::class, 'render'])->name('projects.render');
Route::get('projects/{project}/output/{aspect}', [ProjectController::class, 'output'])->name('projects.output');
Route::delete('projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', function () {
        return Inertia::render('dashboard');
    })->name('dashboard');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
