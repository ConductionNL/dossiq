<?php

/**
 * Whose clock a term date is on.
 *
 * A calendar date is not an instant. "The term ends on 2 June" becomes a
 * moment only once somebody says where midnight is, and if the answer is the
 * tenant's display preference then two handlers on one case are owed different
 * days and the one who travelled is right.
 *
 * The order is the whole requirement: the ORGANISATION calendar first, the
 * tenant only when no calendar answers. REQ-TERM-016.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Termijn
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Termijn;

use OCA\Dossiq\Service\CaseDateNormaliser;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\TenantConfigurationService;
use OCA\Dossiq\Service\TenantContext;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The engine calendar's zone wins over the tenant's.
 *
 * @covers \OCA\Dossiq\Service\CaseDateNormaliser
 * @uses \OCA\Dossiq\Service\SettingsService
 * @uses \OCA\Dossiq\Service\TenantConfigurationService
 * @uses \OCA\Dossiq\Service\TenantContext
 */
class TermZoneIsTheCalendarsTest extends TestCase {
	// The clock is a seam here too: this suite is about which zone wins,
	// not about which day it is.
	use MakesCaseDateNormaliser;

	/**
	 * Build a normaliser with a tenant zone and an optional engine calendar.
	 *
	 * @param string      $tenantZone   The zone the tenant administers.
	 * @param string|null $calendarZone The zone the engine calendar answers, or null for none.
	 *
	 * @return CaseDateNormaliser The subject.
	 */
	private function normaliser(string $tenantZone, ?string $calendarZone): CaseDateNormaliser {
		$context = $this->createMock(TenantContext::class);
		$context->method('isBound')->willReturn(true);
		$context->method('getTenantId')->willReturn('tenant-1');

		$config = $this->createMock(TenantConfigurationService::class);
		$config->method('getConfig')->willReturn(['timezone' => $tenantZone]);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getOpenRegisterClass')->willReturn(
			$calendarZone === null ? null : new FakeZoneCalendars(zone: $calendarZone)
		);

		return new CaseDateNormaliser(
			tenantContext: $context,
			tenantConfiguration: $config,
			settingsService: $settings,
			logger: $this->createMock(LoggerInterface::class),
			time: $this->clockFixedAt(instant: self::CLOCK_AT_THE_DAY_BOUNDARY),
		);
	}

	/**
	 * The organisation calendar's zone is the one used.
	 *
	 * THE ENGINE CLASS IS ALIASED INTO EXISTENCE, which is what `WorkingClock`,
	 * `WorkingDayRoll` and the intake term start already do for the same seam.
	 * This test used to skip instead, and the skip was not conditional in
	 * practice: `OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarService` is
	 * never on this repository's classpath, so the ORDER this test exists to
	 * pin was checked nowhere and the run stayed green about it. The normaliser
	 * guards on `class_exists` before it asks the container, so making the name
	 * real for this process is the only way to walk the branch that matters.
	 *
	 * It runs in its own process, so the alias cannot leak into another test's
	 * `class_exists()`.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function testTheCalendarZoneWinsOverTheTenants(): void {
		if (class_exists(CaseDateNormaliser::ENGINE_CALENDAR_CLASS) === false) {
			class_alias(FakeZoneCalendars::class, CaseDateNormaliser::ENGINE_CALENDAR_CLASS);
		}

		$this->assertSame(
			'Europe/Amsterdam',
			$this->normaliser(tenantZone: 'Europe/Berlin', calendarZone: 'Europe/Amsterdam')
				->timeZone()->getName()
		);
	}

	/**
	 * With no calendar answering, the tenant's zone is the fallback.
	 *
	 * REQ-TERM-016 says the term zone is not the tenant's. It is not, when
	 * there is a calendar; this is what is left when there is none, and it is
	 * better than the process default, which is invisible from the data. The
	 * deviation is deliberate and recorded rather than silent.
	 *
	 * @return void
	 */
	public function testWithoutACalendarTheTenantZoneIsTheFallback(): void {
		$this->assertSame(
			'Europe/Berlin',
			$this->normaliser(tenantZone: 'Europe/Berlin', calendarZone: null)
				->timeZone()->getName()
		);
	}
}//end class

/**
 * Stands in for OpenRegister's WorkingCalendarService.
 */
class FakeZoneCalendars {
	/**
	 * Constructor.
	 *
	 * @param string $zone The zone its calendar answers.
	 */
	public function __construct(private readonly string $zone) {

	}//end __construct()

	/**
	 * Answer a calendar carrying the zone.
	 *
	 * @param string|null $calendarSlug The slug.
	 * @param string|null $organisation The organisation.
	 *
	 * @return object The calendar.
	 */
	public function resolve(?string $calendarSlug, ?string $organisation): object {
		return new FakeZoneCalendar(zone: $this->zone);
	}//end resolve()
}//end class

/**
 * Stands in for OpenRegister's WorkingCalendar.
 */
class FakeZoneCalendar {
	/**
	 * Constructor.
	 *
	 * @param string $zone The zone.
	 */
	public function __construct(private readonly string $zone) {

	}//end __construct()

	/**
	 * The zone.
	 *
	 * @return string The IANA name.
	 */
	public function getTimeZone(): string {
		return $this->zone;
	}//end getTimeZone()
}//end class
