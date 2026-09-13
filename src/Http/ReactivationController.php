<?php

declare(strict_types=1);

namespace PrivacyCI\Http;

use Illuminate\Contracts\View\View;
use PrivacyCI\Lifecycle\DeletionSchedule;
use PrivacyCI\Lifecycle\DeletionStatus;
use PrivacyCI\Lifecycle\DeletionStore;

/**
 * The "actually, keep my account" flow.
 *
 * Deliberately does not sign the subject in. Reactivating restores the account;
 * proving who you are is still the application's normal login, and turning an
 * emailed link into a session is a far larger security surface than this
 * feature needs.
 */
final class ReactivationController
{
    public function __construct(
        private readonly DeletionStore $store,
        private readonly DeletionSchedule $schedule,
    ) {
    }

    public function show(string $request): View
    {
        $deletion = $this->store->find($request);

        if ($deletion === null) {
            return view('privacy::expired', ['reason' => 'unknown']);
        }

        if ($deletion->status !== DeletionStatus::PendingDelete) {
            return view('privacy::expired', [
                'reason' => $deletion->status === DeletionStatus::Cancelled ? 'already' : 'done',
            ]);
        }

        return view('privacy::reactivate', [
            'request' => $deletion,
            'days' => $deletion->daysRemaining(now()->toDateTimeImmutable()),
            'deletesOn' => $deletion->executeAfter,
        ]);
    }

    public function confirm(string $request): View
    {
        $deletion = $this->store->find($request);

        if ($deletion === null || $deletion->status !== DeletionStatus::PendingDelete) {
            // Re-submitting a form, or following the link twice, must not error.
            return view('privacy::expired', [
                'reason' => $deletion?->status === DeletionStatus::Cancelled ? 'already' : 'unknown',
            ]);
        }

        $this->schedule->reactivate(
            $deletion->subjectType,
            $deletion->subjectId,
            'reactivated from the emailed link',
        );

        return view('privacy::reactivated');
    }
}
