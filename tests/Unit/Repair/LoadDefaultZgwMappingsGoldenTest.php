<?php

/**
 * Golden test for the default ZGW mappings.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Repair
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

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\LoadDefaultZgwMappings;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\ZgwMappingService;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Every default mapping, per resource, matches the recording.
 *
 * @covers \OCA\Dossiq\Repair\LoadDefaultZgwMappings
 *
 * @spec openspec/specs/zgw-api-mapping/spec.md
 */
class LoadDefaultZgwMappingsGoldenTest extends TestCase {
	use MatchesGoldenFiles;

	/**
	 * Every resource's default mapping matches the recording, one golden file per resource.
	 *
	 * @return void
	 */
	public function testEveryDefaultMappingMatchesTheRecording(): void {
		$mappings = $this->mappings();
		$this->assertMatchesGolden(name: 'load-default-zgw-mappings/resources', value: array_keys($mappings));
		foreach ($mappings as $resource => $mapping) {
			$this->assertMatchesGolden(name: 'load-default-zgw-mappings/' . strtolower((string)$resource), value: $mapping);
		}
	}//end testEveryDefaultMappingMatchesTheRecording()

	/**
	 * The default mappings over fixed settings.
	 *
	 * @return array<string, mixed>
	 */
	private function mappings(): array {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getSettings')->willReturn(
			['register' => 'reg-1', 'case_schema' => 'case-s', 'case_type_schema' => 'ct-s', 'status_schema' => 'st-s']
		);

		$repair = new LoadDefaultZgwMappings(
			zgwMappingService: $this->createMock(originalClassName: ZgwMappingService::class),
			settingsService: $settings,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);

		return $repair->getDefaultMappings(registerId: 'reg-1');
	}//end mappings()
}//end class
