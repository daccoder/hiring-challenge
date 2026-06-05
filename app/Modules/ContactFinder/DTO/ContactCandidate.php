<?php

declare(strict_types=1);

namespace App\Modules\ContactFinder\DTO;

/**
 * A single contact hypothesis returned by one provider for one company.
 *
 * It is deliberately "dumb": it carries whatever the source asserted plus the
 * provenance needed to trace it back ({@see $sourceId}, {@see $sourceUrl}, {@see $raw}).
 * All reconciliation and scoring happens later, never inside a provider.
 */
final class ContactCandidate
{
    public function __construct(
        public readonly string $sourceId,        // 'registry' | 'listing' | 'enrichment'
        public readonly string $sourceUrl,       // mock://... provenance pointer
        public readonly ?string $name = null,
        public readonly ?string $role = null,
        public readonly ?string $email = null,
        public readonly ?string $phone = null,
        public readonly ?int $providerConfidence = null, // provider self-report, NOT our score
        public readonly array $raw = [],          // raw payload snapshot for the audit trail
    ) {
    }

    public function hasName(): bool
    {
        return $this->name !== null && trim($this->name) !== '';
    }

    public function hasContactMethod(): bool
    {
        return ($this->email !== null && $this->email !== '')
            || ($this->phone !== null && $this->phone !== '');
    }
}
