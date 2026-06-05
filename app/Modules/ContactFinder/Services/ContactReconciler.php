<?php

declare(strict_types=1);

namespace App\Modules\ContactFinder\Services;

use App\Modules\ContactFinder\DTO\ContactCandidate;
use App\Modules\ContactFinder\DTO\ContactResult;

/**
 * Turns a company's raw candidates into one decision: who to contact, on what
 * channel, how sure we are, and — when we are not sure — an honest blank with
 * a reason instead of a fabricated contact.
 *
 * Channel priority follows CLARIFICATIONS (decision-maker first) and my Q2
 * assumption: a named decision-maker's business email, else a corroborated
 * phone, else nothing. A conflict is never resolved by guessing a winner.
 */
final class ContactReconciler
{
    /** CLARIFICATIONS fixes the cutoff at 70. */
    public const THRESHOLD = 70;

    /** Canonical source ordering for stable provenance + source strings. */
    private const SOURCE_ORDER = ['registry', 'listing', 'enrichment'];

    public function __construct(private readonly ConfidenceScorer $scorer)
    {
    }

    public function reconcile(string $companyName, array $candidates): ContactResult
    {
        $byId = [];
        foreach ($candidates as $c) {
            $byId[$c->sourceId] = $c;
        }
        $registry = $byId['registry'] ?? null;
        $listing = $byId['listing'] ?? null;
        $enrichment = $byId['enrichment'] ?? null;

        $scored = $this->scorer->score($companyName, $candidates);
        $score = $scored['score'];
        $conflict = $scored['conflict'];

        $status = $this->resolveStatus($candidates, $conflict, $score);
        $verified = $status === ContactResult::STATUS_VERIFIED;

        // Identity: registry is the most authoritative name; listing as fallback.
        $name = $this->firstName($registry, $listing);
        $role = $registry?->role ?? '';

        // We surface a contact value only when we are confident. Below threshold,
        // on conflict, or with nothing found, we return "" (never a guess).
        $contactValue = $verified ? $this->pickContactValue($listing, $enrichment) : '';

        // On a conflict we do not pick a person at all — both names go in the reason.
        $contactName = ($verified || $status === ContactResult::STATUS_NEEDS_REVIEW) ? ($name ?? '') : '';
        $contactRole = $contactName === '' ? '' : $role;

        return new ContactResult(
            companyName: $companyName,
            contactName: $contactName,
            contactRole: $contactRole,
            contactEmailOrPhone: $contactValue,
            confidenceScore: $score,
            source: $this->sourceString($candidates),
            needsHumanReview: ! $verified,
            status: $status,
            reason: $this->reason($status, $score, $registry, $listing, $scored['reasons']),
            provenance: $this->provenance($candidates),
            scoreReasons: $scored['reasons'],
        );
    }

    private function resolveStatus(array $candidates, bool $conflict, int $score): string
    {
        return match (true) {
            $candidates === [] => ContactResult::STATUS_UNVERIFIED,
            $conflict => ContactResult::STATUS_CONFLICTING,
            $score >= self::THRESHOLD => ContactResult::STATUS_VERIFIED,
            default => ContactResult::STATUS_NEEDS_REVIEW,
        };
    }

    private function firstName(?ContactCandidate $registry, ?ContactCandidate $listing): ?string
    {
        if ($registry !== null && $registry->hasName()) {
            return $registry->name;
        }
        if ($listing !== null && $listing->hasName()) {
            return $listing->name;
        }

        return null;
    }

    /** Named decision-maker's email first, then a phone, else nothing. */
    private function pickContactValue(?ContactCandidate $listing, ?ContactCandidate $enrichment): string
    {
        if ($enrichment !== null && $enrichment->email !== null && $enrichment->email !== '') {
            return $enrichment->email;
        }

        $phone = $enrichment?->phone ?? $listing?->phone;

        return $phone ?? '';
    }

    private function sourceString(array $candidates): string
    {
        $present = array_map(static fn (ContactCandidate $c): string => $c->sourceId, $candidates);
        $ordered = array_values(array_filter(self::SOURCE_ORDER, static fn (string $s): bool => in_array($s, $present, true)));

        return implode('+', $ordered);
    }

    private function provenance(array $candidates): array
    {
        $out = [];
        foreach (self::SOURCE_ORDER as $sourceId) {
            foreach ($candidates as $c) {
                if ($c->sourceId === $sourceId) {
                    $out[] = ['source' => $c->sourceId, 'source_url' => $c->sourceUrl, 'raw' => $c->raw];
                }
            }
        }

        return $out;
    }

    private function reason(string $status, int $score, ?ContactCandidate $registry, ?ContactCandidate $listing, array $scoreReasons): string
    {
        return match ($status) {
            ContactResult::STATUS_VERIFIED => 'Corroborated above threshold (' . $score . '): ' . ($scoreReasons[0] ?? 'multiple sources agree') . '.',
            ContactResult::STATUS_CONFLICTING => sprintf(
                'Sources disagree on the contact (%s vs %s); routed to human review rather than guessing.',
                $registry?->name ?? 'unknown',
                $listing?->name ?? 'unknown',
            ),
            ContactResult::STATUS_UNVERIFIED => 'No source returned data for this company; cannot verify.',
            default => 'Below confidence threshold (' . $score . ' < ' . self::THRESHOLD . '); ' . ($scoreReasons[0] ?? 'insufficient corroboration') . '.',
        };
    }
}
