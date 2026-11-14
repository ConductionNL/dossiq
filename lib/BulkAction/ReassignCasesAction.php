<?php

/**
 * Dossiq bulk action: give many cases to another handler.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category BulkAction
 * @package  OCA\Dossiq\BulkAction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\BulkAction;

use InvalidArgumentException;
use OCA\Dossiq\Kernel\Contracts\CaseAssigneeWriterInterface;
use OCA\OpenRegister\BulkAction\BulkActionInterface;
use OCA\OpenRegister\BulkAction\BulkActionResult;
use OCA\OpenRegister\BulkAction\BulkStep;
use OCP\IL10N;
use Throwable;

/**
 * One redistribution, over every case the job selected.
 *
 * This is the one action behind both gestures that move a caseload: a
 * coordinator redistributing a selection, and a coordinator releasing the work
 * of a handler who left or is on leave. They differ in how the selection is
 * built and in nothing else, which is why they share an action rather than
 * each growing a loop (D-6).
 *
 * The justification is required here rather than by OpenRegister, because
 * OpenRegister's job does not know that reassigning four hundred statutory
 * cases is a thing a coordinator has to explain. It is a case policy, so it is
 * dossiq's (D-3).
 *
 * @spec openspec/specs/case-management/spec.md
 *
 * @SuppressWarnings(PHPMD.StaticAccess) `BulkActionResult` is a value object
 * whose constructor is PRIVATE: `applied()`, `skipped()`, `refused()` and
 * `failed()` are its only constructors, and they are static by OpenRegister's
 * design so the four outcomes read as four named things rather than as four
 * flags. There is no instance to call, so the rule cannot be satisfied here.
 */
class ReassignCasesAction implements BulkActionInterface {

	use ReadsCaseObject;

	/**
	 * The action id every caller names.
	 *
	 * @var string
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public const ID = 'dossiq:reassign-cases';

	/**
	 * Constructor.
	 *
	 * @param CaseAssigneeWriterInterface $writer The single-case write and its audit entry.
	 * @param IL10N              $l10n   Localisation, for the label an operator reads.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function __construct(
		private readonly CaseAssigneeWriterInterface $writer,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * The action id.
	 *
	 * @return string The id.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function getId(): string {
		return self::ID;
	}//end getId()

	/**
	 * The label.
	 *
	 * @return string The label.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function getLabel(): string {
		return $this->l10n->t('Give the cases to another handler');
	}//end getLabel()

	/**
	 * What the action does.
	 *
	 * @return string The description.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function getDescription(): string {
		return $this->l10n->t('Moves every selected case to one handler, recording on each case who it came from.');
	}//end getDescription()

	/**
	 * Reassigning cases in bulk needs a written reason.
	 *
	 * @return bool True.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function requiresJustification(): bool {
		return true;
	}//end requiresJustification()

	/**
	 * A redistribution reads no case-type attribute, so the homogeneity guard
	 * would refuse a selection it has no reason to refuse.
	 *
	 * @return array<int, string> No guards.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function getGuards(): array {
		return [];
	}//end getGuards()

	/**
	 * A receiving handler and a non-empty reason are required.
	 *
	 * @param array<string, mixed> $parameters The job parameters.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When either is missing.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function validateParameters(array $parameters): void {
		if (trim((string)($parameters['toUser'] ?? '')) === '') {
			throw new InvalidArgumentException('toUser is required');
		}

		if (trim((string)($parameters['reason'] ?? '')) === '') {
			throw new InvalidArgumentException('reason is required');
		}
	}//end validateParameters()

	/**
	 * Move one case, or say whether moving it is work at all.
	 *
	 * @param BulkStep $step The member object, the parameters, whether to commit and who started the job.
	 *
	 * @return BulkActionResult What happened, or what would.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function apply(BulkStep $step): BulkActionResult {
		$caseId = $this->caseId(object: $step->object);
		if ($caseId === '') {
			return BulkActionResult::skipped(reason: 'no_case_id');
		}

		$caseData = $this->caseData(object: $step->object);
		$toUser = trim((string)($step->parameters['toUser'] ?? ''));

		// Not a failure, and not work either. Rewriting the row would stamp an
		// audit entry saying a case moved from somebody to themselves.
		if (trim((string)($caseData['assignee'] ?? '')) === $toUser) {
			return BulkActionResult::skipped(reason: 'already_assigned');
		}

		if ($step->commit === false) {
			return BulkActionResult::applied();
		}

		return $this->reassign(step: $step, caseId: $caseId, caseData: $caseData, toUser: $toUser);
	}//end apply()

	/**
	 * Write the reassignment of one case.
	 *
	 * @param BulkStep             $step     The member object, the parameters, whether to commit and who started the job.
	 * @param string               $caseId   The case.
	 * @param array<string, mixed> $caseData The case's data.
	 * @param string               $toUser   Who receives the case.
	 *
	 * @return BulkActionResult What happened.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	private function reassign(BulkStep $step, string $caseId, array $caseData, string $toUser): BulkActionResult {
		try {
			$actorId = ($step->actor?->getUID() ?? '');
			$batchId = (string)($step->parameters['batchId'] ?? $this->writer->newBatchId());
			$written = $this->writer->reassignOne(caseId: $caseId, case: $caseData, toUser: $toUser, actorId: $actorId, batchId: $batchId);
		} catch (Throwable $e) {
			return BulkActionResult::failed(message: $e->getMessage());
		}

		if ($written === false) {
			return BulkActionResult::failed(message: 'reassignment_write_failed');
		}

		return BulkActionResult::applied();
	}//end reassign()
}//end class
