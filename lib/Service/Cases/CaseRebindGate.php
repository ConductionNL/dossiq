<?php

/**
 * Dossiq case rebind gate.
 *
 * Whether a running case may move to another case type, and what it still has
 * to answer before it does. Five refusals live here: the caller is not a
 * coordinator, no reason was given, the target is absent or a draft or the
 * case's own type, the landing status belongs to another case type, and the
 * target requires properties in that status which the case does not carry.
 *
 * The answers belong here too, because "what is missing" is only readable
 * against "what the case already answers", and the dialog's answers are folded
 * in before that question is asked.
 *
 * Split out of {@see \OCA\Dossiq\Service\CaseRebindService}, which was over
 * its complexity ceiling. What is left there is the rebind itself: reading the
 * case, moving the flow run, re-arming the terms, writing the journal and
 * saving. Every refusal is written here and not only in the browser, because
 * an action the browser hides is still an endpoint anyone may call.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Cases
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/case-type-rebind/specs/zaaktype-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Cases;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\CaseTypeStore;
use OCP\IGroupManager;

/**
 * What refuses a rebind, and what the case still has to answer.
 *
 * @spec openspec/changes/case-type-rebind/specs/zaaktype-versioning/spec.md
 */
class CaseRebindGate {

	/**
	 * The group a rebind is for.
	 *
	 * Kept as its own constant rather than read from a setting, for the reason
	 * {@see \OCA\Dossiq\Service\CaseRebindService::COORDINATOR_GROUP} gives:
	 * the endpoint is reachable with curl by any authenticated user, and a
	 * guard that lives in the UI is the shape that has shipped as an IDOR here
	 * before. That constant now points at this one, so the refusal and the
	 * hiding still read one name.
	 *
	 * @var string
	 */
	public const COORDINATOR_GROUP = 'dossiq-coordinators';

	/**
	 * Constructor.
	 *
	 * @param CaseTypeStore    $store        The app's one case type reader.
	 * @param CaseTypeResolver $resolver     Statuses, results and properties of one case type.
	 * @param IGroupManager    $groupManager Group membership, for D-3.
	 */
	public function __construct(
		private readonly CaseTypeStore $store,
		private readonly CaseTypeResolver $resolver,
		private readonly IGroupManager $groupManager,
	) {
	}//end __construct()

	/**
	 * Whether this user may rebind at all.
	 *
	 * Answered as its own question so the action can be hidden from people who
	 * would only be refused, and so the refusal and the hiding read the same
	 * group rather than two lists that drift.
	 *
	 * @param string $uid The user id, or '' for nobody.
	 *
	 * @return boolean True when they may.
	 *
	 * @spec openspec/changes/case-type-rebind/specs/zaaktype-versioning/spec.md
	 */
	public function mayRebind(string $uid): bool {
		if ($uid === '') {
			return false;
		}

		return $this->groupManager->isInGroup($uid, self::COORDINATOR_GROUP);
	}//end mayRebind()

	/**
	 * Refuse a rebind nobody may make, or one nobody explained.
	 *
	 * @param string $actorUid Who is asking.
	 * @param string $reason   Why the case is moving.
	 *
	 * @return string The reason, trimmed.
	 *
	 * @throws RefusedException When the caller may not rebind, or named no reason.
	 *
	 * @spec openspec/changes/case-type-rebind/specs/zaaktype-versioning/spec.md
	 */
	public function assertMayRebind(string $actorUid, string $reason): string {
		if ($this->mayRebind(uid: $actorUid) === false) {
			throw new RefusedException(
				rule: 'rebind-is-for-coordinators',
				sentence: 'Only a case coordinator may change the type of a running case.',
				status: RefusedException::STATUS_FORBIDDEN,
			);
		}

		$reason = trim($reason);
		if ($reason === '') {
			throw new RefusedException(
				rule: 'rebind-needs-a-reason',
				sentence: 'Say why this case is moving to another case type.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		return $reason;
	}//end assertMayRebind()

	/**
	 * Refuse a target that is absent, a draft, or the case's own type.
	 *
	 * @param string               $caseId           The case, for the message.
	 * @param array<string, mixed> $case             The case as read.
	 * @param string               $targetCaseTypeId The target.
	 *
	 * @return void
	 *
	 * @throws RefusedException When the target will not do.
	 *
	 * @spec openspec/changes/case-type-rebind/specs/zaaktype-versioning/spec.md
	 */
	public function assertTarget(string $caseId, array $case, string $targetCaseTypeId): void {
		if (trim($targetCaseTypeId) === '') {
			throw new RefusedException(
				rule: 'rebind-target-missing',
				sentence: 'Name the case type this case should be rebound to.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		if ($this->store->referenceId(value: ($case['caseType'] ?? '')) === $targetCaseTypeId) {
			throw new RefusedException(
				rule: 'rebind-to-itself',
				sentence: 'This case is already on that case type.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		$target = $this->store->readCaseType(caseTypeId: $targetCaseTypeId);
		if ($target === []) {
			throw new RefusedException(
				rule: 'rebind-target-not-found',
				sentence: 'That case type could not be read, so case ' . $caseId . ' was not rebound.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		if (($target['isDraft'] ?? false) === true) {
			throw new RefusedException(
				rule: 'rebind-target-is-a-draft',
				sentence: 'That case type is still a draft. Publish it before moving a running case onto it.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}
	}//end assertTarget()

	/**
	 * Refuse a landing status that is not the target's own.
	 *
	 * The mapping is explicit (D-1), which means it is also UNTRUSTED: a status
	 * id posted straight to the endpoint could name a row of any case type at
	 * all, and a case sitting in another type's status is invisible to every
	 * lens that reads its own blueprint.
	 *
	 * @param string $targetCaseTypeId The target case type.
	 * @param string $targetStatusId   The status asked for.
	 *
	 * @return void
	 *
	 * @throws RefusedException When the status is absent or belongs elsewhere.
	 *
	 * @spec openspec/changes/case-type-rebind/specs/zaaktype-versioning/spec.md
	 */
	public function assertStatus(string $targetCaseTypeId, string $targetStatusId): void {
		if (trim($targetStatusId) === '') {
			throw new RefusedException(
				rule: 'rebind-needs-a-mapped-status',
				sentence: 'Say which status of the target case type this case lands in.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		foreach ($this->resolver->statusTypesFor(caseTypeId: $targetCaseTypeId) as $status) {
			if ($this->store->rowId(row: $status) === $targetStatusId) {
				return;
			}
		}

		throw new RefusedException(
			rule: 'rebind-status-is-not-the-targets',
			sentence: 'That status does not belong to the case type you are rebinding to.',
			status: RefusedException::STATUS_UNPROCESSABLE,
		);
	}//end assertStatus()

	/**
	 * The case's answered properties, whichever shape they are stored in.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<string, mixed> The answers.
	 */
	public function answersOf(array $case): array {
		$raw = ($case['properties'] ?? []);
		if (is_string($raw) === true && trim($raw) !== '') {
			$decoded = json_decode($raw, true);
			$raw = [];
			if (is_array($decoded) === true) {
				$raw = $decoded;
			}
		}

		if (is_array($raw) === true) {
			return $raw;
		}

		return [];
	}//end answersOf()

	/**
	 * Fold the dialog's answers into the case's properties.
	 *
	 * @param array<string, mixed> $case       The case.
	 * @param array<string, mixed> $properties What was answered.
	 *
	 * @return array<string, mixed> The case.
	 */
	public function applyAnswers(array $case, array $properties): array {
		if ($properties === []) {
			return $case;
		}

		$answers = $this->answersOf(case: $case);
		foreach ($properties as $name => $value) {
			$name = trim((string)$name);
			if ($name !== '') {
				$answers[$name] = $value;
			}
		}

		$case['properties'] = $answers;

		return $case;
	}//end applyAnswers()

	/**
	 * The target's required properties at that status that this case lacks.
	 *
	 * Reads `requiredAtStatus` on the target's property definitions, which is
	 * the same declaration
	 * {@see \OCA\Dossiq\Service\Status\CaseStateFieldRuleProjector} publishes to
	 * the status machinery. Asking a second source would let the dialog and the
	 * page disagree about what a status requires.
	 *
	 * @param array<string, mixed> $case             The case as it stands.
	 * @param string               $targetCaseTypeId The target case type.
	 * @param string               $targetStatusId   The landing status.
	 *
	 * @return array<int, string> The property names still to be answered.
	 *
	 * @spec openspec/changes/case-type-rebind/specs/zaaktype-versioning/spec.md
	 */
	public function missingAt(array $case, string $targetCaseTypeId, string $targetStatusId): array {
		$answers = $this->answersOf(case: $case);

		$missing = [];
		foreach ($this->resolver->propertyDefinitionsFor(caseTypeId: $targetCaseTypeId) as $property) {
			$name = trim((string)($property['name'] ?? ''));
			$requiredAt = $this->store->referenceId(value: ($property['requiredAtStatus'] ?? ''));
			if ($name === '' || $requiredAt !== $targetStatusId) {
				continue;
			}

			$value = ($answers[$name] ?? null);
			if ($value === null || $value === '' || $value === []) {
				$missing[] = $name;
			}
		}

		sort($missing);

		return array_values(array_unique($missing));
	}//end missingAt()

	/**
	 * Refuse a rebind the case does not carry the target's required fields for.
	 *
	 * @param array<int, string> $missing The fields the target requires and the case lacks.
	 *
	 * @return void
	 *
	 * @throws RefusedException When anything is missing.
	 *
	 * @spec openspec/changes/case-type-rebind/specs/zaaktype-versioning/spec.md
	 */
	public function assertNothingMissing(array $missing): void {
		if ($missing === []) {
			return;
		}

		$pronoun = 'them';
		if (count($missing) === 1) {
			$pronoun = 'it';
		}

		throw new RefusedException(
			rule: 'rebind-missing-required-properties',
			sentence: 'The target case type requires ' . implode(', ', $missing)
				. ' in that status, and this case does not carry ' . $pronoun . '.',
			status: RefusedException::STATUS_UNPROCESSABLE,
		);
	}//end assertNothingMissing()

	/**
	 * Whether the case's result survives the rebind, and what to say if not.
	 *
	 * A result is a row of the case type it was chosen from, so a rebind can
	 * leave a case closed with a result the new type does not have. The note is
	 * shown rather than the result silently cleared: a coordinator rebinding a
	 * decided case needs to read that its outcome no longer means anything.
	 *
	 * @param array<string, mixed> $case             The case.
	 * @param string               $targetCaseTypeId The target.
	 *
	 * @return array{carried: bool, note: string} The verdict.
	 *
	 * @spec openspec/changes/case-type-rebind/specs/zaaktype-versioning/spec.md
	 */
	public function resultCompatibility(array $case, string $targetCaseTypeId): array {
		$resultId = $this->store->referenceId(value: ($case['result'] ?? ''));
		if ($resultId === '') {
			return ['carried' => true, 'note' => ''];
		}

		foreach ($this->resolver->resultTypesFor(caseTypeId: $targetCaseTypeId) as $result) {
			if ($this->store->rowId(row: $result) === $resultId) {
				return ['carried' => true, 'note' => ''];
			}
		}

		return [
			'carried' => false,
			'note' => 'The result this case was closed with is not a result of the target case type. '
				. 'It stays on the case as a record of what was decided, and it no longer matches the blueprint.',
		];
	}//end resultCompatibility()
}//end class
