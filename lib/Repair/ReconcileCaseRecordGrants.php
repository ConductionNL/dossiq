<?php

/**
 * Dossiq repair step: reconcile the grants of every case's private records
 *
 * A record a case type keeps private to the case is read by its owner, its
 * owning group and whoever holds a grant. A record made before its schema
 * became private, or before its case type changed who reads it, carries no
 * grant or the wrong one, and the people on the case are refused until
 * somebody reconciles it. This step does that on the upgrade itself, so no
 * instance waits for an administrator to call a route
 * (case-reviewer-access, REQ-CRA-012).
 *
 * It runs CaseRecordGrantsBackfill, the walk the administrator route runs.
 * Running it again changes nothing on a record that is already right. It
 * never throws: a failed upgrade step would hold the whole upgrade. What it
 * could not do it says as a warning in the upgrade output and as an error in
 * the log, with the count and the cases, and never as a success.
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
 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-existing-records-are-reconciled-on-upgrade-req-cra-012
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\Service\Access\CaseRecordGrantsBackfill;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reconciles the grants of every case's private records on upgrade.
 *
 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-existing-records-are-reconciled-on-upgrade-req-cra-012
 */
class ReconcileCaseRecordGrants implements IRepairStep {

	/**
	 * The route an administrator finishes the work with.
	 */
	public const FALLBACK = 'POST /index.php/apps/dossiq/api/cases/reviewer-access/reconcile';

	/**
	 * The most case ids one message names.
	 */
	private const NAMED = 20;

	/**
	 * Constructor.
	 *
	 * @param CaseRecordGrantsBackfill $backfill The walk over every declaring case.
	 * @param LoggerInterface          $logger   The log an unfinished run is written to.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly CaseRecordGrantsBackfill $backfill,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-existing-records-are-reconciled-on-upgrade-req-cra-012
	 */
	public function getName(): string {
		return 'Give the people on each case their access to its private records';
	}//end getName()

	/**
	 * Reconcile every declaring case and say what was done and what was not.
	 *
	 * @param IOutput $output The repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-existing-records-are-reconciled-on-upgrade-req-cra-012
	 */
	public function run(IOutput $output): void {
		try {
			$answer = $this->backfill->run();
		} catch (Throwable $e) {
			$this->unfinished(
				output: $output,
				message: 'Case record access: the reconcile stopped on an error and did NOT finish ('.$e->getMessage().').',
				context: ['exception' => $e->getMessage()]
			);
			return;
		}

		$counts = $answer['cases'].' cases, '.$answer['granted'].' grants added, '.$answer['revoked'].' revoked, '.$answer['refused'].' refused';
		if ($answer['status'] === 'reconciled') {
			$output->info('Case record access: reconciled as '.$answer['actor'].': '.$counts.'.');
			return;
		}

		$this->unfinished(output: $output, message: $this->sentenceFor(answer: $answer, counts: $counts), context: $answer);
	}//end run()

	/**
	 * The sentence for a run that did not reconcile everything.
	 *
	 * @param array<string, mixed> $answer The backfill's answer.
	 * @param string               $counts The counts, as a phrase.
	 *
	 * @return string
	 */
	private function sentenceFor(array $answer, string $counts): string {
		if ($answer['status'] === 'no-administrator') {
			return 'Case record access: NOT reconciled. No enabled administrator account exists to write the grants as.';
		}

		if ($answer['status'] === 'unavailable') {
			return 'Case record access: NOT reconciled. This OpenRegister has no per-object sharing, so no grant was written.';
		}

		$failed = (array)$answer['failed'];
		$sentence = 'Case record access: NOT finished ('.$counts.').';
		if ($failed !== []) {
			$sentence .= ' '.count($failed).' cases were not fully reconciled: '.implode(', ', array_slice($failed, 0, self::NAMED));
			if (count($failed) > self::NAMED) {
				$sentence .= ' and '.(count($failed) - self::NAMED).' more';
			}

			$sentence .= '.';
		}

		if ((array)$answer['unfinished'] !== []) {
			$sentence .= ' Case types with more cases than one run walks: '.implode(', ', (array)$answer['unfinished']).'.';
		}

		return $sentence;
	}//end sentenceFor()

	/**
	 * Say an unfinished run where an administrator reads it: the upgrade output and the log.
	 *
	 * @param IOutput              $output  The repair output.
	 * @param string               $message What was not done.
	 * @param array<string, mixed> $context The answer, for the log.
	 *
	 * @return void
	 */
	private function unfinished(IOutput $output, string $message, array $context): void {
		$message .= ' Until it is, people on those cases can be refused their own records. Sign in as an administrator and call '.self::FALLBACK.'.';
		$output->warning($message);
		$this->logger->error('Dossiq '.$message, $context);
	}//end unfinished()
}//end class
