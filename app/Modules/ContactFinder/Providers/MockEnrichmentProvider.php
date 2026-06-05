<?php

declare(strict_types=1);

namespace App\Modules\ContactFinder\Providers;

use App\Modules\ContactFinder\Contracts\ContactProvider;
use App\Modules\ContactFinder\DTO\ContactCandidate;

/**
 * Email/phone enrichment vendor: can supply a candidate email or phone with its
 * own self-reported confidence. Sometimes returns nothing, sometimes a
 * plausible-but-weak guess — so its confidence is an input, never the verdict.
 */
final class MockEnrichmentProvider implements ContactProvider
{
    public function __construct(private readonly MockProviderRepository $repo)
    {
    }

    public function id(): string
    {
        return 'enrichment';
    }

    public function lookup(string $companyName): ?ContactCandidate
    {
        $blob = $this->repo->source($companyName, $this->id());

        if ($blob === null) {
            return null;
        }

        $confidence = $blob['provider_confidence'] ?? null;

        return new ContactCandidate(
            sourceId: $this->id(),
            sourceUrl: (string) ($blob['source_url'] ?? ''),
            email: $blob['email'] ?? null,
            phone: $blob['phone'] ?? null,
            providerConfidence: $confidence === null ? null : (int) $confidence,
            raw: $blob,
        );
    }
}
