<?php

/**
 * Unit tests for CaseFlowSeedCases and the run's save.
 *
 * The demo cases of one case type: bound to the case type, the status
 * resolved by name or left out, a case already planted skipped, and a failed
 * write reported without stopping the others.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
 *
 * @covers \OCA\Dossiq\Repair\CaseFlowSeedCases
 * @covers \OCA\Dossiq\Repair\CaseFlowSeedRun
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\CaseFlowSeedCases;
use OCA\Dossiq\Repair\CaseFlowSeedIndex;
use OCA\Dossiq\Repair\CaseFlowSeedRun;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Transitions\StatusTypeLookup;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class CaseFlowSeedCasesTest extends TestCase {

	/**
	 * A store that records each save with its register and schema, and refuses one title.
	 *
	 * @param string $refusedTitle A case title whose save throws.
	 *
	 * @return object The ObjectService double.
	 */
	private function store(string $refusedTitle = ''): object {
		return new class($refusedTitle) {
			/**
			 * @var array<int, array{register: string, schema: string, object: array<string,mixed>}>
			 */
			public array $saved = [];

			/**
			 * Constructor.
			 *
			 * @param string $refusedTitle A case title whose save throws.
			 */
			public function __construct(private string $refusedTitle) {
			}

			/**
			 * Record one save, or refuse it.
			 *
			 * @param array<string,mixed> $object        The object written.
			 * @param ObjectSaveOptions   $options       Where it is written.
			 * @param boolean             $_rbac         Unused.
			 * @param boolean             $_multitenancy Unused.
			 *
			 * @return mixed The object with an id.
			 */
			public function saveObject(
				array $object,
				ObjectSaveOptions $options = new ObjectSaveOptions(),
				bool $_rbac = true,
				bool $_multitenancy = true,
			): mixed {
				if (($object['title'] ?? null) === $this->refusedTitle) {
					throw new RuntimeException('schema rejected the object');
				}

				$this->saved[] = ['register' => $options->target->register, 'schema' => $options->target->schema, 'object' => $object];

				return $object + ['id' => 'id-' . count($this->saved)];
			}
		};
	}//end store()

	/**
	 * A run over the store, with the seed's cases.
	 *
	 * @param object                          $store  The ObjectService double.
	 * @param array<int,mixed>                $cases  The seed's cases.
	 * @param IOutput|null                    $output Where the run reports.
	 *
	 * @return CaseFlowSeedRun The run.
	 */
	private function seedRun(object $store, array $cases, ?IOutput $output = null): CaseFlowSeedRun {
		return new CaseFlowSeedRun(
			objectService: $store,
			schemas: ['register' => 'dossiq', 'caseType' => 'case_type', 'statusType' => 'status_type', 'case' => 'case_schema'],
			data: ['cases' => $cases],
			output: $output ?? $this->createMock(originalClassName: IOutput::class),
		);
	}//end run()

	/**
	 * The planter, with the statuses the case type has and the case titles already planted.
	 *
	 * @param array<string,string> $statuses Status id => name.
	 * @param string[]             $present  Case titles already planted.
	 *
	 * @return CaseFlowSeedCases The planter.
	 */
	private function cases(array $statuses = [], array $present = []): CaseFlowSeedCases {
		$index = $this->getMockBuilder(className: CaseFlowSeedIndex::class)
			->setConstructorArgs([$this->createMock(originalClassName: SettingsService::class)])
			->onlyMethods(['caseTitlesFor'])
			->getMock();
		$index->method('caseTitlesFor')->willReturn($present);

		$lookup = $this->createMock(originalClassName: StatusTypeLookup::class);
		$lookup->method('statusesOf')->willReturn($statuses);

		return new CaseFlowSeedCases(statuses: $lookup, index: $index);
	}//end cases()

	/**
	 * A case is bound to its case type; a status name that resolves is its status, one that does not is left out.
	 *
	 * @return void
	 */
	public function testEachCaseIsBoundToItsCaseTypeWithItsStatusResolvedByName(): void {
		$store = $this->store();
		$run = $this->seedRun(store: $store, cases: [
			['title' => 'Aanvraag A', 'status' => 'Ontvangen', 'caseTypeSlug' => 'aanvraag'],
			['title' => 'Aanvraag B', 'status' => 'Onbekend'],
			'not a case',
		]);

		$created = $this->cases(statuses: ['st-1' => 'Ontvangen'])->plant(run: $run, caseTypeId: 'ct-1');

		$this->assertSame(expected: 2, actual: $created);
		$this->assertSame(expected: 'ct-1', actual: $run->caseTypeId);
		$this->assertSame(
			expected: [
				['register' => 'dossiq', 'schema' => 'case_schema', 'object' => ['title' => 'Aanvraag A', 'caseType' => 'ct-1', 'status' => 'st-1']],
				['register' => 'dossiq', 'schema' => 'case_schema', 'object' => ['title' => 'Aanvraag B', 'caseType' => 'ct-1']],
			],
			actual: $store->saved
		);
	}//end testEachCaseIsBoundToItsCaseTypeWithItsStatusResolvedByName()

	/**
	 * A case planted before is skipped by title, and a failed write is reported while the others are still planted.
	 *
	 * @return void
	 */
	public function testACasePlantedBeforeIsSkippedAndAFailedWriteIsReported(): void {
		$store = $this->store(refusedTitle: 'Aanvraag C');
		$output = $this->createMock(originalClassName: IOutput::class);
		$output->expects($this->once())->method('warning')
			->with('Case-flow seed: could not create case "Aanvraag C": schema rejected the object');
		$run = $this->seedRun(store: $store, cases: [['title' => 'Aanvraag A'], ['title' => 'Aanvraag C'], ['title' => 'Aanvraag D']], output: $output);

		$created = $this->cases(present: ['Aanvraag A'])->plant(run: $run, caseTypeId: 'ct-1');

		$this->assertSame(expected: 1, actual: $created);
		$this->assertSame(
			expected: ['Aanvraag D'],
			actual: array_map(static fn (array $s): string => $s['object']['title'], $store->saved)
		);
	}//end testACasePlantedBeforeIsSkippedAndAFailedWriteIsReported()

	/**
	 * The run writes to its register under the schema it is asked for.
	 *
	 * @return void
	 */
	public function testTheRunSavesUnderTheSchemaItIsAskedFor(): void {
		$store = $this->store();
		$run = $this->seedRun(store: $store, cases: []);

		$saved = $run->save(schemaKey: 'statusType', object: ['name' => 'Ontvangen']);

		$this->assertSame(expected: ['name' => 'Ontvangen', 'id' => 'id-1'], actual: $saved);
		$this->assertSame(
			expected: [['register' => 'dossiq', 'schema' => 'status_type', 'object' => ['name' => 'Ontvangen']]],
			actual: $store->saved
		);
	}//end testTheRunSavesUnderTheSchemaItIsAskedFor()
}//end class
