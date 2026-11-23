<?php

/**
 * Plants the demo cases of one seeded case type.
 *
 * Each demo case is bound to its case type and its status is resolved by
 * name. A case already planted, by title, is left alone, so a case a person
 * deleted is not planted again on the next repair pass.
 * {@see CaseFlowSeedDataRepairStep} decides when the cases are due and
 * seeds the case type and its statuses first.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\Kernel\Contracts\StatusTypeLookupInterface;
use Throwable;

/**
 * Writes the seed's demo cases for one case type.
 *
 * @spec openspec/specs/case-flow-human-steps/spec.md
 */
class CaseFlowSeedCases {

	/**
	 * Constructor.
	 *
	 * @param StatusTypeLookupInterface $statuses Reads the case type's statuses, by name.
	 * @param CaseFlowSeedIndex         $index    Reads the cases already planted.
	 */
	public function __construct(
		private readonly StatusTypeLookupInterface $statuses,
		private readonly CaseFlowSeedIndex $index,
	) {
	}//end __construct()

	/**
	 * Plant the demo cases of one case type.
	 *
	 * @param CaseFlowSeedRun $run        The run; its case type becomes `$caseTypeId`.
	 * @param string          $caseTypeId The case type.
	 *
	 * @return integer The cases created.
	 *
	 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
	 * @spec openspec/specs/case-flow-human-steps/spec.md
	 */
	public function plant(CaseFlowSeedRun $run, string $caseTypeId): int {
		$run->caseTypeId = $caseTypeId;

		return $this->seedCases(
			run: $run,
			statusesByName: array_flip($this->statuses->statusesOf(caseTypeId: $caseTypeId))
		);
	}//end plant()

	/**
	 * Create the demo cases belonging to this case type.
	 *
	 * @param CaseFlowSeedRun      $run            The run, with the created case type as its `caseTypeId`.
	 * @param array<string,string> $statusesByName Status ids, keyed by name.
	 *
	 * @return integer How many cases were created.
	 *
	 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
	 * @spec openspec/specs/case-flow-human-steps/spec.md
	 */
	private function seedCases(CaseFlowSeedRun $run, array $statusesByName): int {
		$caseTypeId = $run->caseTypeId;
		$created = 0;

		$present = $this->index->caseTitlesFor(schemas: $run->schemas, caseTypeId: $caseTypeId);

		foreach (($run->data['cases'] ?? []) as $case) {
			if (is_array($case) === false) {
				continue;
			}

			if (in_array((string)($case['title'] ?? ''), $present, true) === true) {
				continue;
			}

			$case = $this->seedCaseRow(case: $case, caseTypeId: $caseTypeId, statusesByName: $statusesByName);
			$created += $this->saveSeedCase(run: $run, case: $case);
		}//end foreach

		return $created;
	}//end seedCases()

	/**
	 * The seed case as it is written: bound to the case type, its status resolved by name.
	 *
	 * @param array<string,mixed>  $case           The seed case.
	 * @param string               $caseTypeId     The created case type.
	 * @param array<string,string> $statusesByName Status ids, keyed by name.
	 *
	 * @return array<string,mixed> The case to write.
	 *
	 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
	 * @spec openspec/specs/case-flow-human-steps/spec.md
	 */
	private function seedCaseRow(array $case, string $caseTypeId, array $statusesByName): array {
		$statusName = (string)($case['status'] ?? '');
		unset($case['caseTypeSlug']);

		$case['caseType'] = $caseTypeId;

		// A case whose status name does not resolve is written WITHOUT a
		// status rather than with a dangling one: an unresolvable
		// reference reads as a real status everywhere it is displayed.
		unset($case['status']);
		if (isset($statusesByName[$statusName]) === true) {
			$case['status'] = $statusesByName[$statusName];
		}

		return $case;
	}//end seedCaseRow()

	/**
	 * Save one seed case, warning when it cannot be created.
	 *
	 * @param CaseFlowSeedRun     $run  The run.
	 * @param array<string,mixed> $case The case to write.
	 *
	 * @return integer 1 when created, 0 otherwise.
	 *
	 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
	 * @spec openspec/specs/case-flow-human-steps/spec.md
	 */
	private function saveSeedCase(CaseFlowSeedRun $run, array $case): int {
		try {
			$run->save(schemaKey: 'case', object: $case);
			return 1;
		} catch (Throwable $e) {
			$run->output->warning(
				'Case-flow seed: could not create case "' . (string)($case['title'] ?? '') . '": ' . $e->getMessage()
			);
			return 0;
		}
	}//end saveSeedCase()
}//end class
