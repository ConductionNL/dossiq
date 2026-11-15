<?php

/**
 * Test helper for the one date write path.
 *
 * Every write-path test needs a normaliser bound to a known zone. Building it
 * in one place keeps the zone under test explicit and stops each suite
 * inventing its own tenant.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/one-date-write-path/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Support;

use DateTimeImmutable;
use OCA\Dossiq\Service\CaseDateNormaliser;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\TenantConfigurationService;
use OCA\Dossiq\Service\TenantContext;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

/**
 * Builds a CaseDateNormaliser whose tenant answers a stated zone.
 *
 * @spec openspec/changes/one-date-write-path/specs/case-management/spec.md
 */
trait MakesCaseDateNormaliser {
	/**
	 * The instant a frozen normaliser reads by default.
	 *
	 * Deliberately the instant that broke three suites: 22:30 UTC is half past
	 * midnight in Amsterdam, so the process day and the administered day are
	 * two different days. A test pinned here proves it reasons in the
	 * administered zone; a test that only ever ran at noon could not.
	 *
	 * @var string
	 */
	private const CLOCK_AT_THE_DAY_BOUNDARY = '2026-09-19T22:30:00+00:00';

	/**
	 * A normaliser reading the given administered zone.
	 *
	 * @param string            $zone  The IANA identifier the tenant administers.
	 * @param ITimeFactory|null $clock The clock it reads, or null for the live one.
	 *
	 * @return CaseDateNormaliser
	 */
	private function caseDates(string $zone = 'Europe/Amsterdam', ?ITimeFactory $clock = null): CaseDateNormaliser {
		$context = $this->createMock(originalClassName: TenantContext::class);
		$context->method('isBound')->willReturn(true);
		$context->method('getTenantId')->willReturn('tenant-under-test');

		$configuration = $this->createMock(originalClassName: TenantConfigurationService::class);
		$configuration->method('getConfig')->willReturn(['timezone' => $zone]);

		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getOpenRegisterClass')->willReturn(null);

		return new CaseDateNormaliser(
			tenantContext: $context,
			tenantConfiguration: $configuration,
			settingsService: $settings,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			time: ($clock ?? $this->liveClock()),
		);
	}//end caseDates()

	/**
	 * A normaliser stopped at a stated instant.
	 *
	 * Use this, and build every fixture date from its `today()`, whenever a
	 * test asks what day it is. Both halves then read the same zone and the
	 * same instant, so the test says the same thing every day of the year.
	 *
	 * @param string $instant The moment the clock reports, in any parseable form.
	 * @param string $zone    The IANA identifier the tenant administers.
	 *
	 * @return CaseDateNormaliser
	 */
	private function caseDatesFrozenAt(
		string $instant = self::CLOCK_AT_THE_DAY_BOUNDARY,
		string $zone = 'Europe/Amsterdam',
	): CaseDateNormaliser {
		return $this->caseDates(zone: $zone, clock: $this->clockFixedAt(instant: $instant));
	}//end caseDatesFrozenAt()

	/**
	 * A clock that always reports the same instant.
	 *
	 * @param string $instant The moment it reports.
	 *
	 * @return ITimeFactory
	 */
	private function clockFixedAt(string $instant): ITimeFactory {
		$frozen = new DateTimeImmutable($instant);

		$clock = $this->createMock(originalClassName: ITimeFactory::class);
		$clock->method('now')->willReturn($frozen);
		$clock->method('getTime')->willReturn($frozen->getTimestamp());

		return $clock;
	}//end clockFixedAt()

	/**
	 * The wall clock, for the suites that do not pin an instant.
	 *
	 * @return ITimeFactory
	 */
	private function liveClock(): ITimeFactory {
		$clock = $this->createMock(originalClassName: ITimeFactory::class);
		$clock->method('now')->willReturnCallback(static fn (): DateTimeImmutable => new DateTimeImmutable('now'));
		$clock->method('getTime')->willReturnCallback(static fn (): int => time());

		return $clock;
	}//end liveClock()
}//end trait
