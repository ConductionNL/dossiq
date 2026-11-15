<?php

/**
 * What a case definition export holds: the dependency list and each component.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\CaseDefinitionContents;
use OCA\Dossiq\Service\CaseDefinitionExport;
use OCA\Dossiq\Service\CaseTypeStore;
use PHPUnit\Framework\TestCase;

/**
 * The rows read per schema key, the dependency set, and every component shape.
 *
 * @covers \OCA\Dossiq\Service\CaseDefinitionContents
 * @uses \OCA\Dossiq\Service\CaseDefinitionExport
 */
class CaseDefinitionContentsTest extends TestCase {

	/**
	 * The contents over a store holding the given rows per schema key, for case type `ct-1` only.
	 *
	 * @param array<string, list<array<string, mixed>>> $rows Rows by schema key.
	 *
	 * @return CaseDefinitionContents
	 */
	private function contents(array $rows): CaseDefinitionContents {
		$store = $this->createMock(CaseTypeStore::class);
		$store->method('rowsOfType')->willReturnCallback(
			static fn (string $schemaKey, string $caseTypeId): array => $caseTypeId === 'ct-1' ? ($rows[$schemaKey] ?? []) : []
		);
		$store->method('rowId')->willReturnCallback(static fn (array $row): string => (string)($row['id'] ?? ''));

		return new CaseDefinitionContents($store);
	}//end contents()

	/**
	 * The export of case type `ct-1`.
	 *
	 * @param array<string, mixed> $caseType The case type object.
	 *
	 * @return CaseDefinitionExport
	 */
	private static function export(array $caseType = []): CaseDefinitionExport {
		return new CaseDefinitionExport('ct-1', $caseType);
	}//end export()

	/**
	 * The dependencies are the case type, every row id and every outward reference, unique, trimmed and sorted.
	 *
	 * @return void
	 */
	public function testDependenciesAreTheCaseTypeItsRowsAndItsOutwardReferences(): void {
		$contents = $this->contents(
			[
				'property_definition_schema' => [['id' => 'pd-2'], ['id' => 'pd-1']],
				'status_type_schema' => [['id' => 'st-1'], ['id' => '']],
				'workflow_template_schema' => [['id' => 'wf-1']],
			]
		);
		$export = self::export(
			[
				'decisionTypes' => [' bt-1 ', 'bt-1', '', 7],
				'relatedCaseTypes' => ['ct-9'],
				'subCaseTypes' => 'ct-8',
				'parentCaseType' => '  ',
				'defaultInformatieobjecttype' => 'iot-1',
			]
		);

		$this->assertSame(['bt-1', 'ct-1', 'ct-8', 'ct-9', 'iot-1', 'pd-1', 'pd-2', 'st-1', 'wf-1'], $contents->dependenciesOf($export));
	}//end testDependenciesAreTheCaseTypeItsRowsAndItsOutwardReferences()

	/**
	 * A case type without rows or references depends on itself only.
	 *
	 * @return void
	 */
	public function testABareCaseTypeDependsOnItselfOnly(): void {
		$this->assertSame(['ct-1'], $this->contents([])->dependenciesOf(self::export()));
	}//end testABareCaseTypeDependsOnItselfOnly()

	/**
	 * The schema, permissions, documents and metadata components pair the rows with the case type's own fields.
	 *
	 * @return void
	 */
	public function testTheRowComponents(): void {
		$contents = $this->contents(
			[
				'property_definition_schema' => [['id' => 'pd-1']],
				'role_type_schema' => [['id' => 'rt-1']],
				'document_type_schema' => [['id' => 'dt-1']],
				'result_type_schema' => [['id' => 'res-1']],
			]
		);
		$caseType = ['rightsMatrix' => [['role' => 'behandelaar']], 'defaultInformatieobjecttype' => 'iot-1', 'decisionTypes' => ['bt-1']];
		$export = self::export($caseType);

		$this->assertSame(['caseTypeId' => 'ct-1', 'caseType' => $caseType, 'propertyDefinitions' => [['id' => 'pd-1']]], $contents->exportComponent($export, 'schema'));
		$this->assertSame(['roleTypes' => [['id' => 'rt-1']], 'rightsMatrix' => [['role' => 'behandelaar']]], $contents->exportComponent($export, 'permissions'));
		$this->assertSame(['documentTypes' => [['id' => 'dt-1']], 'defaultInformatieobjecttype' => 'iot-1'], $contents->exportComponent($export, 'documents'));
		$this->assertSame(['resultTypes' => [['id' => 'res-1']], 'decisionTypes' => ['bt-1']], $contents->exportComponent($export, 'metadata'));
		$this->assertNull($contents->exportComponent($export, 'unknown'));
	}//end testTheRowComponents()

	/**
	 * Missing case type fields fall back to an empty list or null.
	 *
	 * @return void
	 */
	public function testMissingCaseTypeFieldsFallBack(): void {
		$contents = $this->contents([]);

		$this->assertSame(['roleTypes' => [], 'rightsMatrix' => []], $contents->exportComponent(self::export(), 'permissions'));
		$this->assertSame(['documentTypes' => [], 'defaultInformatieobjecttype' => null], $contents->exportComponent(self::export(), 'documents'));
		$this->assertSame(['resultTypes' => [], 'decisionTypes' => []], $contents->exportComponent(self::export(), 'metadata'));
	}//end testMissingCaseTypeFieldsFallBack()

	/**
	 * The statuses component lists the status types and every transition of every template, tagged with its template.
	 *
	 * @return void
	 */
	public function testTransitionsCarryTheirTemplateAndSkipMalformedEntries(): void {
		$contents = $this->contents(
			[
				'status_type_schema' => [['id' => 'st-1']],
				'workflow_template_schema' => [
					['id' => 'wf-1', 'transitions' => [['from' => 'a', 'to' => 'b'], 'not-a-transition']],
					['id' => 'wf-2', 'transitions' => 'broken'],
					['id' => 'wf-3', 'transitions' => [['from' => 'b', 'to' => 'c']]],
				],
			]
		);

		$this->assertSame(
			[
				'statusTypes' => [['id' => 'st-1']],
				'transitions' => [
					['from' => 'a', 'to' => 'b', 'workflowTemplate' => 'wf-1'],
					['from' => 'b', 'to' => 'c', 'workflowTemplate' => 'wf-3'],
				],
			],
			$contents->exportComponent(self::export(), 'statuses')
		);
	}//end testTransitionsCarryTheirTemplateAndSkipMalformedEntries()

	/**
	 * Workflows are keyed by a slug of their title, the id when the title has no usable characters,
	 * the id appended on a clash, and a template with neither is left out.
	 *
	 * @return void
	 */
	public function testWorkflowsAreKeyedByTitleSlug(): void {
		$first = ['id' => 'wf-1', 'title' => 'Intake & beoordeling'];
		$clash = ['id' => 'wf-2', 'title' => 'Intake  beoordeling'];
		$untitled = ['id' => 'wf-3', 'title' => '***'];
		$anonymous = ['title' => ''];
		$contents = $this->contents(['workflow_template_schema' => [$first, $clash, $untitled, $anonymous]]);

		$this->assertSame(
			['Intake-beoordeling' => $first, 'Intake-beoordeling-wf-2' => $clash, 'wf-3' => $untitled],
			$contents->exportComponent(self::export(), 'workflows')
		);
	}//end testWorkflowsAreKeyedByTitleSlug()
}//end class
