<?php

/**
 * VoorstelStatusGuard Unit Tests
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Transitions
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/besluitvorming-workflow/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Transitions\VoorstelStatusGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Transitions\VoorstelStatusGuard
 *
 * @uses \OCA\Dossiq\Service\Transitions\GuardResult
 */
class VoorstelStatusGuardTest extends TestCase {

	/**
	 * A guard naming no statuses is unconfigured, and says so.
	 *
	 * @return void
	 */
	public function testFailsWhenAllowedStatusesMissing(): void {
		$guard = new VoorstelStatusGuard($this->createMock(SettingsService::class), new NullLogger());
		$result = $guard->evaluate(guardConfig: [], case: ['id' => 'c'], userId: 'u');

		self::assertFalse($result->passed);
		self::assertSame('Voorstel-status guard missing allowedStatuses', $result->failureMessage);
	}//end testFailsWhenAllowedStatusesMissing()

	/**
	 * A concluded parafering chain lets the case move on.
	 *
	 * @return void
	 */
	public function testPassesOnAConcludedChain(): void {
		$guard = new VoorstelStatusGuard($this->settingsFor([['status' => 'geaccordeerd']]), new NullLogger());
		$result = $guard->evaluate(
			guardConfig: ['allowedStatuses' => ['geaccordeerd']],
			case: ['id' => 'c'],
			userId: 'u',
		);

		self::assertTrue($result->passed);
		self::assertSame('geaccordeerd', $result->details['status']);
	}//end testPassesOnAConcludedChain()

	/**
	 * A chain still running holds the case where it is.
	 *
	 * @return void
	 */
	public function testFailsWhileTheChainIsStillRunning(): void {
		$guard = new VoorstelStatusGuard($this->settingsFor([['status' => 'in_parafering']]), new NullLogger());
		$result = $guard->evaluate(
			guardConfig: ['allowedStatuses' => ['geaccordeerd']],
			case: ['id' => 'c'],
			userId: 'u',
		);

		self::assertFalse($result->passed);
		self::assertStringContainsString('in_parafering', (string)$result->failureMessage);
	}//end testFailsWhileTheChainIsStillRunning()

	/**
	 * A returned chain is not a completed one.
	 *
	 * @return void
	 */
	public function testFailsOnAReturnedChain(): void {
		$guard = new VoorstelStatusGuard($this->settingsFor([['status' => 'teruggestuurd']]), new NullLogger());
		$result = $guard->evaluate(
			guardConfig: ['allowedStatuses' => ['geaccordeerd']],
			case: ['id' => 'c'],
			userId: 'u',
		);

		self::assertFalse($result->passed);
	}//end testFailsOnAReturnedChain()

	/**
	 * The newest chain decides when a case carries more than one.
	 *
	 * @return void
	 */
	public function testTheLatestChainDecides(): void {
		$settings = $this->settingsFor([['status' => 'teruggestuurd'], ['status' => 'geaccordeerd']]);
		$guard = new VoorstelStatusGuard($settings, new NullLogger());
		$result = $guard->evaluate(
			guardConfig: ['allowedStatuses' => ['geaccordeerd']],
			case: ['id' => 'c'],
			userId: 'u',
		);

		self::assertTrue($result->passed);
	}//end testTheLatestChainDecides()

	/**
	 * No voorstel at all means parafering did not complete.
	 *
	 * @return void
	 */
	public function testFailsClosedWhenNoVoorstelExists(): void {
		$guard = new VoorstelStatusGuard($this->settingsFor([]), new NullLogger());
		$result = $guard->evaluate(
			guardConfig: ['allowedStatuses' => ['geaccordeerd']],
			case: ['id' => 'c'],
			userId: 'u',
		);

		self::assertFalse($result->passed);
		self::assertSame('Geen parafeervoorstel gevonden voor deze zaak', $result->failureMessage);
	}//end testFailsClosedWhenNoVoorstelExists()

	/**
	 * A store that throws must not let the case through.
	 *
	 * @return void
	 */
	public function testFailsClosedWhenTheLookupThrows(): void {
		$objectService = new class {
			/**
			 * @param string $register The register slug.
			 * @param string $schema The schema slug.
			 * @param array<string, mixed> $filters The filters.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
				throw new RuntimeException('store down');
			}
		};

		$guard = new VoorstelStatusGuard($this->buildSettings($objectService), new NullLogger());
		$result = $guard->evaluate(
			guardConfig: ['allowedStatuses' => ['geaccordeerd']],
			case: ['id' => 'c'],
			userId: 'u',
		);

		self::assertFalse($result->passed);
	}//end testFailsClosedWhenTheLookupThrows()

	/**
	 * A SettingsService double whose proposal store answers with fixed rows.
	 *
	 * @param array<int, array<string, mixed>> $rows The voorstel rows.
	 *
	 * @return SettingsService&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function settingsFor(array $rows): SettingsService {
		$objectService = new class ($rows) {
			/**
			 * @param array<int, array<string, mixed>> $rows The rows to answer with.
			 */
			public function __construct(private readonly array $rows) {
			}

			/**
			 * @param string $register The register slug.
			 * @param string $schema The schema slug.
			 * @param array<string, mixed> $filters The filters.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
				return $this->rows;
			}
		};

		return $this->buildSettings($objectService);
	}//end settingsFor()

	/**
	 * A SettingsService double wired to a given object-service double.
	 *
	 * @param object $objectService The object-service double.
	 *
	 * @return SettingsService&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function buildSettings(object $objectService): SettingsService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => match ($key) {
				'register' => 'dossiq',
				'voorstel_schema' => 'proposal',
				default => '',
			}
		);

		return $settings;
	}//end buildSettings()
}//end class
