<?php

/**
 * OpenWorkloadAgeService refusal tests.
 *
 * Pins the two answers that are not a report: an unconfigured register or
 * case schema answers an empty report, and a store that cannot be asked is
 * refused rather than read as "nothing is standing".
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\OpenWorkloadAgeService;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests for the answers of OpenWorkloadAgeService that are not a report.
 */
class OpenWorkloadAgeRefusalTest extends TestCase {
	/**
	 * The service over a store that throws on every call, with the given config.
	 *
	 * @param array<string, string> $config Config values by key.
	 *
	 * @return OpenWorkloadAgeService
	 */
	private function service(array $config): OpenWorkloadAgeService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn(
			new class {
				/**
				 * Every call fails.
				 *
				 * @param string            $name      The method.
				 * @param array<int, mixed> $arguments Its arguments.
				 *
				 * @return mixed Never returns.
				 */
				public function __call(string $name, array $arguments): mixed {
					throw new RuntimeException('store down');
				}//end __call()
			}
		);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key): string => ($config[$key] ?? ''));

		return new OpenWorkloadAgeService(settingsService: $settings, logger: new NullLogger());
	}//end service()

	/**
	 * No register or case schema answers an empty report without asking the store.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredStoreAnswersAnEmptyReport(): void {
		self::assertSame(
			['generatedAt' => '2026-10-16', 'openCases' => 0, 'truncated' => false, 'perStatus' => []],
			$this->service(['register' => 'dossiq'])->report(now: new DateTimeImmutable('2026-10-16 14:00'))
		);
	}//end testAnUnconfiguredStoreAnswersAnEmptyReport()

	/**
	 * A store that cannot be asked is refused as indeterminate.
	 *
	 * @return void
	 */
	public function testAnUnreadableStoreIsRefused(): void {
		try {
			$this->service(['register' => 'dossiq', 'case_schema' => 'case'])->report(now: new DateTimeImmutable('2026-10-16'));
			self::fail('An unreadable store must be refused.');
		} catch (RefusedException $e) {
			self::assertSame('open_workload_unreadable', $e->getMessage());
		}
	}//end testAnUnreadableStoreIsRefused()
}//end class
