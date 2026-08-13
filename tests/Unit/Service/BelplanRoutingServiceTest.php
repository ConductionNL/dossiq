<?php

/**
 * BelplanRoutingService Unit Tests
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T06
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\BelplanRoutingService;
use OCA\Procest\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Fake ObjectService exposing the subset of the real OpenRegister API used by
 * BelplanRoutingService, so routing can be exercised without a live register.
 */
class FakeBelplanObjectService {

	/**
	 * The belplannen fixture records.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $belplannen = [];

	/**
	 * The specialist fixture records.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $specialisten = [];

	/**
	 * Mimic OpenRegister ObjectService::searchObjectsBySlug().
	 *
	 * The production code resolves register/schema slugs through the
	 * SearchesObjects trait, which delegates to searchObjectsBySlug() whenever
	 * a non-numeric identifier is supplied.
	 *
	 * @param string $register The register id/slug.
	 * @param string $schema The schema id/slug.
	 * @param array<string, mixed> $filters The filters (unused in the fake).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function searchObjectsBySlug(string $register, string $schema, array $filters = []): array {
		if ($schema === 'belplan-schema') {
			return $this->belplannen;
		}

		if ($schema === 'specialist-schema') {
			return $this->specialisten;
		}

		return [];
	}//end searchObjectsBySlug()
}//end class

/**
 * Unit tests for BelplanRoutingService.
 *
 * @covers \OCA\Procest\Service\BelplanRoutingService
 */
class BelplanRoutingServiceTest extends TestCase {

	/**
	 * The fake object service for test fixtures.
	 *
	 * @var FakeBelplanObjectService
	 */
	private FakeBelplanObjectService $objectService;

	/**
	 * The service under test.
	 *
	 * @var BelplanRoutingService
	 */
	private BelplanRoutingService $service;

	/**
	 * Set up fixtures with a configured belplan + specialists.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objectService = new FakeBelplanObjectService();

		$this->objectService->belplannen = [
			[
				'name' => 'Algemeen',
				'isActive' => true,
				'triggerNumber' => ['14000'],
				'routeringSteps' => [
					['type' => 'vaardigheid_match', 'zaaktype_to_vaardigheid' => ['omgevingsvergunning' => 'omgevingsvergunningen']],
					['type' => 'wachtrij_overflow', 'threshold_wachttijd_sec' => 180, 'fallback_rol' => 'generalist'],
				],
			],
		];

		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key): string {
				return match ($key) {
					'register' => 'reg',
					'belplan_schema' => 'belplan-schema',
					'specialist_beschikbaarheid_schema' => 'specialist-schema',
					default => '',
				};
			}
		);
		$settings->method('getKccConfigValue')->willReturnCallback(
			static function (string $key): string {
				return match ($key) {
					'belplan_overflow_threshold_wachttijd' => '180',
					'belplan_overflow_threshold_wachtrij_lengte' => '5',
					default => '',
				};
			}
		);

		$this->service = new BelplanRoutingService(
			settingsService: $settings,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end setUp()

	/**
	 * Routing picks the available specialist with the shortest wachtrij.
	 *
	 * @return void
	 */
	public function testRoutesToShortestQueueSpecialist(): void {
		$this->objectService->specialisten = [
			[
				'employeeId' => 'busy',
				'status' => 'beschikbaar',
				'expertises' => ['omgevingsvergunningen'],
				'currentQueueLengte' => 3,
				'gemiddeldeHandlingDuration' => 100,
			],
			[
				'employeeId' => 'free',
				'status' => 'beschikbaar',
				'expertises' => ['omgevingsvergunningen'],
				'currentQueueLengte' => 0,
				'gemiddeldeHandlingDuration' => 120,
			],
		];

		$result = $this->service->routeCall('14000', 'omgevingsvergunning');

		$this->assertSame(expected: 'free', actual: $result['destinationSpecialistId']);
		$this->assertFalse(condition: $result['escalatieFlag']);
		$this->assertSame(expected: 'omgevingsvergunningen', actual: $result['vaardigheid']);
	}//end testRoutesToShortestQueueSpecialist()

	/**
	 * When every specialist is busy, routing overflows with an escalatie-flag.
	 *
	 * @return void
	 */
	public function testOverflowToGeneralistWhenAllBusy(): void {
		$this->objectService->specialisten = [
			['employeeId' => 'a', 'status' => 'in_gesprek', 'expertises' => ['omgevingsvergunningen'], 'currentQueueLengte' => 2],
			['employeeId' => 'b', 'status' => 'wrap_up', 'expertises' => ['omgevingsvergunningen'], 'currentQueueLengte' => 1],
		];

		$result = $this->service->routeCall('14000', 'omgevingsvergunning');

		$this->assertNull(actual: $result['destinationSpecialistId']);
		$this->assertTrue(condition: $result['escalatieFlag']);
		$this->assertSame(expected: 'generalist', actual: $result['fallbackRol']);
	}//end testOverflowToGeneralistWhenAllBusy()

	/**
	 * An unknown dialed number throws (no active belplan).
	 *
	 * @return void
	 */
	public function testUnknownNumberThrows(): void {
		$this->expectException(exception: \RuntimeException::class);
		$this->service->routeCall('99999', 'omgevingsvergunning');
	}//end testUnknownNumberThrows()

	/**
	 * Availability filtering by vaardigheid excludes non-matching specialists.
	 *
	 * @return void
	 */
	public function testAvailabilityFiltersByVaardigheid(): void {
		$this->objectService->specialisten = [
			['employeeId' => 'a', 'status' => 'beschikbaar', 'expertises' => ['omgevingsvergunningen']],
			['employeeId' => 'b', 'status' => 'beschikbaar', 'expertises' => ['bouwtoezicht']],
		];

		$matched = $this->service->getSpecialistBeschikbaarheid('bouwtoezicht');

		$this->assertCount(expectedCount: 1, haystack: $matched);
		$this->assertSame(expected: 'b', actual: $matched[0]['employeeId']);
	}//end testAvailabilityFiltersByVaardigheid()
}//end class
