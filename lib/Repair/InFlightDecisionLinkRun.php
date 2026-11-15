<?php

/**
 * Dossiq in-flight contract decision link run.
 *
 * One run of the in-flight contract decision link: the object service it
 * reads and writes through, the output it reports to, and the tally of what
 * became of each case.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/contract-decision-delegation/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCP\Migration\IOutput;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The state of one LinkInFlightContractDecisionsRepair run.
 *
 * @spec openspec/specs/contract-decision-delegation/spec.md
 */
final class InFlightDecisionLinkRun {

	/**
	 * How many cases ended in each outcome, by outcome.
	 *
	 * @var array<string, int>
	 */
	public array $counts = [];

	/**
	 * Constructor.
	 *
	 * @param object          $objectService The OpenRegister ObjectService.
	 * @param IOutput         $output        The migration output interface.
	 * @param LoggerInterface $logger        Logger.
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly IOutput $output,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Count one case's outcome.
	 *
	 * @param string $outcome The outcome.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/contract-decision-delegation/spec.md
	 */
	public function count(string $outcome): void {
		$this->counts[$outcome] = (($this->counts[$outcome] ?? 0) + 1);
	}//end count()

	/**
	 * Report a case type whose cases could not be listed; the run goes on with the next.
	 *
	 * @param string    $caseTypeSlug The case type slug.
	 * @param Throwable $error        Why the list failed.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/contract-decision-delegation/spec.md
	 */
	public function listFailed(string $caseTypeSlug, Throwable $error): void {
		$this->output->warning('Could not list cases for type ' . $caseTypeSlug . ': ' . $error->getMessage());
		$this->logger->warning(
			'LinkInFlightContractDecisionsRepair: list failed',
			['caseTypeSlug' => $caseTypeSlug, 'error' => $error->getMessage()]
		);
	}//end listFailed()
}//end class
