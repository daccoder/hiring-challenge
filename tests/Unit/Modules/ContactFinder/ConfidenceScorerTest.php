<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\ContactFinder;

use App\Modules\ContactFinder\DTO\ContactCandidate;
use App\Modules\ContactFinder\Services\ConfidenceScorer;
use PHPUnit\Framework\TestCase;

class ConfidenceScorerTest extends TestCase
{
    private ConfidenceScorer $scorer;

    protected function setUp(): void
    {
        $this->scorer = new ConfidenceScorer();
    }

    public function test_three_agreeing_sources_score_high(): void
    {
        $result = $this->scorer->score('Cedar Ridge Plumbing LLC', [
            new ContactCandidate('registry', 'mock://r', name: 'Daniel Ortega', role: 'Owner'),
            new ContactCandidate('listing', 'mock://l', name: 'Daniel Ortega', phone: '+1-402-555-0148'),
            new ContactCandidate('enrichment', 'mock://e', email: 'd.ortega@cedarridgeplumbing.com', providerConfidence: 84),
        ]);

        $this->assertGreaterThanOrEqual(70, $result['score']);
        $this->assertFalse($result['conflict']);
    }

    public function test_two_corroborating_sources_clear_threshold(): void
    {
        $result = $this->scorer->score('Bayview Auto Repair', [
            new ContactCandidate('registry', 'mock://r', name: 'Karen Liu', role: 'Owner'),
            new ContactCandidate('enrichment', 'mock://e', email: 'karen@bayviewauto.com', phone: '+1-253-555-0192', providerConfidence: 78),
        ]);

        $this->assertGreaterThanOrEqual(70, $result['score']);
    }

    public function test_single_weak_enrichment_falls_below_threshold(): void
    {
        $result = $this->scorer->score('Riverside Print & Sign', [
            new ContactCandidate('enrichment', 'mock://e', email: 'info@riversideprint.biz', providerConfidence: 41),
        ]);

        $this->assertLessThan(70, $result['score']);
    }

    public function test_disagreeing_sources_flag_conflict_and_cap_score(): void
    {
        $result = $this->scorer->score('Coastal Breeze Pool Service', [
            new ContactCandidate('registry', 'mock://r', name: 'Tina Alvarez', role: 'Manager'),
            new ContactCandidate('listing', 'mock://l', name: 'Marcus Webb', phone: '+1-941-555-0146'),
        ]);

        $this->assertTrue($result['conflict']);
        $this->assertLessThanOrEqual(45, $result['score']);
    }

    public function test_registered_agent_only_is_not_a_decision_maker(): void
    {
        $result = $this->scorer->score('Northgate HVAC Services', [
            new ContactCandidate('registry', 'mock://r', name: 'Thomas Reed', role: 'Registered Agent'),
        ]);

        $this->assertLessThan(70, $result['score']);
    }

    public function test_no_sources_scores_zero(): void
    {
        $result = $this->scorer->score('Desert Sky Solar', []);

        $this->assertSame(0, $result['score']);
        $this->assertFalse($result['conflict']);
    }

    public function test_every_point_has_a_reason(): void
    {
        $result = $this->scorer->score('Pioneer Landscaping Inc', [
            new ContactCandidate('registry', 'mock://r', name: 'Maria Gomez', role: 'President'),
            new ContactCandidate('listing', 'mock://l', name: 'Maria Gomez', phone: '+1-208-555-0175'),
            new ContactCandidate('enrichment', 'mock://e', email: 'maria@pioneerlandscaping.com', phone: '+1-208-555-0175', providerConfidence: 88),
        ]);

        $this->assertNotEmpty($result['reasons']);
    }
}
