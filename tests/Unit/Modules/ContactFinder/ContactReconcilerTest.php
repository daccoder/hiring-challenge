<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\ContactFinder;

use App\Modules\ContactFinder\DTO\ContactCandidate;
use App\Modules\ContactFinder\DTO\ContactResult;
use App\Modules\ContactFinder\Services\ConfidenceScorer;
use App\Modules\ContactFinder\Services\ContactReconciler;
use PHPUnit\Framework\TestCase;

class ContactReconcilerTest extends TestCase
{
    private ContactReconciler $reconciler;

    protected function setUp(): void
    {
        $this->reconciler = new ContactReconciler(new ConfidenceScorer());
    }

    public function test_verified_row_emits_named_email_contact(): void
    {
        $result = $this->reconciler->reconcile('Cedar Ridge Plumbing LLC', [
            new ContactCandidate('registry', 'mock://r', name: 'Daniel Ortega', role: 'Owner'),
            new ContactCandidate('listing', 'mock://l', name: 'Daniel Ortega', phone: '+1-402-555-0148'),
            new ContactCandidate('enrichment', 'mock://e', email: 'd.ortega@cedarridgeplumbing.com', providerConfidence: 84),
        ]);

        $this->assertSame(ContactResult::STATUS_VERIFIED, $result->status);
        $this->assertFalse($result->needsHumanReview);
        $this->assertSame('Daniel Ortega', $result->contactName);
        $this->assertSame('d.ortega@cedarridgeplumbing.com', $result->contactEmailOrPhone);
        $this->assertSame('registry+listing+enrichment', $result->source);
    }

    public function test_verified_row_without_email_falls_back_to_phone(): void
    {
        $result = $this->reconciler->reconcile('Harbor Light Electric', [
            new ContactCandidate('registry', 'mock://r', name: 'Sean Murphy', role: 'Owner'),
            new ContactCandidate('listing', 'mock://l', name: 'S. Murphy', phone: '+1-508-555-0160'),
        ]);

        $this->assertSame(ContactResult::STATUS_VERIFIED, $result->status);
        $this->assertSame('+1-508-555-0160', $result->contactEmailOrPhone);
    }

    public function test_below_threshold_row_suppresses_the_contact_value(): void
    {
        $result = $this->reconciler->reconcile('Riverside Print & Sign', [
            new ContactCandidate('enrichment', 'mock://e', email: 'info@riversideprint.biz', providerConfidence: 41),
        ]);

        $this->assertSame(ContactResult::STATUS_NEEDS_REVIEW, $result->status);
        $this->assertTrue($result->needsHumanReview);
        $this->assertSame('', $result->contactEmailOrPhone);
    }

    public function test_conflicting_sources_pick_no_winner(): void
    {
        $result = $this->reconciler->reconcile('Coastal Breeze Pool Service', [
            new ContactCandidate('registry', 'mock://r', name: 'Tina Alvarez', role: 'Manager'),
            new ContactCandidate('listing', 'mock://l', name: 'Marcus Webb', phone: '+1-941-555-0146'),
        ]);

        $this->assertSame(ContactResult::STATUS_CONFLICTING, $result->status);
        $this->assertTrue($result->needsHumanReview);
        $this->assertSame('', $result->contactName);
        $this->assertSame('', $result->contactEmailOrPhone);
        $this->assertStringContainsString('Tina Alvarez', $result->reason);
        $this->assertStringContainsString('Marcus Webb', $result->reason);
    }

    public function test_no_data_is_unverified_not_a_failure(): void
    {
        $result = $this->reconciler->reconcile('Desert Sky Solar', []);

        $this->assertSame(ContactResult::STATUS_UNVERIFIED, $result->status);
        $this->assertSame(0, $result->confidenceScore);
        $this->assertSame('', $result->contactEmailOrPhone);
        $this->assertTrue($result->needsHumanReview);
    }

    public function test_every_emitted_value_is_traceable_to_a_source_url(): void
    {
        $result = $this->reconciler->reconcile('Cedar Ridge Plumbing LLC', [
            new ContactCandidate('registry', 'mock://registry/ne/cedar-ridge', name: 'Daniel Ortega', role: 'Owner'),
            new ContactCandidate('enrichment', 'mock://enrichment/cedar-ridge', email: 'd.ortega@cedarridgeplumbing.com', providerConfidence: 84),
        ]);

        $this->assertNotEmpty($result->provenance);
        foreach ($result->provenance as $entry) {
            $this->assertArrayHasKey('source_url', $entry);
            $this->assertStringStartsWith('mock://', $entry['source_url']);
        }
    }
}
