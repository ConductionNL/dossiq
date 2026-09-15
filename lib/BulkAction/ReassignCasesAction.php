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
use OCA\Dossiq\Service\Support\CaseAssigneeWriter;
use OCA\OpenRegister\BulkAction\BulkActionInterface;
use OCA\OpenRegister\BulkAction\BulkActionResult;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IL10N;
use OCP\IUser;
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
 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
 */
class ReassignCasesAction implements BulkActionInterface {

	use ReadsCaseObject;

	/**
	 * The action id every caller names.
	 *
	 * @var string
	 *
	 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
	 */
	public const ID = 'dossiq:reassign-cases';

	/**
	 * Constructor.
	 *
	 * @param CaseAssigneeWriter $writer The single-case write and its audit entry.
	 * @param IL10N              $l10n   Localisation, for the label an operator reads.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
	 */
	public function __construct(
		private readonly CaseAssigneeWriter $writer,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * The action id.
	 *
	 * @return string The id.
	 *
	 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
	 */
	public function getId(): string {
		return self::ID;
	}//end getId()

	/**
	 * The label.
	 *
	 * @return string The label.
	 *
	 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
	 */
	public function getLabel(): string {
		return $this->l10n->t('Give the cases to another handler');
	}//end getLabel()

	/**
	 * What the action does.
	 *
	 * @return string The description.
	 *
	 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
	 */
	public function getDescription(): string {
		return $this->l10n->t('Moves every selected case to one handler, recording on each case who it came from.');
	}//end getDescription()

	/**
	 * Reassigning cases in bulk needs a written reason.
	 *
	 * @return bool True.
	 *
	 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
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
	 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
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
	 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
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
	 * @param ObjectEntity         $object     The case the job is walking.
	 * @param array<string, mixed> $parameters The job parameters.
	 * @param bool                 $commit     False to rehearse, true to write.
	 * @param IUser|null           $actor      The user the job runs as.
	 *
	 * @return BulkActionResult What happened, or what would.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) One executor for the
	 * rehearsal and the commit is the design property of D-1.
	 *
	 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
	 */
	public function apply(ObjectEntity $object, array $parameters, bool $commit, ?IUser $actor = null): BulkActionResult {
		$caseId = $this->caseId(object: $object);
		if ($caseId === '') {
			return BulkActionResult::skipped(reason: 'no_case_id');
		}

		$case = $this->caseData(object: $object);
		$toUser = trim((string)($parameters['toUser'] ?? ''));

		// Not a failure, and not work either. Rewriting the row would stamp an
		// audit entry saying a case moved from somebody to themselves.
		if (trim((string)($case['assignee'] ?? '')) === $toUser) {
			return BulkActionResult::skipped(reason: 'already_assigned');
		}

		if ($commit === false) {
			return BulkActionResult::applied();
		}

		try {
			$written = $this->writer->reassignOne(
				caseId: $caseId,
				case: $case,
				toUser: $toUser,
				actorId: ($actor?->getUID() ?? ''),
				batchId: (string)($parameters['batchId'] ?? $this->writer->newBatchId()),
			);
		} catch (Throwable $e) {
			return BulkActionResult::failed(message: $e->getMessage());
		}

		if ($written === false) {
			return BulkActionResult::failed(message: 'reassignment_write_failed');
		}

		return BulkActionResult::applied();
	}//end apply()
}//end class
