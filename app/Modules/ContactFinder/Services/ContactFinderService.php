<?php

declare(strict_types=1);

namespace App\Modules\ContactFinder\Services;

use App\Modules\ContactFinder\Contracts\ContactProvider;
use App\Modules\ContactFinder\DTO\ContactResult;

/**
 * Orchestrates one row end-to-end: fan out to every provider (failure-isolated
 * — one source erroring must not poison the row), collect candidates, hand off
 * to the reconciler. Per-row and stateless, so a 1k-row batch is just a loop
 * (and, in production, one idempotent queued job per row).
 *
 * @param list<ContactProvider> $providers
 */
final class ContactFinderService
{
    public function __construct(
        private readonly array $providers,
        private readonly ContactReconciler $reconciler,
    ) {
    }

    public function findForCompany(string $companyName): ContactResult
    {
        $candidates = [];

        foreach ($this->providers as $provider) {
            try {
                $candidate = $provider->lookup($companyName);
            } catch (\Throwable) {
                // A provider failure is a "not found" from that source, never a dead row.
                $candidate = null;
            }

            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        return $this->reconciler->reconcile($companyName, $candidates);
    }

    /**
     * @param iterable<string> $companyNames
     * @return list<ContactResult>
     */
    public function findForCompanies(iterable $companyNames): array
    {
        $results = [];
        foreach ($companyNames as $name) {
            $results[] = $this->findForCompany($name);
        }

        return $results;
    }
}
