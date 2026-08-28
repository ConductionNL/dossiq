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
	 *
	 * @return object The ObjectService double.
	 */
	private function store(?string $failsOnSchema = null): object {
		$this->saved = [];

		return new class($this->saved, $failsOnSchema) {
			private int $n = 0;

			public function __construct(
				public array &$saved,
				private ?string $failsOnSchema,
			) {
			}

			public function saveObject(array $object, string $register, string $schema): array {
				if ($schema === $this->failsOnSchema) {
					throw new RuntimeException('schema rejected the object');
				}

				$this->saved[] = ['schema' => $schema, 'object' => $object];
				$this->n++;

				return ($object + ['id' => $schema . '-' . $this->n]);
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
}//end class
