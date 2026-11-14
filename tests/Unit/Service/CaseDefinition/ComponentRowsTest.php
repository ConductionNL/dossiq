<?php

/**
 * Unit tests for ComponentRows: each case type collection's rows are yielded
 * with the schema its settings key names, one object counts as one row, and
 * an unconfigured schema stops the write.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\CaseDefinition;

use OCA\Dossiq\Service\CaseDefinition\ComponentRows;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\CaseDefinition\ComponentRows
 */
class ComponentRowsTest extends TestCase {

	/**
	 * Rows over settings that answer the given schema keys.
	 *
	 * @param array<string, string> $schemas Schema ids by settings key.
	 */
	private function rows(array $schemas): ComponentRows {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key): string => $schemas[$key] ?? '');
		return new ComponentRows($settings);
	}//end rows()

	public function testRowsAreYieldedWithTheirCollectionsSchemaInCollectionOrder(): void {
		$data = [
			'caseType' => ['id' => 'ct-1'],
			'statusTypes' => [['id' => 's-1'], 'junk', ['id' => 's-2']],
			'roleTypes' => [['id' => 'r-1']],
		];
		$rows = $this->rows(['case_type_schema' => '10', 'status_type_schema' => '11', 'role_type_schema' => '12']);

		$this->assertSame(
			[['11', ['id' => 's-1']], ['11', ['id' => 's-2']], ['10', ['id' => 'ct-1']]],
			iterator_to_array($rows->schemaRows($data, ['statusTypes', 'caseType']), false)
		);
	}//end testRowsAreYieldedWithTheirCollectionsSchemaInCollectionOrder()

	public function testAnAbsentEmptyOrScalarCollectionYieldsNothing(): void {
		$rows = $this->rows(['document_type_schema' => '13', 'result_type_schema' => '14', 'property_definition_schema' => '15']);

		$this->assertSame(
			[],
			iterator_to_array($rows->schemaRows(['documentTypes' => [], 'resultTypes' => 'none'], ['documentTypes', 'resultTypes', 'propertyDefinitions']), false)
		);
	}//end testAnAbsentEmptyOrScalarCollectionYieldsNothing()

	public function testAnUnconfiguredSchemaStopsTheWriteAfterTheEarlierCollections(): void {
		$rows = $this->rows(['case_type_schema' => '10']);
		$yielded = [];

		try {
			foreach ($rows->schemaRows(['caseType' => ['id' => 'ct-1'], 'statusTypes' => [['id' => 's-1']]], ['caseType', 'statusTypes']) as $row) {
				$yielded[] = $row;
			}

			$this->fail('Expected a RuntimeException.');
		} catch (RuntimeException $e) {
			$this->assertSame("No schema is configured for 'statusTypes', so its rows cannot be written.", $e->getMessage());
		}

		$this->assertSame([['10', ['id' => 'ct-1']]], $yielded);
	}//end testAnUnconfiguredSchemaStopsTheWriteAfterTheEarlierCollections()
}//end class
