<?php

/**
 * Moving one running case onto another version of its own case type.
 *
 * 🔴 THE DEFAULT IS STILL THAT NOTHING MOVES. `zaaktype-versioning` REQ-ZV-02
 * pins a running case to the version it was filed under, and that rule is right:
 * its status is a row only that version contains and its deadline was computed
 * from that version's `processingDeadline`, so carrying it forward on publish
 * would silently rewrite the terms of cases already in flight. What was missing
 * was the DELIBERATE exception, the one a coordinator performs knowingly on a
 * named case, with a reason and having been shown what it costs. Without it a
 * case filed a day before a correction was published had to be closed and
 * refiled under a new number.
 *
 * This is that exception, and only for another version of the SAME case type.
 * Moving a case to a DIFFERENT case type is a rebind, it changes the vocabulary
 * rather than its edition, and it belongs to `case-type-rebind`, which declares
 * this change as its dependency for exactly this reason.
 *
 * THE MAPPING IS BY NAME, AND THAT IS NOT A SHORTCUT. A statusType carries no
 * identity that survives a version: {@see \OCA\Dossiq\Service\CaseTypeCopyService}
 * copies each row into a new object with a new uuid, so the only thing two
 * versions of one status share is what it is called. A version where the author
 * renamed or deleted the status a case is sitting in is therefore precisely the
 * case that cannot be mapped, and it is refused BY NAME rather than guessed at:
 * landing a case in the target's first status because the mapping failed is the
 * kind of write nobody can read back afterwards.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\CaseType
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
 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\CaseType;

use DateTimeImmutable;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\SettingsService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * What a move to another version would change, and the move itself.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) `RefusedException::indeterminate()` is a
 *  named constructor, not a service call. It holds no state and exists so a
 *  caller cannot build a refusal with the wrong status on it. Same reading as
 *  `RefusalOutcome` and `TriageSleep`.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
 */
class CaseVersionMove {

	/**
	 * Why the pinned engine run does not move with the case yet.
	 *
	 * 🔑 SAID OUT LOUD RATHER THAN LEFT TO BE NOTICED. The case moves onto the
	 * target version's own workflow template, which is what dossiq owns. The
	 * RUN the engine has in flight stays pinned to the flow definition version
	 * it was queued against, because moving one is
	 * `migrate-run-between-versions` in openregister (register row 3.16), which
	 * is specified there and not yet shipped. A move that quietly left the run
	 * where it was and reported plain success is the silent half-write this
	 * sentence exists to prevent, so every answer carries it.
	 *
	 * @var string
	 *
	 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
	 */
	public const RUN_NOT_MOVED = 'The case moves to the new version. A flow run already in progress stays on the '
		. 'definition version it started under, until openregister ships migrate-run-between-versions.';

	/**
	 * Constructor.
	 *
	 * @param SettingsService      $settingsService Register/schema configuration and the object service.
	 * @param CaseTypeStore        $store           The app's one case type reader.
	 * @param CaseTypeVersionChain $chain           The versions a case could move to.
	 * @param CaseVersionDiff      $diff            How the two versions differ, for this case.
	 * @param LoggerInterface      $logger          The logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly CaseTypeStore $store,
		private readonly CaseTypeVersionChain $chain,
		private readonly CaseVersionDiff $diff,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The versions this case could move to, and the one it is on.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return array{current: array<string, mixed>, targets: array<int, array<string, mixed>>}
	 *
	 * @throws RefusedException When the case cannot be read.
	 *
	 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
	 */
	public function options(string $caseId): array {
		$case = $this->readCase(caseId: $caseId);
		$caseTypeId = $this->store->referenceId(value: ($case['caseType'] ?? ''));

		if ($caseTypeId === '') {
			throw new RefusedException(
				rule: 'case-has-no-case-type',
				sentence: 'This case names no case type, so there is no version chain to move it along.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		$current = [];
		foreach ($this->chain->versionsOf(caseTypeId: $caseTypeId) as $entry) {
			if ($entry['isSelf'] === true) {
				$current = $entry;
			}
		}

		return [
			'current' => $current,
			'targets' => $this->chain->targetsFor(caseTypeId: $caseTypeId),
		];
	}//end options()

	/**
	 * What moving this case onto that version would change.
	 *
	 * @param string $caseId             The case uuid.
	 * @param string $targetCaseTypeId   The version to move onto.
	 *
	 * @return array<string, mixed> The preview, including whether it can be done.
	 *
	 * @throws RefusedException When the case or the target cannot be read, or the target is not a version of this type.
	 *
	 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
	 */
	public function preview(string $caseId, string $targetCaseTypeId): array {
		$case = $this->readCase(caseId: $caseId);
		$sourceId = $this->store->referenceId(value: ($case['caseType'] ?? ''));
		$this->assertSameChain(sourceId: $sourceId, targetId: $targetCaseTypeId);

		$preview = $this->diff->between(
			case: $case,
			sourceId: $sourceId,
			targetId: $targetCaseTypeId
		);

		// The engine seam rides on every answer, never only on the ones that
		// succeed: a move that quietly left the run where it was and reported
		// plain success is the silent half-write this line prevents.
		$preview['run'] = ['moved' => false, 'reason' => self::RUN_NOT_MOVED];

		return $preview;
	}//end preview()

	/**
	 * Move the case onto another version of its case type.
	 *
	 * The preview is re-computed here rather than trusted from the caller: the
	 * dialog showed a preview a person read, and between reading it and
	 * pressing the button the target version may have been published over. A
	 * move that applies a stale mapping is the write this re-read prevents.
	 *
	 * @param string $caseId           The case uuid.
	 * @param string $targetCaseTypeId The version to move onto.
	 * @param string $reason           Why the case is being moved.
	 * @param string $actorUid         Who is moving it.
	 *
	 * @return array<string, mixed> The preview that was applied, with `moved` on it.
	 *
	 * @throws RefusedException When the move is refused, or the write fails.
	 *
	 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
	 */
	public function move(string $caseId, string $targetCaseTypeId, string $reason, string $actorUid): array {
		$reason = trim($reason);
		if ($reason === '') {
			throw new RefusedException(
				rule: 'version-move-needs-a-reason',
				sentence: 'Say why this case is moving to another version of its case type.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		$preview = $this->preview(caseId: $caseId, targetCaseTypeId: $targetCaseTypeId);
		if ($preview['canMove'] !== true) {
			throw new RefusedException(
				rule: 'status-does-not-exist-in-target-version',
				sentence: 'Version ' . (string)($preview['to']['version'] ?? '?')
					. ' has no status called "' . (string)$preview['status']['from']
					. '", so this case has nowhere to land. Add that status to the version, or move the case on first.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		$case = $this->readCase(caseId: $caseId);
		$sourceId = $this->store->referenceId(value: ($case['caseType'] ?? ''));

		$case['caseType'] = $targetCaseTypeId;
		$case['status'] = (string)$preview['status']['targetStatusId'];
		$case = $this->rebindTemplate(case: $case, targetCaseTypeId: $targetCaseTypeId);
		$case = $this->journal(
			case: $case,
			sourceId: $sourceId,
			preview: $preview,
			reason: $reason,
			actorUid: $actorUid
		);

		$this->write(caseId: $caseId, case: $case);

		$this->logger->info(
			'CaseVersionMove: a case moved to another version of its case type',
			[
				'case' => $caseId,
				'from' => $sourceId,
				'to' => $targetCaseTypeId,
				'actor' => $actorUid,
			]
		);

		$preview['moved'] = true;

		return $preview;
	}//end move()

	/**
	 * Refuse a target that is not another version of the case's own type.
	 *
	 * @param string $sourceId The version the case is on.
	 * @param string $targetId The version asked for.
	 *
	 * @return void
	 *
	 * @throws RefusedException When the two are not versions of one case type.
	 *
	 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
	 */
	private function assertSameChain(string $sourceId, string $targetId): void {
		if ($sourceId === '' || $targetId === '') {
			throw new RefusedException(
				rule: 'version-move-target-missing',
				sentence: 'Name the version this case should move to.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		if ($sourceId === $targetId) {
			throw new RefusedException(
				rule: 'version-move-to-itself',
				sentence: 'This case is already on that version.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		foreach ($this->chain->versionsOf(caseTypeId: $sourceId) as $entry) {
			if ($entry['id'] === $targetId) {
				return;
			}
		}

		throw new RefusedException(
			rule: 'version-move-crosses-case-types',
			sentence: 'That is a different case type, not another version of this one. Changing a case\'s type is a rebind and is not this act.',
			status: RefusedException::STATUS_UNPROCESSABLE,
		);
	}//end assertSameChain()

	/**
	 * Pin the case to the target version's own workflow template.
	 *
	 * `case.workflowTemplate` is filtered on the case's own case type, so a
	 * template belonging to the version the case just left is a pin its own
	 * page cannot show. When the target has no active template the pin is
	 * cleared, which is what a case type driving its lifecycle from transitions
	 * alone looks like.
	 *
	 * @param array<string, mixed> $case             The case being moved.
	 * @param string               $targetCaseTypeId The version it moves onto.
	 *
	 * @return array<string, mixed> The case, re-pinned.
	 *
	 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
	 */
	private function rebindTemplate(array $case, string $targetCaseTypeId): array {
		$rows = $this->store->rowsOfType(
			schemaKey: 'workflow_template_schema',
			caseTypeId: $targetCaseTypeId
		);

		$chosen = [];
		foreach ($rows as $row) {
			if (($row['isActive'] ?? false) === true) {
				$chosen = $row;
				break;
			}

			if ($chosen === []) {
				$chosen = $row;
			}
		}

		if ($chosen === []) {
			$case['workflowTemplate'] = null;
			$case['workflowVersion'] = null;

			return $case;
		}

		$case['workflowTemplate'] = $this->store->rowId(row: $chosen);
		$case['workflowVersion'] = (int)($chosen['version'] ?? 1);

		return $case;
	}//end rebindTemplate()

	/**
	 * Record the move on the case's own activity journal.
	 *
	 * Both version ids, the reason and the actor, because "the case type
	 * changed" with nothing beside it is the audit entry that sends somebody
	 * digging through the store to work out what it used to be.
	 *
	 * @param array<string, mixed> $case     The case being moved.
	 * @param string               $sourceId The version it is leaving.
	 * @param array<string, mixed> $preview  What the move changes.
	 * @param string               $reason   Why it is moving.
	 * @param string               $actorUid Who moved it.
	 *
	 * @return array<string, mixed> The case, with the entry appended.
	 *
	 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
	 */
	private function journal(array $case, string $sourceId, array $preview, string $reason, string $actorUid): array {
		$entries = [];
		$raw = ($case['activity'] ?? null);
		if (is_string($raw) === true && trim($raw) !== '') {
			$decoded = json_decode($raw, true);
			if (is_array($decoded) === true) {
				$entries = $decoded;
			}
		}

		if (is_array($raw) === true) {
			$entries = $raw;
		}

		$entries[] = [
			'type' => 'case-type-version-move',
			'fromCaseType' => $sourceId,
			'toCaseType' => (string)($preview['to']['id'] ?? ''),
			'fromVersion' => ($preview['from']['version'] ?? null),
			'toVersion' => ($preview['to']['version'] ?? null),
			'status' => (string)$preview['status']['to'],
			'fieldsRemoved' => $preview['fields']['removed'],
			'reason' => $reason,
			'actor' => $actorUid,
			'timestamp' => (new DateTimeImmutable())->format('Y-m-d\TH:i:sP'),
		];

		$case['activity'] = json_encode($entries);

		return $case;
	}//end journal()

	/**
	 * Read one case, refusing rather than answering an empty one.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return array<string, mixed> The case.
	 *
	 * @throws RefusedException When it cannot be read.
	 */
	private function readCase(string $caseId): array {
		[$objectService, $register, $schema] = $this->scope();

		try {
			$found = $objectService->find($caseId, register: $register, schema: $schema);
		} catch (Throwable $e) {
			throw RefusedException::indeterminate(
				rule: 'case-unreadable',
				sentence: 'This case could not be read, so it was not moved.',
				previous: $e,
			);
		}

		$case = $this->store->asRow(value: $found);
		if ($case === []) {
			throw new RefusedException(
				rule: 'case-not-found',
				sentence: 'This case could not be found.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		return $case;
	}//end readCase()

	/**
	 * Write the moved case back.
	 *
	 * @param string               $caseId The case uuid.
	 * @param array<string, mixed> $case   The case, moved.
	 *
	 * @return void
	 *
	 * @throws RefusedException When the write fails.
	 */
	private function write(string $caseId, array $case): void {
		[$objectService, $register, $schema] = $this->scope();

		try {
			$objectService->updateObject($register, $schema, $caseId, $case);
		} catch (Throwable $e) {
			throw RefusedException::indeterminate(
				rule: 'version-move-not-written',
				sentence: 'The case was not moved, because the change could not be saved.',
				previous: $e,
			);
		}
	}//end write()

	/**
	 * The object service and the case scope, or a refusal.
	 *
	 * @return array{0: object, 1: string, 2: string} The service, register and schema.
	 *
	 * @throws RefusedException When OpenRegister or the case schema is absent.
	 */
	private function scope(): array {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('case_schema');

		if ($objectService === null || $register === '' || $schema === '') {
			throw RefusedException::indeterminate(
				rule: 'case-store-unavailable',
				sentence: 'The case register could not be reached, so nothing was moved.',
			);
		}

		return [$objectService, $register, $schema];
	}//end scope()
}//end class
