<?php

declare(strict_types=1);

namespace App\Modules\ContactFinder\Providers;

/**
 * Loads the canned fixture (challenge/mocks/enrichment_responses.json) once and
 * hands back the per-company, per-source blobs. Stands in for "the network".
 *
 * A company key being absent, or a source key being absent within it, both mean
 * "this source found nothing" — the providers translate that to null.
 */
final class MockProviderRepository
{
    private array $data;

    public function __construct(string $fixturePath)
    {
        if (! is_file($fixturePath)) {
            throw new \InvalidArgumentException("Mock fixture not found at: {$fixturePath}");
        }

        $decoded = json_decode((string) file_get_contents($fixturePath), true);

        if (! is_array($decoded)) {
            throw new \RuntimeException("Mock fixture is not valid JSON: {$fixturePath}");
        }

        $this->data = $decoded;
    }

    /** The raw blob for one source ('registry'|'listing'|'enrichment') or null. */
    public function source(string $companyName, string $sourceId): ?array
    {
        $company = $this->data[$companyName] ?? null;

        if (! is_array($company)) {
            return null;
        }

        $blob = $company[$sourceId] ?? null;

        return is_array($blob) ? $blob : null;
    }
}
