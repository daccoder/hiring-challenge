<?php

declare(strict_types=1);

namespace App\Modules\ContactFinder\Providers;

use App\Modules\ContactFinder\Contracts\ContactProvider;
use App\Modules\ContactFinder\DTO\ContactCandidate;

/**
 * Web / maps business listing: good for a main business phone and sometimes a
 * role-less or partial name. No strong identity guarantee on its own.
 */
final class MockListingProvider implements ContactProvider
{
    public function __construct(private readonly MockProviderRepository $repo)
    {
    }

    public function id(): string
    {
        return 'listing';
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
            phone: $blob['phone'] ?? null,
            raw: $blob,
        );
    }
}
