<?php

/**
 * TussenrapportageService Unit Tests.
 *
 * Exercises the interim-report cadence and assessment-deadline math
 * (REQ-SUB-004): reporting periods per frequentie and the termijn binding.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Subsidie
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

namespace OCA\Dossiq\Tests\Unit\Service\Subsidie;

use DateTimeImmutable;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Subsidie\TussenrapportageService;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\Subsidie\TussenrapportageService
 *
 * @spec openspec/changes/subsidieverlening-keten/tasks.md#TASK-SUB-13
 */
class TussenrapportageServiceTest extends TestCase {

	private TussenrapportageService $service;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$settings = $this->createMock(SettingsService::class);
		$session = $this->createMock(IUserSession::class);
		$logger = $this->createMock(LoggerInterface::class);
		$this->service = new TussenrapportageService($settings, $session, $logger);
	}//end setUp()

	/**
	 * REQ-SUB-004: assessment deadline = period end + regeling term.
	 *
	 * @return void
	 */
	public function testBeoordelingstermijn(): void {
		$end = new DateTimeImmutable('2026-12-31');
		$deadline = $this->service->computeBeoordelingstermijn($end, 22);
		$this->assertSame('2027-06-03', $deadline->format('Y-m-d'));
	}//end testBeoordelingstermijn()

	/**
	 * @return void
	 */
	public function testPeriodsJaarlijks(): void {
		$periods = $this->service->periodsForFrequentie('annually', 2026);
		$this->assertCount(1, $periods);
		$this->assertSame('2026-01-01', $periods[0]['start']);
		$this->assertSame('2026-12-31', $periods[0]['eind']);
	}//end testPeriodsJaarlijks()

	/**
	 * @return void
	 */
	public function testPeriodsHalfjaarlijks(): void {
		$periods = $this->service->periodsForFrequentie('halfjaarlijks', 2026);
		$this->assertCount(2, $periods);
		$this->assertSame('2026-06-30', $periods[0]['eind']);
		$this->assertSame('2026-07-01', $periods[1]['start']);
	}//end testPeriodsHalfjaarlijks()

	/**
	 * @return void
	 */
	public function testPeriodsNoneForMilestoneOrGeen(): void {
		$this->assertSame([], $this->service->periodsForFrequentie('on_milestone', 2026));
		$this->assertSame([], $this->service->periodsForFrequentie('none', 2026));
	}//end testPeriodsNoneForMilestoneOrGeen()
}//end class
