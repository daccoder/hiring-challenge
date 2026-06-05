<?php

declare(strict_types=1);

namespace App\Modules\ContactFinder\Support;

/**
 * Deliberately simple, explainable name/identity matching — no fuzzy ML, just
 * token overlap we can justify in a review.
 *
 * Two people "agree" when they share a token of length >= 3 (a surname like
 * "Murphy" or "Kowalski", which survives "S. Murphy" and "Bob"/"Robert").
 * An email local-part "agrees" with a name when it contains such a token
 * ("a.brooks" -> "Angela Brooks", "g.whitfield" -> "George Whitfield").
 */
final class NameMatcher
{
    private const STOPWORDS = ['dr', 'mr', 'mrs', 'ms', 'the', 'manager', 'owner', 'inc', 'llc'];

    /** Normalised, meaningful tokens of a person name. */
    public static function tokens(?string $name): array
    {
        if ($name === null) {
            return [];
        }

        $clean = strtolower((string) preg_replace('/[^a-z]+/i', ' ', $name));
        $tokens = array_filter(
            preg_split('/\s+/', trim($clean)) ?: [],
            static fn (string $t): bool => strlen($t) >= 2 && ! in_array($t, self::STOPWORDS, true),
        );

        return array_values($tokens);
    }

    /** The strongest shared token (>= 3 chars) between two names, or null. */
    public static function sharedToken(?string $a, ?string $b): ?string
    {
        $shared = array_values(array_intersect(self::tokens($a), self::tokens($b)));
        $strong = array_values(array_filter($shared, static fn (string $t): bool => strlen($t) >= 3));

        if ($strong === []) {
            return null;
        }

        usort($strong, static fn (string $x, string $y): int => strlen($y) <=> strlen($x));

        return $strong[0];
    }

    public static function namesAgree(?string $a, ?string $b): bool
    {
        return self::sharedToken($a, $b) !== null;
    }

    /** Does an email's local-part carry a >= 3 char token from the name? */
    public static function emailAgreesWithName(?string $email, ?string $name): bool
    {
        if ($email === null || $name === null || ! str_contains($email, '@')) {
            return false;
        }

        $local = strtolower((string) strstr($email, '@', true));
        $localTokens = array_filter(
            preg_split('/[^a-z]+/', $local) ?: [],
            static fn (string $t): bool => strlen($t) >= 3,
        );

        return array_intersect($localTokens, self::tokens($name)) !== [];
    }

    /** A name with a single meaningful token (e.g. "Jeff", "Dr. Patel") is weak. */
    public static function isSingleToken(?string $name): bool
    {
        return count(self::tokens($name)) === 1;
    }
}
