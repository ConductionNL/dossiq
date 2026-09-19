<?php

/**
 * Unit tests for NoticeOfDefaultService.
 *
 * Drives the AWB 4:17 registration through valid + premature + duplicate
 * notices, verifies DwangsomBerekening creation, and asserts the
 * one-dwangsom guard semantics.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Dossiq\Service\NoticeOfDefaultService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Service\TermijnTimerService;
use OCA\Dossiq\Service\WorkingDayCalculator;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\NoticeOfDefaultService
 *
 * @uses \OCA\Dossiq\Service\TermijnService
 * @uses \OCA\Dossiq\Service\CaseDateNormaliser
 * @uses \OCA\Dossiq\Service\TermijnTimerService
 * @uses \OCA\Dossiq\Service\WorkingDayCalculator
 */
class NoticeOfDefaultServiceTest extends TestCase {
	use MakesCaseDateNormaliser;

	private FakeTermijnStore $objects;
	private TermijnService $termService;
	private NoticeOfDefaultService $service;
	/**
	 * The settings mock, reused when a second service is built on a calendar.
	 *
	 * @var SettingsService
	 */
	private SettingsService $settings;

	/**
	 * The logger, reused for the same reason.
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	protected function setUp(): void {
		$this->objects = new FakeTermijnStore();
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key): string {
				return match ($key) {
					'register' => 'dossiq',
					'termijn_definitie_schema' => 'deadlineDefinition',
					'termijn_instance_schema' => 'deadlineInstance',
					'termijn_gebeurtenis_schema' => 'termijnGebeurtenis',
					'ingebrekestelling_schema' => 'noticeOfDefault',
					'dwangsom_berekening_schema' => 'penaltyPaymentCalculation',
					default => '',
				};
			},
		);

		$logger = $this->createMock(LoggerInterface::class);
		$this->settings = $settings;
		$this->logger = $logger;
		$this->termService = new TermijnService($settings, $logger);
		$this->service = new NoticeOfDefaultService($settings, $this->termService, $logger);

		// Seed an AWB-default definition.
		$this->objects->seed('deadlineDefinition', [
			'id' => 'td-ov',
			'caseType' => 'omgevingsvergunning-regulier',
			'wettelijkeGrondslag' => 'Wabo 3.9 lid 1',
			'standardDurationDays' => 56,
			'countExtensions' => 1,
			'validFrom' => '2026-01-01',
		]);

		// Seed an overdue TermijnInstance.
		$this->objects->seed('deadlineInstance', [
			'id' => 'ti-1',
			'case' => 'Z/2026/300',
			'deadlineDefinition' => 'td-ov',
			'startDate' => '2026-01-01T10:00:00+00:00',
			'endDateCalculated' => '2026-02-25',
			'endDateCurrent' => '2026-02-25',
			'status' => 'exceeded',
			'notificatiesVerstuurd' => [],
		]);
	}

	/**
	 * @return void
	 */
	public function testValidNoticeCreatesBerekeningWithCorrectGrace(): void {
		$row = $this->service->registerNoticeOfDefault(
			'ti-1',
			new DateTimeImmutable('2026-03-15'),
			'email',
			'doc:1'
		);

		self::assertTrue($row['gevalideerd']);
		self::assertSame('valid', $row['validityStatus']);
		self::assertArrayHasKey('penaltyPaymentCalculation', $row);

		$b = $row['penaltyPaymentCalculation'];
		self::assertSame('2026-03-29', $b['startDate']);
		self::assertSame(144200, $b['plafondCalculated']);
		self::assertSame('awb-default', $b['regime']);
		self::assertSame('lopend', $b['status']);

		// Instance has the notice linked.
		$updated = $this->objects->store['deadlineInstance']['ti-1'];
		self::assertSame((string)$row['id'], $updated['relevantIngbrekes']);
	}

	/**
	 * @return void
	 */
	public function testPrematureNoticeIsRejected(): void {
		// Use a different instance still in lopend (not overschreden).
		$this->objects->seed('deadlineInstance', [
			'id' => 'ti-lopend',
			'case' => 'Z/2026/301',
			'deadlineDefinition' => 'td-ov',
			'startDate' => '2026-01-01T10:00:00+00:00',
			'endDateCalculated' => '2026-12-31',
			'endDateCurrent' => '2026-12-31',
			'status' => 'lopend',
			'notificatiesVerstuurd' => [],
		]);

		$row = $this->service->registerNoticeOfDefault(
			'ti-lopend',
			new DateTimeImmutable('2026-03-15'),
			'post'
		);

		self::assertFalse($row['gevalideerd']);
		self::assertSame('premaat', $row['validityStatus']);
		self::assertArrayNotHasKey('penaltyPaymentCalculation', $row);
	}

	/**
	 * @return void
	 */
	public function testSecondNoticeDoesNotSpawnSecondBerekening(): void {
		$first = $this->service->registerNoticeOfDefault(
			'ti-1',
			new DateTimeImmutable('2026-03-15'),
			'email'
		);
		self::assertArrayHasKey('penaltyPaymentCalculation', $first);

		$second = $this->service->registerNoticeOfDefault(
			'ti-1',
			new DateTimeImmutable('2026-03-20'),
			'post'
		);
		self::assertTrue($second['gevalideerd']);
		self::assertArrayNotHasKey('penaltyPaymentCalculation', $second);

		// Only one berekening in the store.
		self::assertCount(1, $this->objects->store['penaltyPaymentCalculation'] ?? []);
	}

	/**
	 * @return void
	 */
	public function testCustomRegimeIsResolvedFromDefinition(): void {
		$this->objects->seed('deadlineDefinition', [
			'id' => 'td-woo',
			'caseType' => 'woo-verzoek',
			'wettelijkeGrondslag' => 'Woo art 4.4',
			'standardDurationDays' => 28,
			'countExtensions' => 1,
			'deviatingPenaltyPaymentRegime' => ['dailyTariff' => 1500, 'plafond' => 50000, 'grace' => 14],
			'validFrom' => '2026-01-01',
		]);
		$this->objects->seed('deadlineInstance', [
			'id' => 'ti-woo',
			'case' => 'Z/2026/302',
			'deadlineDefinition' => 'td-woo',
			'startDate' => '2026-01-01T10:00:00+00:00',
			'endDateCalculated' => '2026-01-29',
			'endDateCurrent' => '2026-01-29',
			'status' => 'exceeded',
			'notificatiesVerstuurd' => [],
		]);

		$row = $this->service->registerNoticeOfDefault(
			'ti-woo',
			new DateTimeImmutable('2026-02-15'),
			'post'
		);
		$b = $row['penaltyPaymentCalculation'];
		self::assertSame('afwijkend', $b['regime']);
		self::assertSame(50000, $b['plafondCalculated']);
	}

	/**
	 * The same service, with the engine calendar reachable.
	 *
	 * @param array<int, string> $closed Non-working dates as `Y-m-d`.
	 *
	 * @return NoticeOfDefaultService The service under test.
	 */
	private function serviceOnCalendar(array $closed): NoticeOfDefaultService {
		$calendars = new WorkingCalendarServiceFake(new WorkingCalendarFake('nl-national', $closed));
		$calculator = new SlaCalculatorFake();

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getOpenRegisterClass')->willReturnCallback(
			static function (string $class) use ($calendars, $calculator): ?object {
				return match ($class) {
					TermijnTimerService::CALENDAR_SERVICE_CLASS => $calendars,
					TermijnTimerService::SLA_CALCULATOR_CLASS => $calculator,
					default => null,
				};
			}
		);
		$timers = new TermijnTimerService(
			settingsService: $settings,
			logger: $this->logger,
			dates: $this->caseDates(),
			fallbackCalendar: new WorkingDayCalculator(),
		);

		return new NoticeOfDefaultService($this->settings, $this->termService, $this->logger, $timers);
	}

	/**
	 * Awb 4:17: fourteen days from a receipt on 7 June 2026 is a Sunday, and
	 * the dwangsom cannot start running on one. The window opens the Monday.
	 *
	 * @return void
	 */
	public function testTheGracePeriodEndingOnASundayMovesToTheMonday(): void {
		$row = $this->serviceOnCalendar([])->registerNoticeOfDefault(
			'ti-1',
			new DateTimeImmutable('2026-06-07'),
			'email',
			'doc:1'
		);

		self::assertSame('2026-06-22', $row['penaltyPaymentCalculation']['startDate']);
	}

	/**
	 * The fixture pair: the same receipt with the engine calendar out of
	 * reach keeps the raw Sunday, so the assertion above is about the roll
	 * and not about the fourteen days.
	 *
	 * @return void
	 */
	public function testTheSameGracePeriodWithoutTheCalendarKeepsTheSunday(): void {
		$row = $this->service->registerNoticeOfDefault(
			'ti-1',
			new DateTimeImmutable('2026-06-07'),
			'email',
			'doc:1'
		);

		self::assertSame('2026-06-21', $row['penaltyPaymentCalculation']['startDate']);
	}

	/**
	 * The grace counts on the calendar the ORGANISATION administers: a day it
	 * closes moves the window even though no national list names it.
	 *
	 * @return void
	 */
	public function testTheGracePeriodCountsOnTheAdministeredCalendar(): void {
		$row = $this->serviceOnCalendar(['2026-03-30'])->registerNoticeOfDefault(
			'ti-1',
			new DateTimeImmutable('2026-03-15'),
			'post'
		);

		// 2026-03-29 is a Sunday and 2026-03-30 is a local closure.
		self::assertSame('2026-03-31', $row['penaltyPaymentCalculation']['startDate']);
	}

	/**
	 * The regime's validity rules are untouched by the roll: a premature
	 * notice is still premature and still spawns nothing.
	 *
	 * @return void
	 */
	public function testTheRollDoesNotChangeTheValidityRules(): void {
		$this->objects->seed('deadlineInstance', [
			'id' => 'ti-lopend-2',
			'case' => 'Z/2026/303',
			'deadlineDefinition' => 'td-ov',
			'startDate' => '2026-01-01T10:00:00+00:00',
			'endDateCalculated' => '2026-12-31',
			'endDateCurrent' => '2026-12-31',
			'status' => 'lopend',
			'notificatiesVerstuurd' => [],
		]);

		$row = $this->serviceOnCalendar([])->registerNoticeOfDefault(
			'ti-lopend-2',
			new DateTimeImmutable('2026-06-07'),
			'post'
		);

		self::assertFalse($row['gevalideerd']);
		self::assertSame('premaat', $row['validityStatus']);
		self::assertArrayNotHasKey('penaltyPaymentCalculation', $row);
	}
}
