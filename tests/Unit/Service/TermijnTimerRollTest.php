<?php

/**
 * Unit tests for the Algemene termijnenwet roll on TermijnTimerService.
 *
 * The roll is consumed from the engine, never recomputed here: the test
 * asserts that dossiq resolves the administered calendar and then asks the
 * engine's own `businessDays` walk for the day a term lands on. The fallback
 * path is asserted too, because an absent OpenRegister is the one case where
 * dossiq's own holiday list is allowed to answer.
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
 *
 * @spec openspec/changes/every-term-on-the-engine-calendar/specs/termijnbewaking-schemas/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\TermijnTimerService;
use OCA\Dossiq\Service\WorkingDayCalculator;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\TermijnTimerService
 *
 * @uses \OCA\Dossiq\Service\WorkingDayCalculator
 */
class TermijnTimerRollTest extends TestCase {
	use MakesCaseDateNormaliser;

	/**
	 * The engine's calendar resolver.
	 *
	 * @var WorkingCalendarServiceFake
	 */
	private WorkingCalendarServiceFake $calendars;

	/**
	 * The engine's business-time calculator, whose walk is the roll.
	 *
	 * @var SlaCalculatorFake
	 */
	private SlaCalculatorFake $calculator;

	/**
	 * Seed the administered calendar these cases roll against.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		// The administered calendar closes on Tweede Paasdag 2027 and on
		// Koningsdag 2026. Nothing else: a term that rolls here rolled
		// because the ORGANISATION says the day is closed.
		$this->calendars = new WorkingCalendarServiceFake(
			new WorkingCalendarFake('gemeente-amsterdam', ['2027-03-29', '2026-04-27'])
		);
		$this->calculator = new SlaCalculatorFake();
	}

	/**
	 * A service wired to the engine calendar fake.
	 *
	 * @param bool $engine Whether OpenRegister answers at all.
	 *
	 * @return TermijnTimerService The service under test.
	 */
	private function service(bool $engine = true): TermijnTimerService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getOpenRegisterClass')->willReturnCallback(
			function (string $class) use ($engine): ?object {
				if ($engine === false) {
					return null;
				}

				return match ($class) {
					TermijnTimerService::CALENDAR_SERVICE_CLASS => $this->calendars,
					TermijnTimerService::SLA_CALCULATOR_CLASS => $this->calculator,
					default => null,
				};
			}
		);

		return new TermijnTimerService(
			settingsService: $settings,
			logger: $this->createMock(LoggerInterface::class),
			dates: $this->caseDates(),
			fallbackCalendar: new WorkingDayCalculator(),
		);
	}

	/**
	 * A term ending on a day the administered calendar closes runs to the
	 * next ordinary day, and the walk is the ENGINE's.
	 *
	 * @return void
	 */
	public function testAnEndOnAClosedDayRollsOnTheEngineCalendar(): void {
		$rolled = $this->service()->rollTermEnd(date: new DateTimeImmutable('2027-03-29'));

		self::assertSame('2027-03-30', $rolled->format('Y-m-d'));
		self::assertCount(1, $this->calculator->calls);
		self::assertSame('businessDays', $this->calculator->calls[0]['unit']);
		self::assertSame(0.0, $this->calculator->calls[0]['value']);
		self::assertSame('gemeente-amsterdam', $this->calculator->calls[0]['calendar']);
	}

	/**
	 * A term already ending on an ordinary day is returned unchanged, so the
	 * roll never lengthens a term that does not need it.
	 *
	 * @return void
	 */
	public function testAnEndOnAnOrdinaryDayIsUnchanged(): void {
		$rolled = $this->service()->rollTermEnd(date: new DateTimeImmutable('2027-03-30'));

		self::assertSame('2027-03-30', $rolled->format('Y-m-d'));
	}

	/**
	 * Dossiq holds no holiday list of its own on this path: a day the
	 * ORGANISATION closes rolls even though the Dutch national list does not
	 * name it, and a national holiday the organisation does not close on does
	 * not roll.
	 *
	 * @return void
	 */
	public function testTheOrganisationsCalendarDecidesAndNotDossiqs(): void {
		$calendars = new WorkingCalendarServiceFake(
			new WorkingCalendarFake('gemeente-eigen', ['2026-06-16'])
		);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getOpenRegisterClass')->willReturnCallback(
			function (string $class) use ($calendars): ?object {
				return match ($class) {
					TermijnTimerService::CALENDAR_SERVICE_CLASS => $calendars,
					TermijnTimerService::SLA_CALCULATOR_CLASS => $this->calculator,
					default => null,
				};
			}
		);
		$service = new TermijnTimerService(
			settingsService: $settings,
			logger: $this->createMock(LoggerInterface::class),
			dates: $this->caseDates(),
			fallbackCalendar: new WorkingDayCalculator(),
		);

		// A local closure no national list carries.
		self::assertSame('2026-06-17', $service->rollTermEnd(date: new DateTimeImmutable('2026-06-16'))->format('Y-m-d'));

		// Bevrijdingsdag, which dossiq's own list calls a holiday and this
		// organisation works through.
		self::assertSame('2026-05-05', $service->rollTermEnd(date: new DateTimeImmutable('2026-05-05'))->format('Y-m-d'));
	}

	/**
	 * The calendar named on the term and the organisation reach the resolver,
	 * so a tenant with its own calendar is not silently given the national one.
	 *
	 * @return void
	 */
	public function testTheNamedCalendarAndOrganisationReachTheResolver(): void {
		$this->service()->rollTermEnd(
			date: new DateTimeImmutable('2027-03-29'),
			roll: true,
			calendarSlug: 'gemeente-amsterdam',
			organisation: 'org-1'
		);

		self::assertSame(
			[['calendarSlug' => 'gemeente-amsterdam', 'organisation' => 'org-1']],
			$this->calendars->calls
		);
	}

	/**
	 * A term whose definition switches the roll off keeps the raw date, and
	 * the engine is never consulted.
	 *
	 * @return void
	 */
	public function testATermWithoutTheRollIsUnchanged(): void {
		$service = $this->service();
		$roll = $service->rollEnabled(definitie: ['rollToWorkingDay' => false]);

		self::assertFalse($roll);
		self::assertSame(
			'2027-03-29',
			$service->rollTermEnd(date: new DateTimeImmutable('2027-03-29'), roll: $roll)->format('Y-m-d')
		);
		self::assertSame([], $this->calculator->calls);
	}

	/**
	 * A definition that does not carry the flag still rolls: Awt art. 1
	 * applies by law, and the flag exists to switch it off.
	 *
	 * @return void
	 */
	public function testTheRollAppliesWhenTheDefinitionIsSilent(): void {
		$service = $this->service();

		self::assertTrue($service->rollEnabled(definitie: []));
		self::assertTrue($service->rollEnabled(definitie: ['rollToWorkingDay' => true]));
	}

	/**
	 * With OpenRegister absent the roll falls back to dossiq's own calendar
	 * and says so, rather than quietly leaving a statutory date on a Sunday.
	 *
	 * @return void
	 */
	public function testAnAbsentEngineFallsBackToTheLocalCalendarAndLogsIt(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())
			->method('info')
			->with(
				self::stringContains('engine calendar unavailable'),
				self::callback(static fn (array $context): bool => $context['rolled'] === '2026-06-22')
			);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getOpenRegisterClass')->willReturn(null);
		$service = new TermijnTimerService(
			settingsService: $settings,
			logger: $logger,
			dates: $this->caseDates(),
			fallbackCalendar: new WorkingDayCalculator(),
		);

		// 2026-06-21 is a Sunday; dossiq's own list moves it to the Monday.
		self::assertSame(
			'2026-06-22',
			$service->rollTermEnd(date: new DateTimeImmutable('2026-06-21'))->format('Y-m-d')
		);
	}

	/**
	 * A refusing calendar resolver is a degraded engine, not a crash: the
	 * fallback answers and the failure is logged.
	 *
	 * @return void
	 */
	public function testARefusingResolverDegradesToTheFallback(): void {
		$this->calendars->refuse = true;

		self::assertSame(
			'2026-12-28',
			$this->service()->rollTermEnd(date: new DateTimeImmutable('2026-12-26'))->format('Y-m-d')
		);
	}
}
