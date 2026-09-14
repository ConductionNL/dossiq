<?php

/**
 * CaseDateNormaliser unit tests.
 *
 * One submitted day, three shapes, one stored instant; an unreadable value
 * refused rather than guessed; and the zone read from the administrator.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
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

namespace OCA\Dossiq\Tests\Unit\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Dossiq\Service\CaseDateNormaliser;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\TenantConfigurationService;
use OCA\Dossiq\Service\TenantContext;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\CaseDateNormaliser
 */
class CaseDateNormaliserTest extends TestCase {
	/**
	 * Build a normaliser whose tenant answers the given zone.
	 *
	 * @param string|null $tenantZone The administered zone, or null for none.
	 * @param LoggerInterface|null $logger An optional logger to assert on.
	 *
	 * @return CaseDateNormaliser
	 */
	private function normaliser(?string $tenantZone, ?LoggerInterface $logger = null): CaseDateNormaliser {
		$context = $this->createMock(TenantContext::class);
		$context->method('isBound')->willReturn($tenantZone !== null);
		$context->method('getTenantId')->willReturn('tenant-1');

		$config = $this->createMock(TenantConfigurationService::class);
		$config->method('getConfig')->willReturn(
			$tenantZone === null ? null : ['timezone' => $tenantZone]
		);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getOpenRegisterClass')->willReturn(null);

		return new CaseDateNormaliser(
			tenantContext: $context,
			tenantConfiguration: $config,
			settingsService: $settings,
			logger: ($logger ?? $this->createMock(LoggerInterface::class)),
		);
	}

	public function testNoTenantFallsBackToTheDeclaredDefault(): void {
		self::assertSame(
			'Europe/Amsterdam',
			$this->normaliser(null)->timeZone()->getName()
		);
	}

	public function testTheTenantZoneIsRead(): void {
		self::assertSame(
			'Europe/Brussels',
			$this->normaliser('Europe/Brussels')->timeZone()->getName()
		);
	}

	public function testAZoneOutsideTheAllowListIsRefused(): void {
		self::assertSame(
			'Europe/Amsterdam',
			$this->normaliser('Pacific/Auckland')->timeZone()->getName()
		);
	}

	public function testTheFallbackIsLoggedOncePerRequest(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('info');

		$normaliser = $this->normaliser(null, $logger);
		$normaliser->toCalendarDate('2028-01-31', 'endDate');
		$normaliser->toCalendarDate('2028-02-01', 'endDate');
		$normaliser->toMoment('2028-02-02', 'endDate');
	}

	/**
	 * A bare date, a local time and an offset-carrying value are one instant.
	 *
	 * @return void
	 */
	public function testTheSameDayInThreeShapesIsOneInstant(): void {
		$normaliser = $this->normaliser('Europe/Amsterdam');

		$bare = $normaliser->parse('2028-01-31', 'endDate');
		$local = $normaliser->parse('2028-01-31T00:00:00', 'endDate');
		$offset = $normaliser->parse('2028-01-31T00:00:00+01:00', 'endDate');

		self::assertSame($bare->getTimestamp(), $local->getTimestamp());
		self::assertSame($bare->getTimestamp(), $offset->getTimestamp());
		self::assertSame('2028-01-31', $normaliser->toCalendarDate('2028-01-31', 'endDate'));
		self::assertSame('2028-01-31', $normaliser->toCalendarDate('2028-01-31T00:00:00+01:00', 'endDate'));
	}

	public function testAMomentCarriesTheTenantOffset(): void {
		self::assertSame(
			'2028-01-31T00:00:00+01:00',
			$this->normaliser('Europe/Amsterdam')->toMoment('2028-01-31', 'endDate')
		);
		self::assertSame(
			'2028-07-31T00:00:00+02:00',
			$this->normaliser('Europe/Amsterdam')->toMoment('2028-07-31', 'endDate')
		);
	}

	public function testABelgianTenantGetsBelgianOffsets(): void {
		self::assertSame(
			'2028-01-31T00:00:00+01:00',
			$this->normaliser('Europe/Brussels')->toMoment('2028-01-31', 'endDate')
		);
	}

	public function testAUtcTenantReadsAnOffsetValueInUtc(): void {
		self::assertSame(
			'2028-01-30T23:00:00+00:00',
			$this->normaliser('UTC')->toMoment('2028-01-31T00:00:00+01:00', 'endDate')
		);
	}

	public function testAnUnreadableValueIsRefusedAndNamesTheField(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/deadline/');
		$this->normaliser('Europe/Amsterdam')->parse('31-01-2028', 'deadline');
	}

	public function testAnEmptyValueIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->normaliser('Europe/Amsterdam')->parse('', 'deadline');
	}

	public function testAnImpossibleCalendarDayIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->normaliser('Europe/Amsterdam')->parse('2028-02-31', 'deadline');
	}

	public function testARefusedValueIsNeverReplacedByToday(): void {
		$normaliser = $this->normaliser('Europe/Amsterdam');
		try {
			$normaliser->parse('tomorrow', 'deadline');
			self::fail('An unreadable value must be refused.');
		} catch (InvalidArgumentException $exception) {
			self::assertStringNotContainsString($normaliser->todayAsCalendarDate(), $exception->getMessage());
		}
	}

	public function testTryParseAnswersNullForAReadPath(): void {
		$normaliser = $this->normaliser('Europe/Amsterdam');
		self::assertNull($normaliser->tryParse('31-01-2028'));
		self::assertNull($normaliser->tryParse(''));
		self::assertNull($normaliser->tryParse(null));
		self::assertNull($normaliser->toCalendarDateOrNull('not a date'));
		self::assertSame('2028-01-31', $normaliser->toCalendarDateOrNull('2028-01-31T18:00:00+01:00'));
	}

	public function testAStoredValueReadsBackUnchanged(): void {
		$normaliser = $this->normaliser('Europe/Amsterdam');
		foreach (['2026-03-02', '2026-03-02T09:30:00+01:00'] as $stored) {
			self::assertSame(
				$normaliser->toCalendarDateOrNull($stored),
				$normaliser->toCalendarDateOrNull($normaliser->toMoment($stored, 'endDate'))
			);
		}
	}

	public function testADateTimeInstanceIsAcceptedAndRezoned(): void {
		$normaliser = $this->normaliser('Europe/Amsterdam');
		$moment = new DateTimeImmutable('2028-01-31T00:00:00+00:00');
		self::assertSame('2028-01-31T01:00:00+01:00', $normaliser->formatMoment($moment));
		self::assertSame('2028-01-31', $normaliser->formatCalendarDate($moment));
	}

	public function testTodayIsMidnightInTheAdministeredZone(): void {
		$normaliser = $this->normaliser('Europe/Brussels');
		$today = $normaliser->today();
		self::assertSame('00:00:00', $today->format('H:i:s'));
		self::assertSame('Europe/Brussels', $today->getTimezone()->getName());
	}

	public function testFromPartsDoesNotInheritTheProcessZone(): void {
		$normaliser = $this->normaliser('Europe/Amsterdam');
		$easterMonday = $normaliser->fromParts(2026, 4, 6);
		self::assertSame('2026-04-06', $easterMonday->format('Y-m-d'));
		self::assertSame('Europe/Amsterdam', $easterMonday->getTimezone()->getName());
	}
}//end class
