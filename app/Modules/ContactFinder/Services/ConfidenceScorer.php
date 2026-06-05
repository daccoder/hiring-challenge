<?php

declare(strict_types=1);

namespace App\Modules\ContactFinder\Services;

use App\Modules\ContactFinder\DTO\ContactCandidate;
use App\Modules\ContactFinder\Support\NameMatcher;

/**
 * Explainable, additive confidence model. Every point is traceable to a reason
 * string, so a reviewer can audit *why* a row scored what it did — no opaque
 * model, by design (CLARIFICATIONS asks for explainable scoring).
 *
 *   base        strongest identity source (registry > listing-name > listing-phone > enrichment)
 *   + corrob    independent agreement on the person (name across sources, email, phone) — cap +40
 *   + domain    email domain aligns with the company name (+10)
 *   - penalties registered agent, role-based email with no person, single-token name
 *   conflict    two named sources that disagree -> capped at 45 (never auto-pick)
 *
 * @return array{score:int, conflict:bool, reasons:list<string>}
 */
final class ConfidenceScorer
{
    private const GENERIC_LOCALS = [
        'info', 'office', 'sales', 'contact', 'billing', 'ap', 'accounts',
        'accountspayable', 'admin', 'support', 'hello', 'mail',
    ];

    public function score(string $companyName, array $candidates): array
    {
        $reasons = [];

        if ($candidates === []) {
            return ['score' => 0, 'conflict' => false, 'reasons' => ['no source returned data for this company']];
        }

        $byId = [];
        foreach ($candidates as $c) {
            $byId[$c->sourceId] = $c;
        }
        $registry = $byId['registry'] ?? null;
        $listing = $byId['listing'] ?? null;
        $enrichment = $byId['enrichment'] ?? null;

        // --- base: strongest available identity source ---------------------
        $base = 0;
        $registeredAgent = false;

        if ($registry !== null && $registry->hasName()) {
            $registeredAgent = $registry->role !== null && stripos($registry->role, 'registered agent') !== false;
            $base = $registeredAgent ? 30 : 50;
            $reasons[] = $registeredAgent
                ? "registry lists only a registered agent ({$registry->name}) — not a decision-maker (base 30)"
                : "registry identifies {$registry->role} {$registry->name} (base 50)";
        } elseif ($listing !== null && $listing->hasName()) {
            $base = 35;
            $reasons[] = "listing names {$listing->name} (base 35)";
        } elseif ($listing !== null && $listing->phone !== null) {
            $base = 25;
            $reasons[] = 'listing has a business phone but no name (base 25)';
        } elseif ($enrichment !== null && $enrichment->providerConfidence !== null) {
            $base = (int) round($enrichment->providerConfidence * 0.5);
            $reasons[] = "single enrichment guess, provider_confidence {$enrichment->providerConfidence} (base {$base})";
        } elseif ($enrichment !== null) {
            $base = 10;
            $reasons[] = 'single enrichment guess, no provider_confidence (base 10)';
        }

        $primaryName = ($registry !== null && $registry->hasName()) ? $registry->name
            : (($listing !== null && $listing->hasName()) ? $listing->name : null);

        // --- conflict: two named sources that do NOT share a token ----------
        $conflict = false;
        if ($registry !== null && $registry->hasName()
            && $listing !== null && $listing->hasName()
            && ! NameMatcher::namesAgree($registry->name, $listing->name)) {
            $conflict = true;
            $reasons[] = "registry ({$registry->name}) and listing ({$listing->name}) disagree on the person — conflict";
        }

        // --- corroboration (cap +40) ---------------------------------------
        $corrob = 0;
        if ($primaryName !== null) {
            $agreeing = 0;
            foreach ([$registry, $listing] as $src) {
                if ($src !== null && $src->hasName() && NameMatcher::namesAgree($src->name, $primaryName)) {
                    $agreeing++;
                }
            }
            if ($enrichment !== null && NameMatcher::emailAgreesWithName($enrichment->email, $primaryName)) {
                $agreeing++;
                $reasons[] = "enrichment email corroborates {$primaryName}";
            }

            if ($agreeing >= 2) {
                $corrob += 25;
                $reasons[] = "name corroborated by {$agreeing} independent sources (+25)";
            }
            if ($agreeing >= 3) {
                $corrob += 15;
                $reasons[] = 'a third independent source agrees (+15)';
            }
        }

        if ($listing !== null && $listing->phone !== null
            && $enrichment !== null && $enrichment->phone !== null
            && $this->normalizePhone($listing->phone) === $this->normalizePhone($enrichment->phone)) {
            $corrob += 15;
            $reasons[] = 'phone matches across listing + enrichment (+15)';
        }
        $corrob = min($corrob, 40);

        // --- domain alignment ----------------------------------------------
        $domainBonus = 0;
        $email = $enrichment?->email;
        if ($email !== null && $this->domainMatches($companyName, $email)) {
            $domainBonus = 10;
            $reasons[] = 'email domain aligns with company name (+10)';
        }

        // --- penalties ------------------------------------------------------
        $penalty = 0;
        if ($registeredAgent) {
            $penalty += 10;
        }
        if ($primaryName === null && $email !== null && $this->isGenericEmail($email)) {
            $penalty += 10;
            $reasons[] = "role-based email ({$email}) with no named decision-maker (-10)";
        }
        if ($primaryName !== null && NameMatcher::isSingleToken($primaryName)) {
            $penalty += 5;
            $reasons[] = "identity is a single token ({$primaryName}) — cannot confirm decision-maker (-5)";
        }

        $score = max(0, min(100, $base + $corrob + $domainBonus - $penalty));

        if ($conflict) {
            $score = min($score, 45);
            $reasons[] = 'score capped at 45 — sources disagree, routed to a human';
        }

        return ['score' => $score, 'conflict' => $conflict, 'reasons' => $reasons];
    }

    private function normalizePhone(string $phone): string
    {
        return (string) preg_replace('/\D+/', '', $phone);
    }

    private function isGenericEmail(string $email): bool
    {
        $local = strtolower((string) strstr($email, '@', true));

        return in_array($local, self::GENERIC_LOCALS, true);
    }

    /**
     * True when the email's domain stem and the company stem contain one
     * another (>= 5 shared chars). Bidirectional so "bayviewauto" matches
     * "Bayview Auto Repair" and "greenfieldcater" matches "Greenfield Catering".
     */
    private function domainMatches(string $company, string $email): bool
    {
        if (! str_contains($email, '@')) {
            return false;
        }

        $domain = strtolower(substr($email, strpos($email, '@') + 1));
        $domainStem = (string) preg_replace('/[^a-z0-9]/', '', explode('.', $domain)[0]);
        $companyStem = (string) preg_replace('/[^a-z0-9]/', '', strtolower($company));

        $shorter = strlen($domainStem) <= strlen($companyStem) ? $domainStem : $companyStem;
        if (strlen($shorter) < 5) {
            return false;
        }

        return str_contains($companyStem, $domainStem) || str_contains($domainStem, $companyStem);
    }
}
