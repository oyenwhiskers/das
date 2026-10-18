<?php

use App\Http\Controllers\BimbsecFileController;
use App\Http\Controllers\DocumentFileController;
use App\Livewire\AiUsage\Index as AiUsageIndex;
use App\Livewire\Bimbsec\BatchShow as BimbsecBatchShow;
use App\Livewire\Bimbsec\Index as BimbsecIndex;
use App\Livewire\Bimbsec\Show as BimbsecShow;
use App\Livewire\Customers\Create as CustomersCreate;
use App\Livewire\Customers\Index as CustomersIndex;
use App\Livewire\Customers\Show as CustomersShow;
use App\Livewire\Documents\Index as DocumentsIndex;
use App\Livewire\Documents\Show as DocumentsShow;
use App\Livewire\Users\Index as UsersIndex;
use App\Livewire\Users\Show as UsersShow;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login');

Route::middleware(['auth', 'active'])->group(function () {
    Route::get('/customers', CustomersIndex::class)->name('customers.index');
    Route::get('/customers/create', CustomersCreate::class)->name('customers.create');
    Route::get('/customers/{customer}', CustomersShow::class)->name('customers.show');

    Route::get('/bimbsec', BimbsecIndex::class)->name('bimbsec.index');
    Route::get('/bimbsec/batches/{batch}', BimbsecBatchShow::class)->name('bimbsec.batches.show');
    Route::get('/bimbsec/batches/{batch}/file', [BimbsecFileController::class, 'batch'])->name('bimbsec.batches.file');
    Route::get('/bimbsec/batches/{batch}/print', [BimbsecFileController::class, 'printBatch'])->name('bimbsec.batches.print');
    Route::get('/bimbsec/{customer}/applications/{application}', BimbsecShow::class)->name('bimbsec.show');
    Route::get('/bimbsec/{customer}/applications/{application}/file', [BimbsecFileController::class, 'document'])->name('bimbsec.file');
    Route::get('/bimbsec/{customer}/applications/{application}/print', [BimbsecFileController::class, 'print'])->name('bimbsec.print');

    Route::get('/documents', DocumentsIndex::class)->name('documents.index');
    Route::get('/documents/{documentScan}', DocumentsShow::class)->name('documents.show');
    Route::get('/documents/{documentScan}/file', DocumentFileController::class)->name('documents.file');

    Route::view('profile', 'profile')->name('profile');

    Route::middleware('admin')->group(function () {
        Route::get('/ai-usage', AiUsageIndex::class)->name('ai-usage.index');
        Route::get('/users', UsersIndex::class)->name('users.index');
        Route::get('/users/{user}', UsersShow::class)->name('users.show');
    });
});

require __DIR__.'/auth.php';
