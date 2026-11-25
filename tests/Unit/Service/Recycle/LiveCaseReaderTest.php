<?php

/**
 * Unit tests for reading one live case from the configured case store.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Recycle
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
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Recycle;

use OCA\Dossiq\Service\Recycle\LiveCaseReader;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Recycle\LiveCaseReader
 * @uses   \OCA\Dossiq\Service\Recycle\CaseStoreLocation
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 */
class LiveCaseReaderTest extends TestCase {

	/**
	 * A failing store is logged with the case id and answers null.
	 *
	 * @return void
	 */
	public function testAFailingStoreIsLoggedAndAnswersNull(): void {
		$objectService = new class {
			/**
			 * Always fails.
			 *
			 * @param string $id      The case id.
			 * @param mixed  $options The find options.
			 *
			 * @return array<string, mixed> Never.
			 */
			public function find(string $id, mixed $options): array {
				throw new RuntimeException(message: 'store down');
			}
		};
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn(value: $objectService);
		$settings->method('getConfigValue')->willReturn(value: 'configured');
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with('Dossiq: the case behind the retention clocks could not be read', ['caseId' => 'case-1', 'error' => 'store down']);

		self::assertNull(actual: (new LiveCaseReader(settingsService: $settings, logger: $logger))->read(caseId: 'case-1'));
	}//end testAFailingStoreIsLoggedAndAnswersNull()

	/**
	 * Without a configured register nothing is read.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredStoreReadsNothing(): void {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn(value: new \stdClass());
		$settings->method('getConfigValue')->willReturn(value: '');

		$reader = new LiveCaseReader(settingsService: $settings, logger: $this->createMock(originalClassName: LoggerInterface::class));

		self::assertNull(actual: $reader->read(caseId: 'case-1'));
	}//end testAnUnconfiguredStoreReadsNothing()
}//end class
