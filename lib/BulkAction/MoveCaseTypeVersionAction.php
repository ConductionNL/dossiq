<?php

/**
 * Dossiq bulk action: move many running cases onto another version of their
 * case type.
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
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseType\CaseVersionMove;
use OCA\OpenRegister\BulkAction\BulkActionInterface;
use OCA\OpenRegister\BulkAction\BulkActionResult;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IL10N;
use OCP\IUser;
use Throwable;

/**
 * The bulk variant of the per-case move, and the same act underneath.
 *
 * 🔴 IT CALLS {@see CaseVersionMove}, IT DOES NOT REIMPLEMENT IT. A bulk
 * gesture that computes its own mapping is a second answer to "which status
 * does this case land in", and the two would drift the first time either side
 * changed: the single-case dialog would refuse a case the job had already
 * moved, or the other way round, and nothing on either side would say which
 * one was right. A correction published against a hundred running cases is
 * exactly the moment you cannot afford two answers.
 *
 * WHAT THE REHEARSAL IS WORTH HERE. The rehearsal asks the same service for the
 * preview, so a case whose status the target version does not carry is reported
 * REFUSED with the status named, before a single case is written. That is the
 * whole point of the dry run for this act: a mixed selection where nine cases
 * map and one does not is the normal case, not the exception, and the operator
 * finds out which one before deciding.
 *
 * A reason is required. Moving a running case is the deliberate exception to
 * REQ-ZV-02, and one performed across a selection with no reason recorded is
 * unauditable afterwards.
 *
 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
 *
 * @SuppressWarnings(PHPMD.StaticAccess) `BulkActionResult` is a value object
 * whose constructor is PRIVATE: `applied()`, `skipped()`, `refused()` and
 * `failed()` are its only constructors, and they are static by OpenRegister's
 * design so the four outcomes read as four named things rather than as four
 * flags. There is no instance to call, so the rule cannot be satisfied here.
 */
class MoveCaseTypeVersionAction implements BulkActionInterface {

	use ReadsCaseObject;

	/**
	 * The action id every caller names.
	 *
	 * @var string
	 *
	 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
	 */
	public const ID = 'dossiq:move-case-type-version';

	/**
	 * Constructor.
	 *
	 * @param CaseVersionMove $move The one answer to what a move changes.
	 * @param IL10N           $l10n Localisation, for the label an operator reads.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
	 */
	public function __construct(
		private readonly CaseVersionMove $move,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * The action id.
	 *
	 * @return string The id.
	 *
	 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
	 */
	public function getId(): string {
		return self::ID;
	}//end getId()

	/**
	 * The label.
	 *
	 * @return string The label.
	 *
	 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
	 */
	public function getLabel(): string {
		return $this->l10n->t('Move the cases to another version of their case type');
	}//end getLabel()

	/**
	 * What the action does.
	 *
	 * @return string The description.
	 *
	 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
	 */
	public function getDescription(): string {
		return $this->l10n->t(
			'Moves every selected case onto the named version of its own case type, refusing a case whose status that version does not have.'
		);
	}//end getDescription()

	/**
	 * Moving a running case off the version it was filed under owes a reason.
	 *
	 * @return bool True.
	 *
	 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
	 */
	public function requiresJustification(): bool {
		return true;
	}//end requiresJustification()

	/**
	 * One version in, one version out, so the selection must be one version.
	 *
	 * The target is a single case type id, so a selection spanning two versions
	 * would move half the cases forward and refuse the other half for already
	 * being there. The homogeneity guard says so before the rehearsal.
	 *
	 * @return array<int, string> The homogeneity guard.
	 *
	 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
	 */
	public function getGuards(): array {
		return [BulkActionInterface::GUARD_HOMOGENEITY];
	}//end getGuards()

	/**
	 * A target version and a written reason are required.
	 *
	 * The reason is a PARAMETER and not only the job's justification, the way
	 * `ReassignCasesAction` spells it: the action is refused the same whichever
	 * caller hands it over, including one that is not dossiq's own endpoint.
	 *
	 * @param array<string, mixed> $parameters The job parameters.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When no target version or no reason is named.
	 *
	 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
	 */
	public function validateParameters(array $parameters): void {
		if (trim((string)($parameters['target'] ?? '')) === '') {
			throw new InvalidArgumentException('target is required: name the case type version to move onto');
		}

		if (trim((string)($parameters['reason'] ?? '')) === '') {
			throw new InvalidArgumentException('reason is required');
		}
	}//end validateParameters()

	/**
	 * Rehearse or perform the move for one case.
	 *
	 * The rehearsal answers REFUSED with the service's own sentence rather than
	 * a code, because that sentence names the status that does not exist in the
	 * target version, and the name is the only thing an operator can act on.
	 *
	 * @param ObjectEntity         $object     The case the job is walking.
	 * @param array<string, mixed> $parameters The job parameters.
	 * @param bool                 $commit     False to rehearse, true to write.
	 * @param IUser|null           $actor      The user the job runs as.
	 *
	 * @return BulkActionResult What happened, or what would.
	 *
	 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
	 */
	public function apply(ObjectEntity $object, array $parameters, bool $commit, ?IUser $actor = null): BulkActionResult {
		$caseId = $this->caseId(object: $object);
		if ($caseId === '') {
			return BulkActionResult::skipped(reason: 'no_case_id');
		}

		$target = trim((string)($parameters['target'] ?? ''));
		$reason = trim((string)($parameters['reason'] ?? ''));

		try {
			if ($commit === false) {
				return $this->rehearse(caseId: $caseId, target: $target);
			}

			$this->move->move(
				caseId: $caseId,
				targetCaseTypeId: $target,
				reason: $reason,
				actorUid: $this->uidOf(actor: $actor),
			);

			return BulkActionResult::applied();
		} catch (RefusedException $e) {
			return BulkActionResult::refused(rule: $e->getSentence());
		} catch (Throwable $e) {
			return BulkActionResult::failed(message: $e->getMessage());
		}//end try
	}//end apply()

	/**
	 * What the move would do to this case, without writing it.
	 *
	 * @param string $caseId The case uuid.
	 * @param string $target The version to move onto.
	 *
	 * @return BulkActionResult Applied when it would move, refused with the reason when it would not.
	 *
	 * @throws RefusedException When the case or the target cannot be read.
	 *
	 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
	 */
	private function rehearse(string $caseId, string $target): BulkActionResult {
		$preview = $this->move->preview(caseId: $caseId, targetCaseTypeId: $target);
		if ($preview['canMove'] === true) {
			return BulkActionResult::applied();
		}

		$refusals = $preview['refusals'];

		return BulkActionResult::refused(
			rule: (string)($refusals[0] ?? 'This case cannot move to that version.')
		);
	}//end rehearse()

	/**
	 * Who the job is running as.
	 *
	 * @param IUser|null $actor The acting user.
	 *
	 * @return string The uid, or the empty string.
	 */
	private function uidOf(?IUser $actor): string {
		if ($actor === null) {
			return '';
		}

		return $actor->getUID();
	}//end uidOf()
}//end class
