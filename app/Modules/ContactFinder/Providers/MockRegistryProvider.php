<?php

declare(strict_types=1);

namespace App\Modules\ContactFinder\Providers;

use App\Modules\ContactFinder\Contracts\ContactProvider;
use App\Modules\ContactFinder\DTO\ContactCandidate;

/**
 * Business-registry lookup: authoritative for legal identity (owner / officer /
 * registered agent), but stale and rarely carries email or phone.
 */
final class MockRegistryProvider implements ContactProvider
{
    public function __construct(private readonly MockProviderRepository $repo)
    {
    }

    public function id(): string
    {
        return 'registry';
    }

    public function lookup(string $companyName): ?ContactCandidate
    {
        $blob = $this->repo->source($companyName, $this->id());

        if ($blob === null) {
            return null;
        }

        return new ContactCandidate(
            sourceId: $this->id(),
            sourceUrl: (string) ($blob['source_url'] ?? ''),
            name: $blob['name'] ?? null,
            role: $blob['role'] ?? null,
            raw: $blob,
        );
    }
}
