<?php

/**
 * Unit tests for CaseFlowSeedDataRepairStep.
 *
 * 🔴 THIS STEP EXISTS BECAUSE ITS ABSENCE WAS A DEFECT. `case_flow_seed_data.json`
 * shipped with NO importer at all: the file was committed, referenced, and read
 * by nothing, so the case type the flow needs never existed on a fresh install.
 * The e2e found it. Tests here therefore assert that it WRITES, and — more
 * importantly — the two rules that decide whether it can ever finish:
 *
 *   - idempotency is per OBJECT, so a run that created the case type and then
 *     failed on its cases can be completed by the next repair pass;
 *   - a case whose status name does not resolve is written WITHOUT a status,
 *     never with a dangling reference, because an unresolvable id reads as a
 *     real status everywhere it is displayed.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\CaseFlowSeedDataRepairStep;
use OCA\Dossiq\Repair\CaseFlowSeedIndex;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Transitions\StatusTypeLookup;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

class CaseFlowSeedDataRepairStepTest extends TestCase {

	/**
	 * @var array<int, array{schema: string, object: array<string,mixed>}> Writes.
	 */
	private array $saved = [];

	/**
	 * A store that records every save and hands back an id.
	 *
	 * @param string|null $failsOnSchema A schema whose writes throw, modelling a
	 *                                   partial seed.
	 * @param string      $shape         What saveObject hands back: 'array', an
	 *                                   'entity' with jsonSerialize, an 'idless'
	 *                                   row, or a 'scalar'. The store answers
	 *                                   differently across instances, and a
	 *                                   reader that understands only one shape
	 *                                   silently treats a successful write as a
	 *                                   failed one.
	 *
	 * @return object The ObjectService double.
	 */
	private function store(?string $failsOnSchema = null, string $shape = 'array'): object {
		$this->saved = [];

		return new class($this->saved, $failsOnSchema, $shape) {
			private int $n = 0;

			public function __construct(
				public array &$saved,
				private ?string $failsOnSchema,
				private string $shape,
			) {
			}

			public function saveObject(array $object, string $register, string $schema): mixed {
				if ($schema === $this->failsOnSchema) {
					throw new RuntimeException('schema rejected the object');
				}

				$this->saved[] = ['schema' => $schema, 'object' => $object];
				$this->n++;

				$saved = ($object + ['id' => $schema . '-' . $this->n]);

				if ($this->shape === 'entity') {
					return new class($saved) {
						public function __construct(private array $row) {
						}

						public function jsonSerialize(): array {
							return $this->row;
						}
					};
				}

				if ($this->shape === 'idless') {
					unset($saved['id']);
					return $saved;
				}

				if ($this->shape === 'scalar') {
					return 'not-an-object';
				}

				return $saved;
			}
		};
	}//end store()

	/**
	 * Build the step.
	 *
	 * @param object                $store    The ObjectService double.
	 * @param array<string,mixed>|null $caseType What the index reports as already
	 *                                        present, or null for a clean install.
	 * @param string[]              $cases    Case titles already present.
	 * @param array<string,string>  $statuses Statuses the lookup resolves, id => name.
	 * @param boolean               $configured Whether the register/schemas resolve.
	 *
	 * @return CaseFlowSeedDataRepairStep The step.
	 */
	private function step(
		object $store,
		?array $caseType = null,
		array $cases = [],
		array $statuses = [],
		bool $configured = true,
	): CaseFlowSeedDataRepairStep {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn(true);
		$settings->method('getObjectService')->willReturn($store);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ($configured === true ? $key : '')
		);

		$index = $this->createMock(CaseFlowSeedIndex::class);
		$index->method('caseTypeByTitle')->willReturn($caseType);
		$index->method('caseTitlesFor')->willReturn($cases);

		$lookup = $this->createMock(StatusTypeLookup::class);
		$lookup->method('statusesOf')->willReturn($statuses);

		return new CaseFlowSeedDataRepairStep($settings, $lookup, $index, new NullLogger());
	}//end step()

	/**
	 * Saves made against one schema.
	 *
	 * @param string $schema The schema name.
	 *
	 * @return array<int, array<string,mixed>> The objects written.
	 */
	private function savedTo(string $schema): array {
		return array_column(
			array_values(array_filter($this->saved, static fn (array $s): bool => $s['schema'] === $schema)),
			'object'
		);
	}//end savedTo()

	public function testItNamesItselfForTheRepairRunner(): void {
		$name = $this->step($this->store())->getName();

		$this->assertStringContainsString('case flow', strtolower($name));
	}//end testItNamesItselfForTheRepairRunner()

	/**
	 * On a clean install it writes the case type, its statuses and its cases.
	 *
	 * The seed read here is the SHIPPED file, not a fixture: a test over an
	 * invented seed would pass while the real one was malformed.
	 */
	public function testACleanInstallGetsTheCaseTypeItsStatusesAndItsCases(): void {
		$step = $this->step($this->store());
		$step->run($this->createMock(IOutput::class));

		$this->assertNotSame([], $this->savedTo('case_type_schema'), 'the case type must be created');
		$this->assertNotSame([], $this->savedTo('status_type_schema'), 'its statuses must be created');
		$this->assertNotSame([], $this->savedTo('case_schema'), 'its demo cases must be created');
	}//end testACleanInstallGetsTheCaseTypeItsStatusesAndItsCases()

	/**
	 * 🔴 EVERY STATUS CARRIES THE `caseType` BACK-REFERENCE.
	 *
	 * It is the only link the status lookup can follow — `caseType` has no
	 * `statusTypes` property — so a status written without it is invisible to
	 * the flow, and every status move then refuses.
	 */
	public function testEveryStatusCarriesTheCaseTypeBackReference(): void {
		$step = $this->step($this->store());
		$step->run($this->createMock(IOutput::class));

		$statuses = $this->savedTo('status_type_schema');
		$this->assertNotSame([], $statuses);
		foreach ($statuses as $status) {
			$this->assertArrayHasKey('caseType', $status);
			$this->assertNotSame('', (string)$status['caseType']);
		}
	}//end testEveryStatusCarriesTheCaseTypeBackReference()

	/**
	 * The case type is not written as carrying its own statuses.
	 */
	public function testTheCaseTypeIsNotWrittenWithAStatusTypesProperty(): void {
		$step = $this->step($this->store());
		$step->run($this->createMock(IOutput::class));

		$this->assertArrayNotHasKey('statusTypes', $this->savedTo('case_type_schema')[0]);
	}//end testTheCaseTypeIsNotWrittenWithAStatusTypesProperty()

	/**
	 * 🔴 IDEMPOTENCY IS PER OBJECT, NOT PER CASE TYPE.
	 *
	 * With the case type already present but no statuses and no cases, a run must
	 * still create those. Keying the whole seed on "does the case type exist"
	 * means a run that got that far and then failed can NEVER complete.
	 */
	public function testAnExistingCaseTypeStillGetsItsMissingStatusesAndCases(): void {
		$step = $this->step($this->store(), caseType: ['id' => 'ct-existing']);
		$step->run($this->createMock(IOutput::class));

		$this->assertSame([], $this->savedTo('case_type_schema'), 'it must not be created twice');
		$this->assertNotSame([], $this->savedTo('status_type_schema'), 'its missing statuses must still arrive');
		$this->assertNotSame([], $this->savedTo('case_schema'), 'its missing cases must still arrive');
	}//end testAnExistingCaseTypeStillGetsItsMissingStatusesAndCases()

	/**
	 * A status that already exists is not created again.
	 */
	public function testAStatusThatAlreadyExistsIsNotRecreated(): void {
		$clean = $this->step($this->store());
		$clean->run($this->createMock(IOutput::class));
		$names = array_map(static fn (array $s): string => (string)$s['name'], $this->savedTo('status_type_schema'));

		$this->assertNotSame([], $names);

		$again = $this->step(
			$this->store(),
			caseType: ['id' => 'ct-existing'],
			statuses: array_combine(
				array_map(static fn (int $i): string => 'st-' . $i, array_keys($names)),
				$names
			)
		);
		$again->run($this->createMock(IOutput::class));

		$this->assertSame([], $this->savedTo('status_type_schema'), 'all statuses were already present');
	}//end testAStatusThatAlreadyExistsIsNotRecreated()

	/**
	 * A case whose title is already present is not created again.
	 */
	public function testACaseThatAlreadyExistsIsNotRecreated(): void {
		$clean = $this->step($this->store());
		$clean->run($this->createMock(IOutput::class));
		$titles = array_map(static fn (array $c): string => (string)$c['title'], $this->savedTo('case_schema'));

		$this->assertNotSame([], $titles);

		$again = $this->step($this->store(), caseType: ['id' => 'ct-existing'], cases: $titles);
		$again->run($this->createMock(IOutput::class));

		$this->assertSame([], $this->savedTo('case_schema'));
	}//end testACaseThatAlreadyExistsIsNotRecreated()

	/**
	 * 🔴 AN UNRESOLVABLE STATUS NAME LEAVES THE CASE WITHOUT A STATUS.
	 *
	 * Writing the raw name would produce a reference that resolves to nothing
	 * but reads as a real status in every panel that displays it.
	 */
	public function testACaseWithAnUnresolvableStatusIsWrittenWithoutOne(): void {
		// The lookup resolves nothing, so no case's status name can be matched.
		$step = $this->step($this->store(), caseType: ['id' => 'ct-1'], statuses: []);
		$step->run($this->createMock(IOutput::class));

		$cases = $this->savedTo('case_schema');
		$this->assertNotSame([], $cases);
		foreach ($cases as $case) {
			$this->assertArrayNotHasKey('status', $case, 'a dangling status is worse than none');
		}
	}//end testACaseWithAnUnresolvableStatusIsWrittenWithoutOne()

	/**
	 * A case type whose cases cannot be written is reported, not fatal — and the
	 * case type it did create stays, so the next pass finishes the rest.
	 */
	public function testAFailedCaseWriteIsReportedAndNotFatal(): void {
		$step = $this->step($this->store(failsOnSchema: 'case_schema'));

		$output = $this->createMock(IOutput::class);
		$output->expects($this->atLeastOnce())->method('warning');

		$step->run($output);

		$this->assertNotSame([], $this->savedTo('case_type_schema'), 'the work that succeeded is kept');
	}//end testAFailedCaseWriteIsReportedAndNotFatal()

	/**
	 * With the register or schemas unconfigured it writes nothing and says so.
	 */
	public function testAnUnconfiguredInstallSeedsNothing(): void {
		$step = $this->step($this->store(), configured: false);

		$output = $this->createMock(IOutput::class);
		$output->expects($this->atLeastOnce())->method('warning');

		$step->run($output);

		$this->assertSame([], $this->saved);
	}//end testAnUnconfiguredInstallSeedsNothing()

	/**
	 * With OpenRegister absent it writes nothing rather than throwing: a repair
	 * step that fails blocks the whole upgrade.
	 */
	public function testWithoutOpenRegisterItSkipsRatherThanThrows(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn(false);
		$settings->method('getObjectService')->willReturn(null);

		$step = new CaseFlowSeedDataRepairStep(
			$settings,
			$this->createMock(StatusTypeLookup::class),
			$this->createMock(CaseFlowSeedIndex::class),
			new NullLogger()
		);

		$output = $this->createMock(IOutput::class);
		$output->expects($this->atLeastOnce())->method('warning');

		$step->run($output);
	}//end testWithoutOpenRegisterItSkipsRatherThanThrows()

	/**
	 * A case type the store saves without an id stops that branch cleanly.
	 *
	 * Its statuses would otherwise be written with an empty `caseType`, which is
	 * the exact shape that makes them invisible to the status lookup.
	 */
	public function testACaseTypeSavedWithoutAnIdSkipsItsStatuses(): void {
		$step = $this->step($this->store(shape: 'idless'));

		$output = $this->createMock(IOutput::class);
		$output->expects($this->atLeastOnce())->method('warning');

		$step->run($output);

		$this->assertSame([], $this->savedTo('status_type_schema'));
	}//end testACaseTypeSavedWithoutAnIdSkipsItsStatuses()

	/**
	 * An entity return value is read the same as a plain array.
	 */
	public function testAnEntityReturnValueIsUnwrappedForItsId(): void {
		$step = $this->step($this->store(shape: 'entity'));
		$step->run($this->createMock(IOutput::class));

		$statuses = $this->savedTo('status_type_schema');
		$this->assertNotSame([], $statuses, 'the case type id was read out of the entity');
		$this->assertNotSame('', (string)$statuses[0]['caseType']);
	}//end testAnEntityReturnValueIsUnwrappedForItsId()

	/**
	 * A store that answers with something that is not an object at all is
	 * treated as "no id", not as a crash.
	 */
	public function testAScalarReturnValueIsTreatedAsNoId(): void {
		$step = $this->step($this->store(shape: 'scalar'));

		$output = $this->createMock(IOutput::class);
		$output->expects($this->atLeastOnce())->method('warning');

		$step->run($output);

		$this->assertSame([], $this->savedTo('status_type_schema'));
	}//end testAScalarReturnValueIsTreatedAsNoId()

	/**
	 * 🔴 ONE CASE TYPE FAILING MUST NOT STOP THE OTHERS.
	 *
	 * The per-case-type catch is what makes a partial seed recoverable: the
	 * failure is reported and logged, the run continues, and the next repair
	 * pass finishes what is missing.
	 */
	public function testACaseTypeThatFailsEntirelyIsReportedAndTheRunContinues(): void {
		$step = $this->step($this->store(failsOnSchema: 'case_type_schema'));

		$output = $this->createMock(IOutput::class);
		$output->expects($this->atLeastOnce())->method('warning');

		$step->run($output);

		$this->assertSame([], $this->saved, 'nothing was written, and nothing threw out of run()');
	}//end testACaseTypeThatFailsEntirelyIsReportedAndTheRunContinues()

	/**
	 * With OpenRegister reporting available but no ObjectService, it skips.
	 *
	 * These are separate reads on SettingsService, and only the second one is
	 * the object the seed actually writes through.
	 */
	public function testAvailableOpenRegisterWithNoObjectServiceStillSkips(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn(true);
		$settings->method('getObjectService')->willReturn(null);

		$step = new CaseFlowSeedDataRepairStep(
			$settings,
			$this->createMock(StatusTypeLookup::class),
			$this->createMock(CaseFlowSeedIndex::class),
			new NullLogger()
		);

		$output = $this->createMock(IOutput::class);
		$output->expects($this->atLeastOnce())->method('warning');

		$step->run($output);
	}//end testAvailableOpenRegisterWithNoObjectServiceStillSkips()
}//end class
