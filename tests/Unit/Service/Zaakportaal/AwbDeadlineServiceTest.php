<?php

/**
 * AwbDeadlineService Unit Tests
 *
 * Exercises the statutory deadline arithmetic for the citizen portal: the
 * six-week bezwaar termijn, working-day extension over weekends and Dutch
 * public holidays (including the Easter-derived movable feasts), and the
 * days-remaining and timeliness helpers.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service\Zaakportaal
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
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service\Zaakportaal;

use DateTimeImmutable;
use OCA\Procest\Service\Zaakportaal\AwbDeadlineService;
use OCP\AppFramework\OCS\OCSBadRequestException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AwbDeadlineService.
 *
 * @covers \OCA\Procest\Service\Zaakportaal\AwbDeadlineService
 */
class AwbDeadlineServiceTest extends TestCase
{

    private AwbDeadlineService $service;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->service = new AwbDeadlineService();
    }//end setUp()

    /**
     * Six weeks after a Thursday decision lands on a working day (Thursday).
     *
     * @return void
     */
    public function testBezwaarDeadlineSixWeeksLanding(): void
    {
        // 2026-01-08 is a Thursday; +6 weeks = 2026-02-19 (Thursday, working day).
        $this->assertSame('2026-02-19', $this->service->bezwaarDeadline('2026-01-08'));
    }//end testBezwaarDeadlineSixWeeksLanding()

    /**
     * A deadline that would fall on a Saturday is pushed to the next Monday.
     *
     * @return void
     */
    public function testBezwaarDeadlineExtendsOverWeekend(): void
    {
        // 2026-01-10 is a Saturday; +6 weeks = 2026-02-21 (Saturday) -> Monday 2026-02-23.
        $this->assertSame('2026-02-23', $this->service->bezwaarDeadline('2026-01-10'));
    }//end testBezwaarDeadlineExtendsOverWeekend()

    /**
     * A deadline on a recognised holiday is pushed to the next working day.
     *
     * @return void
     */
    public function testNextWorkingDaySkipsHoliday(): void
    {
        // Nieuwjaarsdag 2027-01-01 (Friday) -> next working day 2027-01-04 (Monday).
        $result = $this->service->nextWorkingDay(new DateTimeImmutable('2027-01-01'));
        $this->assertSame('2027-01-04', $result->format('Y-m-d'));
    }//end testNextWorkingDaySkipsHoliday()

    /**
     * Easter Monday is recognised as a Dutch holiday.
     *
     * @return void
     */
    public function testDutchHolidaysIncludeEasterMonday(): void
    {
        // Easter Sunday 2026 = 2026-04-05, so Tweede paasdag = 2026-04-06.
        $this->assertContains('2026-04-06', $this->service->dutchHolidays(2026));
    }//end testDutchHolidaysIncludeEasterMonday()

    /**
     * Weekends are not working days; mid-week non-holidays are.
     *
     * @return void
     */
    public function testIsWorkingDay(): void
    {
        $this->assertFalse($this->service->isWorkingDay(new DateTimeImmutable('2026-01-10')));
        // Saturday.
        $this->assertFalse($this->service->isWorkingDay(new DateTimeImmutable('2026-12-25')));
        // Kerst.
        $this->assertTrue($this->service->isWorkingDay(new DateTimeImmutable('2026-01-08')));
        // Thursday.
    }//end testIsWorkingDay()

    /**
     * A submission on the deadline day is timely; one day later is not.
     *
     * @return void
     */
    public function testTimeliness(): void
    {
        $deadline = $this->service->bezwaarDeadline('2026-01-08');
        // 2026-02-19.
        $this->assertTrue($this->service->isWithinBezwaarTermijn('2026-01-08', $deadline));
        $this->assertFalse($this->service->isWithinBezwaarTermijn('2026-01-08', '2026-02-20'));
    }//end testTimeliness()

    /**
     * Days remaining is zero once the deadline has passed, and clamps at >= 0.
     *
     * @return void
     */
    public function testDaysRemaining(): void
    {
        $this->assertSame(5, $this->service->daysRemaining('2026-02-20', '2026-02-15'));
        $this->assertSame(0, $this->service->daysRemaining('2026-02-10', '2026-02-15'));
    }//end testDaysRemaining()

    /**
     * Leap-year arithmetic: a decision near a leap day stays consistent.
     *
     * @return void
     */
    public function testLeapYearDeadline(): void
    {
        // 2024 is a leap year; 2024-02-29 + 6 weeks = 2024-04-11 (Thursday).
        $this->assertSame('2024-04-11', $this->service->bezwaarDeadline('2024-02-29'));
    }//end testLeapYearDeadline()

    /**
     * An unparseable date raises a bad-request exception.
     *
     * @return void
     */
    public function testInvalidDateThrows(): void
    {
        $this->expectException(OCSBadRequestException::class);
        $this->service->bezwaarDeadline('not-a-date');
    }//end testInvalidDateThrows()
}//end class
