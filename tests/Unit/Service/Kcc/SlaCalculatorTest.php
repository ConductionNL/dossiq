<?php

/**
 * SlaCalculator Unit Tests
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service\Kcc
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service\Kcc;

use OCA\Procest\Service\Kcc\SlaCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SlaCalculator.
 *
 * @covers \OCA\Procest\Service\Kcc\SlaCalculator
 */
class SlaCalculatorTest extends TestCase
{

    private SlaCalculator $calculator;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->calculator = new SlaCalculator();
    }//end setUp()

    /**
     * @return void
     */
    public function testWeekendDetection(): void
    {
        // 2026-05-16 is a Saturday, 2026-05-17 a Sunday, 2026-05-18 a Monday.
        $this->assertTrue($this->calculator->isWeekend(new \DateTimeImmutable('2026-05-16')));
        $this->assertTrue($this->calculator->isWeekend(new \DateTimeImmutable('2026-05-17')));
        $this->assertFalse($this->calculator->isWeekend(new \DateTimeImmutable('2026-05-18')));
    }//end testWeekendDetection()

    /**
     * @return void
     */
    public function testFixedDutchHolidays(): void
    {
        $this->assertTrue($this->calculator->isDutchHoliday(new \DateTimeImmutable('2026-01-01')));
        $this->assertTrue($this->calculator->isDutchHoliday(new \DateTimeImmutable('2026-04-27')));
        $this->assertTrue($this->calculator->isDutchHoliday(new \DateTimeImmutable('2026-12-25')));
        $this->assertTrue($this->calculator->isDutchHoliday(new \DateTimeImmutable('2026-12-26')));
        $this->assertFalse($this->calculator->isDutchHoliday(new \DateTimeImmutable('2026-03-10')));
    }//end testFixedDutchHolidays()

    /**
     * Easter 2026 is on 2026-04-05; derived holidays must follow.
     *
     * @return void
     */
    public function testEasterDerivedHolidays(): void
    {
        // Good Friday 2026-04-03, Easter Monday 2026-04-06.
        $this->assertTrue($this->calculator->isDutchHoliday(new \DateTimeImmutable('2026-04-03')));
        $this->assertTrue($this->calculator->isDutchHoliday(new \DateTimeImmutable('2026-04-05')));
        $this->assertTrue($this->calculator->isDutchHoliday(new \DateTimeImmutable('2026-04-06')));
        // Ascension 2026-05-14, Pentecost 2026-05-24, Whit Monday 2026-05-25.
        $this->assertTrue($this->calculator->isDutchHoliday(new \DateTimeImmutable('2026-05-14')));
        $this->assertTrue($this->calculator->isDutchHoliday(new \DateTimeImmutable('2026-05-25')));
    }//end testEasterDerivedHolidays()

    /**
     * @return void
     */
    public function testIsWorkingDay(): void
    {
        // 2026-05-18 Monday is a working day; 2026-04-27 (Koningsdag) is not.
        $this->assertTrue($this->calculator->isWorkingDay(new \DateTimeImmutable('2026-05-18')));
        $this->assertFalse($this->calculator->isWorkingDay(new \DateTimeImmutable('2026-04-27')));
    }//end testIsWorkingDay()

    /**
     * Adding two working days from a Thursday should land on the next Monday
     * (skipping the weekend).
     *
     * @return void
     */
    public function testAddWorkingDaysSkipsWeekend(): void
    {
        // 2026-06-04 is a Thursday (no holidays in this week). +2 working days
        // = Friday(05) then Monday(08).
        $start  = new \DateTimeImmutable('2026-06-04T09:00:00+00:00');
        $result = $this->calculator->addWorkingDays($start, 2);
        $this->assertSame('2026-06-08', $result->format('Y-m-d'));
        // Time-of-day is preserved.
        $this->assertSame('09:00:00', $result->format('H:i:s'));
    }//end testAddWorkingDaysSkipsWeekend()

    /**
     * Adding working days must also skip holidays.
     *
     * @return void
     */
    public function testAddWorkingDaysSkipsHoliday(): void
    {
        // 2026-04-24 Friday. +1 working day would be Monday 27 (Koningsdag,
        // skipped) so it lands on Tuesday 28.
        $start  = new \DateTimeImmutable('2026-04-24T10:00:00+00:00');
        $result = $this->calculator->addWorkingDays($start, 1);
        $this->assertSame('2026-04-28', $result->format('Y-m-d'));
    }//end testAddWorkingDaysSkipsHoliday()

    /**
     * @return void
     */
    public function testCountWorkingDays(): void
    {
        // 2026-05-18 (Mon) .. 2026-05-24 (Sun): Mon-Fri = 5 working days.
        $start = new \DateTimeImmutable('2026-05-18');
        $end   = new \DateTimeImmutable('2026-05-24');
        $this->assertSame(5, $this->calculator->countWorkingDays($start, $end));
        // Reversed range yields 0.
        $this->assertSame(0, $this->calculator->countWorkingDays($end, $start));
    }//end testCountWorkingDays()

    /**
     * @return void
     */
    public function testEmailDeadlineUsesWorkingDays(): void
    {
        // Email = 2 working days. Thursday 2026-06-04 -> Monday 2026-06-08.
        $start    = new \DateTimeImmutable('2026-06-04T09:00:00+00:00');
        $deadline = $this->calculator->deadlineFor('email', $start);
        $this->assertSame('2026-06-08', $deadline->format('Y-m-d'));
    }//end testEmailDeadlineUsesWorkingDays()

    /**
     * @return void
     */
    public function testPhoneDeadlineUsesSeconds(): void
    {
        $start    = new \DateTimeImmutable('2026-05-21T09:00:00+00:00');
        $deadline = $this->calculator->deadlineFor('phone', $start);
        $this->assertSame(168, ($deadline->getTimestamp() - $start->getTimestamp()));
    }//end testPhoneDeadlineUsesSeconds()

    /**
     * @return void
     */
    public function testBreachDetection(): void
    {
        $start  = new \DateTimeImmutable('2026-05-21T09:00:00+00:00');
        $within = new \DateTimeImmutable('2026-05-21T09:01:00+00:00');
        $past   = new \DateTimeImmutable('2026-05-21T09:05:00+00:00');
        $this->assertFalse($this->calculator->isBreached('phone', $start, $within));
        $this->assertTrue($this->calculator->isBreached('phone', $start, $past));
    }//end testBreachDetection()

    /**
     * Exponential backoff doubles per attempt from a 15-minute base.
     *
     * @return void
     */
    public function testNextRetryBackoff(): void
    {
        $from = new \DateTimeImmutable('2026-05-21T14:30:00+00:00');
        // attempt 1 -> 30 min, attempt 2 -> 60 min.
        $this->assertSame('14:45:00', $this->calculator->nextRetryAt($from, 0)->format('H:i:s'));
        $this->assertSame('15:00:00', $this->calculator->nextRetryAt($from, 1)->format('H:i:s'));
        $this->assertSame('15:30:00', $this->calculator->nextRetryAt($from, 2)->format('H:i:s'));
    }//end testNextRetryBackoff()
}//end class
