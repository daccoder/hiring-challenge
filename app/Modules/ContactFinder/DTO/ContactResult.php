<?php

declare(strict_types=1);

namespace App\Modules\ContactFinder\DTO;

/**
 * The decision for one input row: the emitted contact (or an honest blank),
 * its confidence, why, and the full provenance behind it.
 *
 * Status uses neutral language on purpose (per the repo CLAUDE.md: no negative
 * words in surfaced text) — every row is a state we can act on, never a "failure".
 */
final class ContactResult
{
    public const STATUS_VERIFIED = 'verified';        // >= threshold, safe to action
    public const STATUS_NEEDS_REVIEW = 'needs_review'; // had data but below threshold
    public const STATUS_CONFLICTING = 'conflicting';   // sources disagree on the person
    public const STATUS_UNVERIFIED = 'unverified';     // no source returned anything

    public function __construct(
        public readonly string $companyName,
        public readonly string $contactName,
        public readonly string $contactRole,
        public readonly string $contactEmailOrPhone,
        public readonly int $confidenceScore,
        public readonly string $source,           // e.g. "registry+listing+enrichment"
        public readonly bool $needsHumanReview,
        public readonly string $status,
        public readonly string $reason,
        public readonly array $provenance = [],   // [{source, source_url, raw}]
        public readonly array $scoreReasons = [], // human-readable score breakdown
    ) {
    }

    /** The six fields the challenge asks for, in order. */
    public function toRow(): array
    {
        return [
            'company_name' => $this->companyName,
            'contact_name' => $this->contactName,
            'contact_role' => $this->contactRole,
            'contact_email_or_phone' => $this->contactEmailOrPhone,
            'confidence_score' => $this->confidenceScore,
            'source' => $this->source,
            'needs_human_review' => $this->needsHumanReview ? 'true' : 'false',
            'status' => $this->status,
            'reason' => $this->reason,
        ];
    }

    public function toArray(): array
    {
        return $this->toRow() + [
            'score_reasons' => $this->scoreReasons,
            'provenance' => $this->provenance,
        ];
    }
}
