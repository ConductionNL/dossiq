<?php

/**
 * HoorzittingCalendarSync Unit Tests.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
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

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\HoorzittingCalendarSync;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for HoorzittingCalendarSync.
 *
 * @covers \OCA\Procest\Service\HoorzittingCalendarSync
 */
class HoorzittingCalendarSyncTest extends TestCase {

	/**
	 * @var ContainerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private ContainerInterface $container;

	/**
	 * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * @var HoorzittingCalendarSync
	 */
	private HoorzittingCalendarSync $service;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->container = $this->createMock(ContainerInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->service = new HoorzittingCalendarSync($this->container, $this->logger);
	}//end setUp()

	/**
	 * A waived hearing is returned unchanged — nothing to schedule.
	 *
	 * @return void
	 */
	public function testWaivedHearingIsNotSynced(): void {
		$session = ['hearingWaived' => true, 'waiverReason' => 'afstand gedaan'];

		$result = $this->service->sync($session);

		$this->assertSame($session, $result);
		$this->assertArrayNotHasKey('auditTrail', $result);
	}//end testWaivedHearingIsNotSynced()

	/**
	 * A missing scheduledDate records a skip audit entry but never fails.
	 *
	 * @return void
	 */
	public function testMissingScheduledDateRecordsSkip(): void {
		$result = $this->service->sync(['hearingWaived' => false]);

		$this->assertArrayHasKey('auditTrail', $result);
		$this->assertSame('calendar-sync-skipped', $result['auditTrail'][0]['event']);
	}//end testMissingScheduledDateRecordsSkip()

	/**
	 * When the calendar manager is unavailable the hearing record is kept
	 * and a skip audit entry is added (best-effort transport).
	 *
	 * @return void
	 */
	public function testCalendarManagerUnavailableDegradesGracefully(): void {
		$this->container->method('get')->willThrowException(new \RuntimeException('no calendar'));

		$session = [
			'hearingWaived' => false,
			'scheduledDate' => '2026-06-15T10:00:00',
			'location' => 'Stadhuis',
		];

		$result = $this->service->sync($session);

		$this->assertArrayHasKey('auditTrail', $result);
		$this->assertSame('calendar-sync-skipped', $result['auditTrail'][0]['event']);
		$this->assertArrayNotHasKey('calendarIcs', $result);
		// Original fields preserved.
		$this->assertSame('Stadhuis', $result['location']);
	}//end testCalendarManagerUnavailableDegradesGracefully()

	/**
	 * When a calendar manager is present an ICS body is produced and a
	 * calendar-synced audit entry recorded. Invalid invitee emails are
	 * filtered out.
	 *
	 * @return void
	 */
	public function testSuccessfulSyncProducesIcs(): void {
		$builder = $this->createMock(\OCP\Calendar\ICalendarEventBuilder::class);
		$builder->method('setStartDate')->willReturnSelf();
		$builder->method('setEndDate')->willReturnSelf();
		$builder->method('setSummary')->willReturnSelf();
		$builder->method('setDescription')->willReturnSelf();
		$builder->method('setLocation')->willReturnSelf();
		$builder->method('addAttendee')->willReturnSelf();
		$builder->method('toIcs')->willReturn('BEGIN:VCALENDAR');

		$manager = $this->createMock(\OCP\Calendar\IManager::class);
		$manager->method('createEventBuilder')->willReturn($builder);

		$this->container->method('get')->willReturn($manager);

		$session = [
			'hearingWaived' => false,
			'scheduledDate' => '2026-06-15T10:00:00',
			'location' => 'Stadhuis',
			'invitees' => '[{"name":"Jan","email":"jan@example.nl"},{"name":"Bad","email":"not-an-email"}]',
		];

		$result = $this->service->sync($session);

		$this->assertSame('BEGIN:VCALENDAR', $result['calendarIcs']);
		$this->assertSame('calendar-synced', $result['auditTrail'][0]['event']);
	}//end testSuccessfulSyncProducesIcs()
}//end class
