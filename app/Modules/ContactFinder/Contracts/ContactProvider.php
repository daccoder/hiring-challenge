<?php

declare(strict_types=1);

namespace App\Modules\ContactFinder\Contracts;

use App\Modules\ContactFinder\DTO\ContactCandidate;

/**
 * One swappable data source. Real implementations (a registry API, a maps
 * listing, an enrichment vendor) would live behind this same port; for the
 * challenge the three Mock* providers read canned fixtures instead.
 *
 * Contract: a provider returns null when it has nothing for a company. It must
 * never throw for a "not found" — that is a normal, expected outcome.
 */
interface ContactProvider
{
    public function id(): string;

    public function lookup(string $companyName): ?ContactCandidate;
}
