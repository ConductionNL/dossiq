<?php

/**
 * Unit tests for DeadlinePauseService + DeadlineExtensionService.
 *
 * Exercises AWB 4:5/4:15 pause + resume math and AWB 4:14 verlenging
 * (ceiling + supervisor override) against an in-memory store.
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
use OCA\Dossiq\Service\DeadlineExtensionService;
use OCA\Dossiq\Service\DeadlinePauseService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Service\TermijnTimerService;
use OCA\Dossiq\Service\WorkingDayCalculator;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\DeadlinePauseService
 * @covers \OCA\Dossiq\Service\DeadlineExtensionService
 *
 * @uses \OCA\Dossiq\Service\Substitution\SubstitutedWorkResolver
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses \OCA\Dossiq\Service\TermijnService
 * @uses \OCA\Dossiq\Service\TermijnTimerService
 */
class DeadlinePauseExtensionServiceTest extends TestCase {
	use MakesCaseDateNormaliser;

	private FakeTermijnStore $objects;
	private FlowTimerEngineFake $engine;
	private TermijnService $termService;
	private DeadlinePauseService $pauseService;
	private DeadlineExtensionService $extService;
	/**
	 * The engine's calendar resolver, seeded from `$closedDates`.
	 *
	 * @var WorkingCalendarServiceFake
	 */
	private WorkingCalendarServiceFake $calendars;

	/**
	 * Days the administered calendar closes, beyond its weekend.
	 *
	 * @var array<int, string>
	 */
	private array $closedDates = [];

	protected function setUp(): void {
		$this->objects = new FakeTermijnStore();
		$this->engine = new FlowTimerEngineFake();
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$this->calendars = new WorkingCalendarServiceFake(new WorkingCalendarFake('nl-national', $this->closedDates));
		$slaCalculator = new SlaCalculatorFake();
		$settings->method('getOpenRegisterClass')->willReturnCallback(
			function (string $class) use ($slaCalculator): ?object {
				return match ($class) {
					TermijnTimerService::ENGINE_CLASS => $this->engine,
					TermijnTimerService::CALENDAR_SERVICE_CLASS => $this->calendars,
					TermijnTimerService::SLA_CALCULATOR_CLASS => $slaCalculator,
					default => null,
				};
			}
		);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key): string {
				return match ($key) {
					'register' => 'dossiq',
					'termijn_definitie_schema' => 'deadlineDefinition',
					'termijn_instance_schema' => 'deadlineInstance',
					'termijn_gebeurtenis_schema' => 'termijnGebeurtenis',
					default => '',
				};
			},
		);

		$logger = $this->createMock(LoggerInterface::class);
		$timerService = new TermijnTimerService(
			settingsService: $settings,
			logger: $logger,
			dates: $this->caseDates(),
			fallbackCalendar: new WorkingDayCalculator(),
		);
		$this->termService = new TermijnService($settings, $logger, $timerService);
		$this->pauseService = new DeadlinePauseService($this->termService, $timerService);
		$this->extService = new DeadlineExtensionService(termService: $this->termService, dates: $this->caseDates(), timerService: $timerService);

		// Seed an Omgevingsvergunning definition (max 1 extension).
		$this->objects->seed('deadlineDefinition', [
			'id' => 'td-ov',
			'caseType' => 'omgevingsvergunning-regulier',
			'wettelijkeGrondslag' => 'Wabo 3.9 lid 1',
			'standardDurationDays' => 56,
			'countExtensions' => 1,
			'validFrom' => '2026-01-01',
		]);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function newInstance(): array {
		// Resolve the definition so the cache gets populated.
		$this->termService->getTermijnDefinitie('omgevingsvergunning-regulier');
		return $this->termService->createTermijnInstance(
			'Z/2026/300',
			'omgevingsvergunning-regulier',
			new DateTimeImmutable('2026-06-01T10:00:00+00:00')
		);
	}

	/**
	 * @return void
	 */
	public function testPauseExtendsDeadlineAndSetsStatus(): void {
		$instance = $this->newInstance();
		$id = (string)$instance['id'];

		$paused = $this->pauseService->registerPauze($id, 14, 'Aanvrager moet aanvulling indienen', 'doc:1');
		self::assertSame('paused', $paused['status']);
		self::assertSame('2026-08-10', $paused['endDateCurrent']);
	}

	/**
	 * @return void
	 */
	public function testPauseRejectsNonPositiveDuration(): void {
		$instance = $this->newInstance();
		$this->expectException(RuntimeException::class);
		$this->pauseService->registerPauze((string)$instance['id'], 0, 'foo');
	}

	/**
	 * @return void
	 */
	public function testResumeConsumesElapsedDays(): void {
		$instance = $this->newInstance();
		$id = (string)$instance['id'];

		// Pause for 14 days; deadline now 2026-08-10.
		$this->pauseService->registerPauze($id, 14, 'Aanvulling vereist');

		// Aanvulling arrives 4 days after pause-start (so 10 days unused).
		// After resume, deadline should pull back by 10 days → 2026-07-31.
		$currentInstance = $this->termService->getTermijnInstance($id);
		$pauseStart = new DateTimeImmutable($currentInstance['pauzeStartDatum']);
		$aanvulling = $pauseStart->modify('+4 days');

		$resumed = $this->pauseService->resumeAfterPauze($id, $aanvulling);
		self::assertSame('lopend', $resumed['status']);
		self::assertSame('2026-07-31', $resumed['endDateCurrent']);
	}

	/**
	 * @return void
	 */
	public function testFirstExtensionSucceeds(): void {
		$instance = $this->newInstance();
		$id = (string)$instance['id'];

		$extended = $this->extService->requestExtension(
			$id,
			'Complex zaak; meer onderzoek vereist',
			'2026-08-31',
			'doc:verlengingsbrief-1'
		);
		self::assertSame('verlengd', $extended['status']);
		self::assertSame(1, $extended['countExtensions']);
		self::assertSame('2026-08-31', $extended['endDateCurrent']);
	}

	/**
	 * @return void
	 */
	public function testSecondExtensionBlockedByCeiling(): void {
		$instance = $this->newInstance();
		$id = (string)$instance['id'];

		$this->extService->requestExtension($id, 'eerste', '2026-08-31');
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('AWB 4:14 lid 3');
		$this->extService->requestExtension($id, 'tweede', '2026-09-30');
	}

	/**
	 * @return void
	 */
	public function testSupervisorOverrideAllowsSecondExtension(): void {
		$instance = $this->newInstance();
		$id = (string)$instance['id'];

		$this->extService->requestExtension($id, 'eerste', '2026-08-31');
		$second = $this->extService->requestSupervisorExtension(
			$id,
			'tweede; supervisor goedgekeurd',
			'2026-09-30',
			'doc:second'
		);
		self::assertSame(2, $second['countExtensions']);
	}

	/**
	 * @return void
	 */
	public function testExtensionRejectsEmptyMotivering(): void {
		$instance = $this->newInstance();
		$this->expectException(RuntimeException::class);
		$this->extService->requestExtension((string)$instance['id'], '   ', '2026-08-31');
	}

	/**
	 * @return void
	 */
	public function testExtensionRejectsEarlierDeadline(): void {
		$instance = $this->newInstance();
		$this->expectException(RuntimeException::class);
		$this->extService->requestExtension((string)$instance['id'], 'foo', '2026-07-01');
	}

	/**
	 * FIXTURE PAIR (opschorting, design D-2): the case-data arithmetic
	 * (+14 at pause, -10 unused at resume) and the engine's consumed-budget
	 * model land on the same date. Dossiq computes end = base + pause -
	 * unused; the engine computes fireAt = resume moment + (budget -
	 * consumed-before-pause). For a 56-day term started 1 June, paused at
	 * pause-start and resumed 4 days in, BOTH are base + 4 = 2026-07-31.
	 *
	 * @return void
	 */
	public function testOpschortingArithmeticPairsWithEngineSuspendResume(): void {
		// A term started today, so pause-start (always the real today in
		// registerPauze) coincides with the anchor and the two models can
		// be compared without a time machine.
		$start = new DateTimeImmutable('today');
		$this->termService->getTermijnDefinitie('omgevingsvergunning-regulier');
		$instance = $this->termService->createTermijnInstance(
			'Z/2026/301',
			'omgevingsvergunning-regulier',
			$start
		);
		$id = (string)$instance['id'];

		$this->pauseService->registerPauze($id, 14, 'Aanvulling vereist');
		$current = $this->termService->getTermijnInstance($id);
		$pauseStart = new DateTimeImmutable((string)$current['pauzeStartDatum']);
		$resumeAt = $pauseStart->modify('+4 days');
		$resumed = $this->pauseService->resumeAfterPauze($id, $resumeAt);

		// Dossiq case-data side: base end +14 at pause, -10 unused at
		// resume, net +4 = start + 60, landed on the next day the
		// administered calendar works (Awb 1:1 Algemene termijnenwet), so
		// the assertion holds on the runs where start + 60 is a weekend.
		$expected = $this->firstWorkingDayFrom($start->modify('+60 days'))->format('Y-m-d');
		self::assertSame($expected, $resumed['endDateCurrent']);

		// Engine side: suspend was evidenced with the Awb basis, and the
		// consumed-budget model reprojects to the SAME date: fireAt after
		// resume = resumeAt + (budget - consumed at suspension).
		self::assertSame('Awb 4:5', $this->engine->calls['suspend'][0]['basis']);
		self::assertCount(1, $this->engine->calls['resume']);
		$armed = $this->engine->calls['arm'][0]['config'];
		$consumed = (int)$start->diff($pauseStart)->days;
		$remaining = ((int)$armed['sla']['value'] - $consumed);
		$engineFireAt = $this->firstWorkingDayFrom($resumeAt->modify('+' . $remaining . ' days'));
		self::assertSame($expected, $engineFireAt->format('Y-m-d'));
	}

	/**
	 * The day the fixture's administered calendar first works on or after
	 * the given date, so an expectation anchored on today survives a
	 * weekend without a time machine.
	 *
	 * @param DateTimeImmutable $date The raw calendar date.
	 *
	 * @return DateTimeImmutable
	 */
	private function firstWorkingDayFrom(DateTimeImmutable $date): DateTimeImmutable {
		$calendar = $this->calendars->resolve('nl-national', null);
		while ($calendar->isWorkingDay($date) === false) {
			$date = $date->modify('+1 day');
		}
		return $date;
	}

	/**
	 * The pause arms the hersteltermijn helper (advisory, pauze-verlopen
	 * rule) and stores its uuid; the resume clears it.
	 *
	 * @return void
	 */
	public function testPauseArmsTheHersteltermijnHelper(): void {
		$instance = $this->newInstance();
		$id = (string)$instance['id'];

		$paused = $this->pauseService->registerPauze($id, 14, 'Aanvulling vereist');
		self::assertNotSame('', (string)($paused['pauseTimerId'] ?? ''));

		$helper = $this->engine->calls['arm'][1]['config'];
		self::assertSame('none', $helper['legalEffect']);
		self::assertSame('hersteltermijn', $helper['metadata']['kind']);
		self::assertSame('pauze-verlopen', $helper['escalationRules'][0]['message']);

		$pauseStart = new DateTimeImmutable((string)$paused['pauzeStartDatum']);
		$resumed = $this->pauseService->resumeAfterPauze($id, $pauseStart->modify('+4 days'));
		self::assertNull($resumed['pauseTimerId']);
	}

	/**
	 * FIXTURE PAIR (verdaging, AWB 4:14): the day impact dossiq records
	 * equals the amount mirrored to the engine, on the authorized path per
	 * mode: bounded extend for lid 1, the override for lid 3.
	 *
	 * @return void
	 */
	public function testVerdagingMirrorsTheDayImpactToTheEngine(): void {
		$instance = $this->newInstance();
		$id = (string)$instance['id'];

		// 2026-07-27 -> 2026-08-31 is 35 days.
		$this->extService->requestExtension($id, 'Complex zaak', '2026-08-31');
		self::assertSame(35, $this->engine->calls['extend'][0]['amount']);
		self::assertSame('calendarDays', $this->engine->calls['extend'][0]['unit']);

		// 2026-08-31 -> 2026-09-30 is 30 days, supervisor path.
		$this->extService->requestSupervisorExtension($id, 'supervisor akkoord', '2026-09-30');
		self::assertSame(30, $this->engine->calls['extendWithOverride'][0]['amount']);
		self::assertSame('supervisor', $this->engine->calls['extendWithOverride'][0]['actor']);
	}

	/**
	 * Awb 4:5: the credited suspension moves `endDateCurrent`, which is the
	 * date a handler is judged on. Fourteen days from 12 December 2026 is
	 * Tweede Kerstdag, and the term runs to the Monday.
	 *
	 * @return void
	 */
	public function testAPauseCreditLandingOnTweedeKerstdagRollsToTheNextOrdinaryDay(): void {
		$this->closedDates = ['2026-12-25', '2026-12-26'];
		$this->setUp();

		$id = (string)$this->newInstance()['id'];
		$this->termService->updateTermijnInstance($id, ['endDateCurrent' => '2026-12-12']);

		$updated = $this->pauseService->registerPauze($id, 14, 'aanvulling gevraagd');

		self::assertSame('2026-12-28', $updated['endDateCurrent']);
		self::assertSame('paused', $updated['status']);
	}

	/**
	 * The fixture pair: the same pause against a calendar the organisation
	 * works through keeps the raw date, so the assertion above is about the
	 * calendar and not about the fourteen days.
	 *
	 * @return void
	 */
	public function testTheSamePauseOnACalendarThatWorksThatDayKeepsTheRawDate(): void {
		$this->closedDates = [];
		$this->setUp();

		$id = (string)$this->newInstance()['id'];
		$this->termService->updateTermijnInstance($id, ['endDateCurrent' => '2026-12-14']);

		$updated = $this->pauseService->registerPauze($id, 14, 'aanvulling gevraagd');

		// 2026-12-28 is a Monday this calendar works, so nothing moves.
		self::assertSame('2026-12-28', $updated['endDateCurrent']);
	}

	/**
	 * Awb 4:15: the unused remainder is taken off and the result lands on a
	 * working day too. Ten unused days off 8 April 2027 is Tweede Paasdag.
	 *
	 * @return void
	 */
	public function testAnUnusedRemainderAlsoLandsOnAWorkingDay(): void {
		$this->closedDates = ['2027-03-29'];
		$this->setUp();

		$id = (string)$this->newInstance()['id'];
		$this->termService->updateTermijnInstance(
			$id,
			[
				'endDateCurrent' => '2027-04-08',
				'status' => 'paused',
				'pauzeStartDatum' => '2027-03-01',
				'pauzeDuurDagen' => 14,
			]
		);

		$updated = $this->pauseService->resumeAfterPauze($id, new DateTimeImmutable('2027-03-05'));

		self::assertSame('2027-03-30', $updated['endDateCurrent']);
		self::assertSame('lopend', $updated['status']);
	}

	/**
	 * The arithmetic stays case data (REQ-TOT-002): the pause still credits
	 * exactly the paused working time, and only the day it lands on moves.
	 *
	 * @return void
	 */
	public function testThePauseStillCreditsExactlyTheRequestedDays(): void {
		$this->closedDates = [];
		$this->setUp();

		$id = (string)$this->newInstance()['id'];
		$this->termService->updateTermijnInstance($id, ['endDateCurrent' => '2027-03-15']);

		$updated = $this->pauseService->registerPauze($id, 14, 'aanvulling gevraagd');

		// 2027-03-15 plus fourteen calendar days, landing on an ordinary Monday.
		self::assertSame('2027-03-29', $updated['endDateCurrent']);
	}
}
