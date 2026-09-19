<?php

/**
 * Rebinding a running case to another case type.
 *
 * 🔴 THIS IS NOT THE VERSION MOVE, AND THE DIFFERENCE IS THE MAPPING.
 * {@see \OCA\Dossiq\Service\CaseType\CaseVersionMove} moves a case along its
 * OWN chain, where two versions share a status NAME and the landing status can
 * therefore be derived. A rebind crosses into a different case type, whose
 * statuses are a different vocabulary: "In behandeling" on a Kapvergunning and
 * "In behandeling" on an Omgevingsvergunning are two unrelated rows that happen
 * to read alike, and mapping them by name would land cases in whichever status
 * an author happened to word the same way. So the mapping here is ASKED FOR,
 * never guessed, which is design D-1 and the reason this is a separate class.
 *
 * WHAT A REBIND COSTS, AND WHAT IT MUST NOT COST
 * ----------------------------------------------
 * A case filed under the wrong type used to be closed and refiled: a new
 * number, a new term, and a paper trail that stops. The whole point of the
 * rebind is that none of those happen. The number stays, the folder stays, the
 * documents, roles and notes stay, and the terms keep the day they started on,
 * because a rebind moves no statutory clock (D-4 and D-2 step 4). It changes
 * the blueprint the case is governed by and nothing else.
 *
 * THE ORDER OF WRITES IS THE SAFETY (D-2)
 * ---------------------------------------
 * Validate, then ask the engine, then write, then re-arm. The engine is asked
 * BEFORE anything is written, because a run that refuses to move leaves a case
 * whose blueprint says one thing and whose process is doing another, and that
 * is worse than a rebind that did not happen.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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

namespace OCA\Dossiq\Service;

use DateTimeImmutable;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseType\EngineRunMigration;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Validate, migrate, write and re-arm: one case onto another case type.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/case-type-rebind/specs/zaaktype-versioning/spec.md
 */
class CaseRebindService {

	/**
	 * The one group that may rebind a running case (D-3).
	 *
	 * 🔴 CHECKED HERE AND NOT ONLY ON THE ACTION. The manifest hides the button
	 * from everybody else, and a hidden button is not an authorization: the
	 * endpoint is reachable with curl by any authenticated user. Gate 12 exists
	 * because `#[NoAdminRequired]` with the guard living in the UI is the shape
	 * that has shipped as an IDOR here before.
	 *
	 * @var string
	 */
	public const COORDINATOR_GROUP = 'dossiq-coordinators';

	/**
	 * Constructor.
	 *
	 * @param SettingsService    $settingsService Register/schema configuration and the object service.
	 * @param CaseTypeStore      $store           The app's one case type reader.
	 * @param CaseTypeResolver   $resolver        Statuses and properties of one case type.
	 * @param EngineRunMigration $engine          The seam that moves the flow run.
	 * @param TermijnService     $terms           The terms, for the re-arm.
	 * @param CaseTypeSlugResolver $slugs         Case type uuid to the slug term definitions are keyed by.
	 * @param IGroupManager      $groupManager    Group membership, for D-3.
	 * @param LoggerInterface    $logger          The logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly CaseTypeStore $store,
		private readonly CaseTypeResolver $resolver,
		private readonly EngineRunMigration $engine,
		private readonly TermijnService $terms,
		private readonly CaseTypeSlugResolver $slugs,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
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
	 * The case types this case could be rebound to, and where it stands now.
	 *
	 * Every published case type other than the one the case is on, including
	 * the other versions of its own type: a rebind is a superset of the version
	 * move, and a coordinator who opened this dialog should not be told to go
	 * and find a different one because their target happened to be a version.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return array<string, mixed> The current binding and the targets.
	 *
	 * @throws RefusedException When the case cannot be read.
	 *
	 * @spec openspec/changes/case-type-rebind/specs/zaaktype-versioning/spec.md
	 */
	public function options(string $caseId): array {
		$case = $this->readCase(caseId: $caseId);
		$sourceId = $this->store->referenceId(value: ($case['caseType'] ?? ''));

		$targets = [];
		foreach ($this->store->everyCaseType() as $row) {
			$id = $this->store->rowId(row: $row);
			if ($id === '' || $id === $sourceId || ($row['isDraft'] ?? false) === true) {
				continue;
			}

			$targets[] = [
				'id' => $id,
				'title' => (string)($row['title'] ?? ''),
				'identifier' => (string)($row['identifier'] ?? ''),
				'version' => ($row['version'] ?? null),
				'sameChain' => ($this->identifierOf(caseTypeId: $sourceId) !== ''
					&& $this->identifierOf(caseTypeId: $sourceId) === (string)($row['identifier'] ?? '')),
			];
		}

		usort(
			$targets,
			static fn (array $a, array $b): int => strcmp($a['title'], $b['title'])
		);

		return [
			'current' => [
				'caseType' => $sourceId,
				'title' => (string)($this->store->readCaseType(caseTypeId: $sourceId)['title'] ?? ''),
				'status' => $this->statusNameOf(caseTypeId: $sourceId, statusId: $this->store->referenceId(value: ($case['status'] ?? ''))),
			],
			'targets' => $targets,
		];
	}//end options()

	/**
	 * What rebinding onto that type, landing in that status, would ask for.
	 *
	 * @param string $caseId           The case uuid.
	 * @param string $targetCaseTypeId The case type asked about.
	 * @param string $targetStatusId   The status the coordinator picked, or ''.
	 *
	 * @return array<string, mixed> The statuses to choose from, what is missing, and whether it can be done.
	 *
	 * @throws RefusedException When the case or the target cannot be read.
	 *
	 * @spec openspec/changes/case-type-rebind/specs/zaaktype-versioning/spec.md
	 */
	public function preview(string $caseId, string $targetCaseTypeId, string $targetStatusId): array {
		$case = $this->readCase(caseId: $caseId);
		$this->assertTarget(caseId: $caseId, case: $case, targetCaseTypeId: $targetCaseTypeId);

		$statuses = [];
		foreach ($this->resolver->statusTypesFor(caseTypeId: $targetCaseTypeId) as $status) {
			$id = $this->store->rowId(row: $status);
			if ($id !== '') {
				$statuses[] = ['id' => $id, 'name' => (string)($status['name'] ?? '')];
			}
		}

		$missing = [];
		if ($targetStatusId !== '') {
			$missing = $this->missingAt(
				case: $case,
				targetCaseTypeId: $targetCaseTypeId,
				targetStatusId: $targetStatusId
			);
		}

		return [
			'from' => [
				'caseType' => $this->store->referenceId(value: ($case['caseType'] ?? '')),
				'status' => $this->statusNameOf(
					caseTypeId: $this->store->referenceId(value: ($case['caseType'] ?? '')),
					statusId: $this->store->referenceId(value: ($case['status'] ?? ''))
				),
			],
			'to' => [
				'caseType' => $targetCaseTypeId,
				'title' => (string)($this->store->readCaseType(caseTypeId: $targetCaseTypeId)['title'] ?? ''),
			],
			'statuses' => $statuses,
			'missingProperties' => $missing,
			'results' => $this->resultCompatibility(case: $case, targetCaseTypeId: $targetCaseTypeId),
			// The engine seam rides on the PREVIEW too, not only on the write:
			// a coordinator deciding whether to rebind should read what happens
			// to the run before pressing the button, not afterwards.
			'run' => ['moved' => false, 'reason' => EngineRunMigration::RUN_NOT_MOVED],
			'canRebind' => ($targetStatusId !== '' && $missing === []),
		];
	}//end preview()

	/**
	 * Rebind this case, in the order D-2 sets out.
	 *
	 * @param string               $caseId           The case uuid.
	 * @param string               $targetCaseTypeId The case type to rebind onto.
	 * @param string               $targetStatusId   The status it lands in.
	 * @param string               $reason           Why it is being rebound.
	 * @param array<string, mixed> $properties       The answers to what the target requires and the case lacks.
	 * @param string               $actorUid         The coordinator doing it.
	 *
	 * @return array<string, mixed> What was applied.
	 *
	 * @throws RefusedException When the rebind is refused, or a write fails.
	 *
	 * @spec openspec/changes/case-type-rebind/specs/zaaktype-versioning/spec.md
	 */
	public function rebind(
		string $caseId,
		string $targetCaseTypeId,
		string $targetStatusId,
		string $reason,
		array $properties,
		string $actorUid,
	): array {
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

		$case = $this->readCase(caseId: $caseId);
		$sourceId = $this->store->referenceId(value: ($case['caseType'] ?? ''));
		$this->assertTarget(caseId: $caseId, case: $case, targetCaseTypeId: $targetCaseTypeId);
		$this->assertStatus(targetCaseTypeId: $targetCaseTypeId, targetStatusId: $targetStatusId);

		// D-1: the answers given in the dialog count towards what the target
		// requires, so a coordinator who filled them in is not refused for the
		// very fields they just supplied.
		$case = $this->applyAnswers(case: $case, properties: $properties);
		$missing = $this->missingAt(
			case: $case,
			targetCaseTypeId: $targetCaseTypeId,
			targetStatusId: $targetStatusId
		);
		if ($missing !== []) {
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
		}

		// D-2 step 2, BEFORE any write: a run that refuses to move leaves a
		// case whose blueprint and whose process disagree.
		$run = $this->engine->migrate(
			caseId: $caseId,
			targetCaseTypeId: $targetCaseTypeId,
			actorUid: $actorUid
		);

		$case['caseType'] = $targetCaseTypeId;
		$case['status'] = $targetStatusId;
		$case = $this->rebindTemplate(case: $case, targetCaseTypeId: $targetCaseTypeId);
		$case = $this->journal(
			case: $case,
			sourceId: $sourceId,
			targetCaseTypeId: $targetCaseTypeId,
			targetStatusId: $targetStatusId,
			reason: $reason,
			actorUid: $actorUid
		);

		$this->write(caseId: $caseId, case: $case);

		// THE SLUG, NOT THE UUID. Term definitions are keyed by the case type
		// SLUG, and a uuid matches none of them: handing one over re-arms
		// nothing and reports a clean zero, which is the silent half of this
		// act. {@see CaseTypeSlugResolver::toSlug()} passes a slug through
		// unchanged and refuses to guess at a uuid it cannot resolve.
		$terms = $this->terms->rearmForDefinition(
			caseId: $caseId,
			caseTypeSlug: $this->slugs->toSlug(reference: $targetCaseTypeId),
			reason: $reason
		);

		$this->logger->info(
			'CaseRebindService: a running case was rebound to another case type',
			[
				'case' => $caseId,
				'from' => $sourceId,
				'to' => $targetCaseTypeId,
				'actor' => $actorUid,
				'runMigrated' => $run['migrated'],
			]
		);

		return [
			'rebound' => true,
			'from' => $sourceId,
			'to' => $targetCaseTypeId,
			'status' => $targetStatusId,
			'reason' => $reason,
			'run' => ['moved' => $run['migrated'], 'reason' => $run['reason']],
			'terms' => $terms,
		];
	}//end rebind()

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
	private function assertTarget(string $caseId, array $case, string $targetCaseTypeId): void {
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
	private function assertStatus(string $targetCaseTypeId, string $targetStatusId): void {
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
	private function missingAt(array $case, string $targetCaseTypeId, string $targetStatusId): array {
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
	private function resultCompatibility(array $case, string $targetCaseTypeId): array {
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

	/**
	 * Fold the dialog's answers into the case's properties.
	 *
	 * @param array<string, mixed> $case       The case.
	 * @param array<string, mixed> $properties What was answered.
	 *
	 * @return array<string, mixed> The case.
	 */
	private function applyAnswers(array $case, array $properties): array {
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
	 * The case's answered properties, whichever shape they are stored in.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<string, mixed> The answers.
	 */
	private function answersOf(array $case): array {
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
	 * Pin the case to the target case type's own workflow template.
	 *
	 * @param array<string, mixed> $case             The case.
	 * @param string               $targetCaseTypeId The target.
	 *
	 * @return array<string, mixed> The case, re-pinned.
	 *
	 * @spec openspec/changes/case-type-rebind/specs/zaaktype-versioning/spec.md
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
	 * Record the rebind on the case's own journal, with both bindings.
	 *
	 * @param array<string, mixed> $case             The case.
	 * @param string               $sourceId         The case type it leaves.
	 * @param string               $targetCaseTypeId The case type it joins.
	 * @param string               $targetStatusId   The status it lands in.
	 * @param string               $reason           Why.
	 * @param string               $actorUid         Who.
	 *
	 * @return array<string, mixed> The case, with the entry appended.
	 *
	 * @spec openspec/changes/case-type-rebind/specs/zaaktype-versioning/spec.md
	 */
	private function journal(
		array $case,
		string $sourceId,
		string $targetCaseTypeId,
		string $targetStatusId,
		string $reason,
		string $actorUid,
	): array {
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
			'type' => 'case-type-rebind',
			'fromCaseType' => $sourceId,
			'fromCaseTypeTitle' => (string)($this->store->readCaseType(caseTypeId: $sourceId)['title'] ?? ''),
			'toCaseType' => $targetCaseTypeId,
			'toCaseTypeTitle' => (string)($this->store->readCaseType(caseTypeId: $targetCaseTypeId)['title'] ?? ''),
			'status' => $targetStatusId,
			'reason' => $reason,
			'actor' => $actorUid,
			'timestamp' => (new DateTimeImmutable())->format('Y-m-d\TH:i:sP'),
		];

		$case['activity'] = json_encode($entries);

		return $case;
	}//end journal()

	/**
	 * The identifier of one case type, or ''.
	 *
	 * @param string $caseTypeId The case type.
	 *
	 * @return string The identifier.
	 */
	private function identifierOf(string $caseTypeId): string {
		return trim((string)($this->store->readCaseType(caseTypeId: $caseTypeId)['identifier'] ?? ''));
	}//end identifierOf()

	/**
	 * The name of one status of one case type.
	 *
	 * @param string $caseTypeId The case type.
	 * @param string $statusId   The status.
	 *
	 * @return string The name, or ''.
	 */
	private function statusNameOf(string $caseTypeId, string $statusId): string {
		if ($caseTypeId === '' || $statusId === '') {
			return '';
		}

		foreach ($this->resolver->statusTypesFor(caseTypeId: $caseTypeId) as $status) {
			if ($this->store->rowId(row: $status) === $statusId) {
				return (string)($status['name'] ?? '');
			}
		}

		return '';
	}//end statusNameOf()

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
				sentence: 'This case could not be read, so it was not rebound.',
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
	 * Write the rebound case back.
	 *
	 * @param string               $caseId The case uuid.
	 * @param array<string, mixed> $case   The case, rebound.
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
				rule: 'rebind-not-written',
				sentence: 'The case was not rebound, because the change could not be saved.',
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
				sentence: 'The case register could not be reached, so nothing was rebound.',
			);
		}

		return [$objectService, $register, $schema];
	}//end scope()
}//end class
