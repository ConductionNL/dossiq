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
	 * Each resource reads its own schema setting, recorded with every schema setting set to a value of its own.
	 *
	 * @return void
	 */
	public function testEachResourceReadsItsOwnSchemaSetting(): void {
		$keys = [
			'abonnement_schema', 'case_document_schema', 'case_object_schema', 'case_property_schema', 'case_schema',
			'case_type_schema', 'catalogus_schema', 'customer_contact_schema', 'decision_document_schema', 'decision_schema',
			'decision_type_schema', 'dispatch_schema', 'document_link_schema', 'document_schema', 'document_type_schema',
			'kanaal_schema', 'property_definition_schema', 'result_schema', 'result_type_schema', 'role_schema',
			'role_type_schema', 'status_record_schema', 'status_type_schema', 'usage_rights_schema',
			'zaaktype_informatieobjecttype_schema',
		];
		$sources = [];
		foreach ($this->mappings(settings: array_combine($keys, array_map(static fn (string $k): string => 'schema-of-' . $k, $keys))) as $resource => $mapping) {
			$sources[$resource] = array_intersect_key($mapping, array_flip(['sourceRegister', 'sourceSchema']));
		}

		$this->assertMatchesGolden(name: 'load-default-zgw-mappings/sources', value: $sources);
	}//end testEachResourceReadsItsOwnSchemaSetting()

	/**
	 * The default mappings over fixed settings.
	 *
	 * @param array<string, string>|null $settings The settings to read, null for the fixed four.
	 *
	 * @return array<string, mixed>
	 */
	private function mappings(?array $settings = null): array {
		$service = $this->createMock(originalClassName: SettingsService::class);
		$service->method('getSettings')->willReturn(
			($settings ?? ['register' => 'reg-1', 'case_schema' => 'case-s', 'case_type_schema' => 'ct-s', 'status_schema' => 'st-s'])
		);

		$repair = new LoadDefaultZgwMappings(
			zgwMappingService: $this->createMock(originalClassName: ZgwMappingService::class),
			settingsService: $service,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);

		return $repair->getDefaultMappings(registerId: 'reg-1');
	}//end mappings()
}//end class
