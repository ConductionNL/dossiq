<?php

/**
 * Unit tests for OpenRegisterArchivalAdapter: the destruction date is the
 * creation (or announcement) date plus the bewaartermijn, an absent or
 * unparseable bewaartermijn falls back to fifteen years, an unparseable
 * creation date yields no destruction date, and the archive id is stable.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Beschikking;

use DateInterval;
use DateTimeImmutable;
use OCA\Dossiq\Service\Beschikking\OpenRegisterArchivalAdapter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\Beschikking\OpenRegisterArchivalAdapter
 */
class OpenRegisterArchivalAdapterTest extends TestCase {

	private ContainerInterface&MockObject $container;

	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->container = $this->createMock(ContainerInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	private function adapter(): OpenRegisterArchivalAdapter {
		return new OpenRegisterArchivalAdapter($this->container, $this->logger);
	}//end adapter()

	public function testTheDestructionDateIsTheCreationDatePlusTheBewaartermijn(): void {
		$result = $this->adapter()->ingest('b-1', 'f-1', ['bewaartermijn' => 'P10Y', 'creatieDatum' => '2020-03-15']);

		$this->assertSame('2030-03-15', $result['destructionDate']);
	}//end testTheDestructionDateIsTheCreationDatePlusTheBewaartermijn()

	public function testTheArchiveIdIsDerivedFromDecisionAndFile(): void {
		$result = $this->adapter()->ingest('b-1', 'f-1', ['creatieDatum' => '2020-01-01']);

		$this->assertSame('openregister-' . substr(hash('sha256', 'b-1f-1'), 0, 12), $result['archiveId']);
		$this->assertNotSame($result['archiveId'], $this->adapter()->ingest('b-1', 'f-2', [])['archiveId']);
	}//end testTheArchiveIdIsDerivedFromDecisionAndFile()

	public function testAnAbsentBewaartermijnFallsBackToFifteenYearsFromTheAnnouncementDate(): void {
		$result = $this->adapter()->ingest('b-2', 'f-2', ['announcementDate' => '2021-06-30']);

		$this->assertSame('2036-06-30', $result['destructionDate']);
	}//end testAnAbsentBewaartermijnFallsBackToFifteenYearsFromTheAnnouncementDate()

	public function testTheCreationDateWinsOverTheAnnouncementDate(): void {
		$result = $this->adapter()->ingest('b-3', 'f-3', [
			'bewaartermijn' => 'P1Y',
			'creatieDatum' => '2022-02-01',
			'announcementDate' => '2023-02-01',
		]);

		$this->assertSame('2023-02-01', $result['destructionDate']);
	}//end testTheCreationDateWinsOverTheAnnouncementDate()

	public function testAnUnparseableBewaartermijnFallsBackToFifteenYears(): void {
		$this->assertSame(
			'2035-05-05',
			$this->adapter()->ingest('b-4', 'f-4', ['bewaartermijn' => 'forever', 'creatieDatum' => '2020-05-05'])['destructionDate']
		);
		$this->assertSame(
			'2035-05-05',
			$this->adapter()->ingest('b-4', 'f-4', ['bewaartermijn' => '', 'creatieDatum' => '2020-05-05'])['destructionDate']
		);
	}//end testAnUnparseableBewaartermijnFallsBackToFifteenYears()

	public function testWithoutAnyDateTheTermRunsFromToday(): void {
		$before = (new DateTimeImmutable())->add(new DateInterval('P7Y'))->format('Y-m-d');
		$result = $this->adapter()->ingest('b-5', 'f-5', ['bewaartermijn' => 'P7Y']);
		$after = (new DateTimeImmutable())->add(new DateInterval('P7Y'))->format('Y-m-d');

		$this->assertContains($result['destructionDate'], [$before, $after]);
	}//end testWithoutAnyDateTheTermRunsFromToday()

	public function testAnUnparseableCreationDateGivesNoDestructionDateAndWarns(): void {
		$this->logger->expects($this->once())->method('warning')
			->with('OpenRegisterArchivalAdapter: could not compute vernietigingsdatum', $this->arrayHasKey('error'));

		$result = $this->adapter()->ingest('b-6', 'f-6', ['bewaartermijn' => 'P1Y', 'creatieDatum' => 'not a date']);

		$this->assertSame('', $result['destructionDate']);
	}//end testAnUnparseableCreationDateGivesNoDestructionDateAndWarns()

	public function testWithoutOpenRegisterTheContainerIsNotConsulted(): void {
		if (class_exists('OCA\OpenRegister\Service\TmloService') === true) {
			$this->markTestSkipped('OpenRegister TmloService is loaded in this environment.');
		}

		$this->container->expects($this->never())->method('get');

		$this->adapter()->ingest('b-7', 'f-7', ['bewaartermijn' => 'P2Y', 'creatieDatum' => '2020-01-01']);
	}//end testWithoutOpenRegisterTheContainerIsNotConsulted()
}//end class
