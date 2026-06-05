<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\ContactFinder;

use App\Modules\ContactFinder\Support\NameMatcher;
use PHPUnit\Framework\TestCase;

class NameMatcherTest extends TestCase
{
    public function test_surnames_agree_across_abbreviation(): void
    {
        $this->assertTrue(NameMatcher::namesAgree('Sean Murphy', 'S. Murphy'));
    }

    public function test_surnames_agree_across_nickname(): void
    {
        // Bob vs Robert: the first names differ, but the surname carries it.
        $this->assertTrue(NameMatcher::namesAgree('Robert Kowalski', 'Bob Kowalski'));
    }

    public function test_different_people_do_not_agree(): void
    {
        $this->assertFalse(NameMatcher::namesAgree('Tina Alvarez', 'Marcus Webb'));
    }

    public function test_title_is_ignored(): void
    {
        $this->assertTrue(NameMatcher::namesAgree('Dr. Emily Hart', 'Emily Hart'));
    }

    public function test_email_local_part_corroborates_surname(): void
    {
        $this->assertTrue(NameMatcher::emailAgreesWithName('a.brooks@greenfieldcater.com', 'Angela Brooks'));
        $this->assertTrue(NameMatcher::emailAgreesWithName('g.whitfield@tidewaterph.com', 'George Whitfield'));
    }

    public function test_email_local_part_corroborates_first_name(): void
    {
        $this->assertTrue(NameMatcher::emailAgreesWithName('karen@bayviewauto.com', 'Karen Liu'));
    }

    public function test_generic_email_does_not_corroborate(): void
    {
        $this->assertFalse(NameMatcher::emailAgreesWithName('info@riversideprint.biz', 'Angela Brooks'));
    }

    public function test_single_token_name_is_flagged_weak(): void
    {
        $this->assertTrue(NameMatcher::isSingleToken('Jeff (manager)'));
        $this->assertTrue(NameMatcher::isSingleToken('Dr. Patel'));
        $this->assertFalse(NameMatcher::isSingleToken('Sean Murphy'));
    }
}
