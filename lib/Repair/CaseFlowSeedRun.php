<?php

/**
 * One run of the case-flow seed.
 *
 * Built by `CaseFlowSeedDataRepairStep::run()` and never handed out: the
 * object service it writes through, the register and schemas it writes to,
 * the seed it writes, where it reports, and, as it goes, the tally, whether
 * demo cases are due and whether every case type was seeded.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/case-flow-human-steps/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCP\Migration\IOutput;

/**
 * What one seed run writes, reports and counts with.
 *
 * @spec openspec/specs/case-flow-human-steps/spec.md
 */
final class CaseFlowSeedRun {

	/**
	 * Case types, statuses and cases created, and items already present.
	 *
	 * @var array{caseTypes: int, statuses: int, cases: int, skipped: int}
	 */
	public array $counts = ['caseTypes' => 0, 'statuses' => 0, 'cases' => 0, 'skipped' => 0];

	/**
	 * Whether the demo cases are due on this instance.
	 *
	 * @var boolean
	 */
	public bool $withCases = false;

	/**
	 * Whether every case type was seeded without a failure.
	 *
	 * @var boolean
	 */
	public bool $complete = true;

	/**
	 * Constructor.
	 *
	 * @param object                $objectService OpenRegister's object service.
	 * @param array<string, string> $schemas       The register and the three schemas.
	 * @param array<string, mixed>  $data          The seed.
	 * @param IOutput               $output        Output sink.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly array $schemas,
		public readonly array $data,
		public readonly IOutput $output,
	) {
	}//end __construct()

	/**
	 * Whether the demo cases were due and every case type was seeded, so the seed is recorded.
	 *
	 * @return boolean
	 *
	 * @spec openspec/specs/case-flow-human-steps/spec.md
	 */
	public function casesSeeded(): bool {
		return $this->withCases === true && $this->complete === true;
	}//end casesSeeded()

	/**
	 * What the run created and what was already present, as one line.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/case-flow-human-steps/spec.md
	 */
	public function summary(): string {
		return sprintf(
			'Case-flow seed: %d case type(s), %d status(es), %d case(s) created; %d already present.',
			$this->counts['caseTypes'],
			$this->counts['statuses'],
			$this->counts['cases'],
			$this->counts['skipped']
		);
	}//end summary()
}//end class
