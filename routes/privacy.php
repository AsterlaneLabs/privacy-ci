<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use PrivacyCI\Http\ReactivationController;

/*
 * GET only renders a confirmation page; the reactivation itself happens on POST.
 *
 * This is not ceremony. Mail security scanners and link prefetchers. Outlook
 * Safe Links, corporate proxies, some mobile clients, follow every URL in an
 * email. A GET that mutated state would silently reactivate every account the
 * moment the notice was delivered, and the grace period would never expire for
 * anyone.
 */
Route::middleware('signed')->group(function (): void {
    Route::get('/{request}', [ReactivationController::class, 'show'])
        ->name('privacy.reactivate');

    Route::post('/{request}', [ReactivationController::class, 'confirm'])
        ->name('privacy.reactivate.confirm');
});
