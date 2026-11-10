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

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Cases\CaseRebindGate;
use OCA\Dossiq\Service\Cases\CaseRebindImpact;
use OCA\Dossiq\Service\Cases\CaseRebindTerms;
use OCA\Dossiq\Service\Cases\RebindMove;
use OCA\Dossiq\Service\CaseType\EngineRunMigration;
use OCA\Dossiq\Service\Support\RefusesWhenIndeterminate;
use OCA\Dossiq\Service\Support\TranslatedText;
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

	use RefusesWhenIndeterminate;

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
	public const COORDINATOR_GROUP = CaseRebindGate::COORDINATOR_GROUP;

	/**
	 * Constructor.
	 *
	 * @param SettingsService    $settingsService Register/schema configuration and the object service.
	 * @param CaseTypeStore      $store           The app's one case type reader.
	 * @param CaseTypeResolver   $resolver        Statuses and properties of one case type.
	 * @param EngineRunMigration $engine          The seam that moves the flow run.
	 * @param CaseRebindTerms    $terms           The re-arm of a case's running terms under its new case type.
	 * @param CaseRebindGate     $gate            What refuses a rebind, and what the case must answer.
	 * @param CaseRebindImpact   $impact          What the rebind does to the case's answers.
	 * @param LoggerInterface    $logger          The logger.
	 * @param TranslatedText     $text            Translatable titles and names in the reader's language.
	 *
	 * @spec openspec/specs/zaaktype-versioning/spec.md
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly CaseTypeStore $store,
		private readonly CaseTypeResolver $resolver,
		private readonly EngineRunMigration $engine,
		private readonly CaseRebindTerms $terms,
		private readonly CaseRebindGate $gate,
		private readonly CaseRebindImpact $impact,
		private readonly LoggerInterface $logger,
		private readonly TranslatedText $text,
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
		return $this->gate->mayRebind(uid: $uid);
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
	 * @spec openspec/changes/rebind-dialog-translated-labels/specs/zaaktype-versioning/spec.md
	 */
	public function options(string $caseId): array {
		$case = $this->readCase(caseId: $caseId);
		$sourceId = $this->store->referenceId(value: ($case['caseType'] ?? ''));
		$targets = $this->targetsFor(sourceId: $sourceId);

		return [
			'current' => [
				'caseType' => $sourceId,
				'title' => $this->titleOf(caseTypeId: $sourceId),
				'status' => $this->statusNameOf(caseTypeId: $sourceId, statusId: $this->store->referenceId(value: ($case['status'] ?? ''))),
			],
			'targets' => $targets,
		];
	}//end options()

	/**
	 * What rebinding onto that type, landing in that status, would ask for.
	 *
	 * The `impact` block is the same computation {@see rebind()} applies: what
	 * is dropped, what is ported where, and what the target still requires.
	 * `canRebind` is true only when a status is chosen and that impact is
	 * complete, so the dialog cannot enable a button the write would refuse.
	 *
	 * @param string                $caseId           The case uuid.
	 * @param string                $targetCaseTypeId The case type asked about.
	 * @param string                $targetStatusId   The status the coordinator picked, or ''.
	 * @param array<string, string> $remap            Dropped answer name => target field name.
	 * @param array<string, mixed>  $properties       Answers to the target's required fields.
	 *
	 * @return array<string, mixed> The statuses to choose from, the impact, and whether it can be done.
	 *
	 * @throws RefusedException When the case or the target cannot be read, or a remap cannot be made.
	 *
	 * @spec openspec/changes/case-type-rebind/specs/zaaktype-versioning/spec.md
	 * @spec openspec/specs/zaaktype-versioning/spec.md
	 * @spec openspec/changes/rebind-dialog-translated-labels/specs/zaaktype-versioning/spec.md
	 */
	public function preview(
		string $caseId,
		string $targetCaseTypeId,
		string $targetStatusId,
		array $remap = [],
		array $properties = [],
	): array {
		$case = $this->readCase(caseId: $caseId);
		$this->gate->assertTarget(caseId: $caseId, case: $case, targetCaseTypeId: $targetCaseTypeId);

		$statuses = $this->statusChoices(caseTypeId: $targetCaseTypeId);
		$impact = $this->impact->compute(
			case: $case,
			targetCaseTypeId: $targetCaseTypeId,
			targetStatusId: $targetStatusId,
			remap: $remap,
			answers: $properties
		);

		return [
			'from' => $this->bindingOf(case: $case),
			'to' => ['caseType' => $targetCaseTypeId, 'title' => $this->titleOf(caseTypeId: $targetCaseTypeId)],
			'statuses' => $statuses,
			'missingProperties' => $this->unanswered(impact: $impact),
			'impact' => $impact,
			'results' => $this->gate->resultCompatibility(case: $case, targetCaseTypeId: $targetCaseTypeId),
			// The engine seam rides on the PREVIEW too, not only on the write:
			// a coordinator deciding whether to rebind should read what happens
			// to the run before pressing the button, not afterwards.
			'run' => ['moved' => false, 'reason' => EngineRunMigration::RUN_NOT_MOVED],
			'canRebind' => $this->canRebind(targetStatusId: $targetStatusId, impact: $impact),
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
	 * @param array<string, string> $remap           Dropped answer name => target field name.
	 * @param array<int, string>   $confirmDropped   The dropped answer names the coordinator saw and accepted.
	 *
	 * @return array<string, mixed> What was applied.
	 *
	 * @throws RefusedException When the rebind is refused, or a write fails.
	 *
	 * @spec openspec/changes/case-type-rebind/specs/zaaktype-versioning/spec.md
	 * @spec openspec/specs/zaaktype-versioning/spec.md
	 */
	public function rebind(
		string $caseId,
		string $targetCaseTypeId,
		string $targetStatusId,
		string $reason,
		array $properties,
		string $actorUid,
		array $remap = [],
		array $confirmDropped = [],
	): array {
		$move = new RebindMove(
			caseId: $caseId,
			targetCaseTypeId: $targetCaseTypeId,
			targetStatusId: $targetStatusId,
			reason: $this->gate->assertMayRebind(actorUid: $actorUid, reason: $reason),
		);

		$case = $this->readCase(caseId: $caseId);
		$sourceId = $this->store->referenceId(value: ($case['caseType'] ?? ''));
		$impact = $this->checkedImpact(
			move: $move,
			asked: ['case' => $case, 'remap' => $remap, 'answers' => $properties, 'confirmDropped' => $confirmDropped]
		);

		// D-2 step 2, BEFORE any write: a run that refuses to move leaves a
		// case whose blueprint and whose process disagree.
		$run = $this->engine->migrate(caseId: $caseId, targetCaseTypeId: $targetCaseTypeId, actorUid: $actorUid);

		$applied = ['case' => $case, 'sourceId' => $sourceId, 'actorUid' => $actorUid, 'impact' => $impact, 'run' => $run];
		$this->write(caseId: $caseId, case: $this->reboundCase(move: $move, applied: $applied));

		// THE SLUG, NOT THE UUID: {@see CaseRebindTerms::rearm()} keys the
		// re-arm by the target's slug, because a uuid matches no definition.
		$applied['terms'] = $this->terms->rearm(caseId: $caseId, targetCaseTypeId: $targetCaseTypeId, reason: $move->reason);
		$this->logRebound(move: $move, applied: $applied);

		return $move->outcome(applied: $applied);
	}//end rebind()

	/**
	 * The required fields the impact still has no valid answer for.
	 *
	 * @param array{required: array<int, array<string, mixed>>} $impact The impact.
	 *
	 * @return array<int, string> Their names.
	 *
	 * @spec openspec/specs/zaaktype-versioning/spec.md
	 */
	private function unanswered(array $impact): array {
		$names = [];
		foreach ($impact['required'] as $row) {
			if ($row['valid'] === false) {
				$names[] = (string)$row['name'];
			}
		}

		sort($names);

		return $names;
	}//end unanswered()

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
	 * Whether the write would take this: a status is chosen and the impact is complete.
	 *
	 * @param string               $targetStatusId The status picked, or ''.
	 * @param array<string, mixed> $impact         The impact.
	 *
	 * @return boolean True when the rebind can be done.
	 */
	private function canRebind(string $targetStatusId, array $impact): bool {
		return $targetStatusId !== '' && $impact['complete'] === true;
	}//end canRebind()

	/**
	 * Every published case type other than the source, by title.
	 *
	 * @param string $sourceId The case type the case is on.
	 *
	 * @return array<int, array<string, mixed>> The targets.
	 */
	private function targetsFor(string $sourceId): array {
		$targets = [];
		foreach ($this->store->everyCaseType() as $row) {
			$target = $this->targetEntry(row: $row, sourceId: $sourceId);
			if ($target !== null) {
				$targets[] = $target;
			}
		}

		usort(
			$targets,
			static fn (array $a, array $b): int => strcmp($a['title'], $b['title'])
		);

		return $targets;
	}//end targetsFor()

	/**
	 * One case type as a rebind target, or null when it cannot be one.
	 *
	 * @param array<string, mixed> $row      The case type.
	 * @param string               $sourceId The case type the case is on.
	 *
	 * @return array<string, mixed>|null The target.
	 */
	private function targetEntry(array $row, string $sourceId): ?array {
		$id = $this->store->rowId(row: $row);
		if ($id === '' || $id === $sourceId || ($row['isDraft'] ?? false) === true) {
			return null;
		}

		return [
			'id' => $id,
			'title' => $this->text->forReader(value: ($row['title'] ?? null)),
			'identifier' => (string)($row['identifier'] ?? ''),
			'version' => ($row['version'] ?? null),
			'sameChain' => $this->sameChain(sourceId: $sourceId, row: $row),
		];
	}//end targetEntry()

	/**
	 * Whether a case type carries the same identifier as the source, as another version of it.
	 *
	 * @param string               $sourceId The case type the case is on.
	 * @param array<string, mixed> $row      The other case type.
	 *
	 * @return boolean True when both carry the same, non-empty identifier.
	 */
	private function sameChain(string $sourceId, array $row): bool {
		return $this->identifierOf(caseTypeId: $sourceId) !== ''
			&& $this->identifierOf(caseTypeId: $sourceId) === (string)($row['identifier'] ?? '');
	}//end sameChain()

	/**
	 * The statuses of a case type a rebind may land in.
	 *
	 * @param string $caseTypeId The case type.
	 *
	 * @return array<int, array{id: string, name: string}> The statuses.
	 */
	private function statusChoices(string $caseTypeId): array {
		$statuses = [];
		foreach ($this->resolver->statusTypesFor(caseTypeId: $caseTypeId) as $status) {
			$id = $this->store->rowId(row: $status);
			if ($id !== '') {
				$statuses[] = ['id' => $id, 'name' => $this->text->forReader(value: ($status['name'] ?? null))];
			}
		}

		return $statuses;
	}//end statusChoices()

	/**
	 * The case type a case is on, and the name of its status.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array{caseType: string, status: string} The binding.
	 */
	private function bindingOf(array $case): array {
		return [
			'caseType' => $this->store->referenceId(value: ($case['caseType'] ?? '')),
			'status' => $this->statusNameOf(
				caseTypeId: $this->store->referenceId(value: ($case['caseType'] ?? '')),
				statusId: $this->store->referenceId(value: ($case['status'] ?? ''))
			),
		];
	}//end bindingOf()

	/**
	 * The impact of the move, refused unless the target, status, drops and answers all hold.
	 *
	 * The SAME computation the preview showed, recomputed from the case as it
	 * is now: a case that changed since the preview is refused here rather
	 * than rebound on a picture that no longer holds.
	 *
	 * @param RebindMove           $move  The move.
	 * @param array<string, mixed> $asked The case, the remap, the answers and the confirmed drops.
	 *
	 * @return array<string, mixed> The impact.
	 *
	 * @throws RefusedException When any of them does not hold.
	 */
	private function checkedImpact(RebindMove $move, array $asked): array {
		$this->gate->assertTarget(caseId: $move->caseId, case: $asked['case'], targetCaseTypeId: $move->targetCaseTypeId);
		$this->gate->assertStatus(targetCaseTypeId: $move->targetCaseTypeId, targetStatusId: $move->targetStatusId);

		$impact = $this->impact->compute(
			case: $asked['case'],
			targetCaseTypeId: $move->targetCaseTypeId,
			targetStatusId: $move->targetStatusId,
			remap: $asked['remap'],
			answers: $asked['answers']
		);
		$this->gate->assertDropConfirmed(dropped: array_column($impact['dropped'], 'name'), confirmed: $asked['confirmDropped']);
		$this->gate->assertNothingMissing(missing: $this->unanswered(impact: $impact));

		return $impact;
	}//end checkedImpact()

	/**
	 * The case as it reads on its new case type, with the rebind journaled.
	 *
	 * @param RebindMove           $move    The move.
	 * @param array<string, mixed> $applied The case, the source case type, the actor and the impact.
	 *
	 * @return array<string, mixed> The case to write.
	 */
	private function reboundCase(RebindMove $move, array $applied): array {
		$case = $this->impact->apply(case: $applied['case'], impact: $applied['impact']);
		$case['caseType'] = $move->targetCaseTypeId;
		$case['status'] = $move->targetStatusId;
		$case = $this->rebindTemplate(case: $case, targetCaseTypeId: $move->targetCaseTypeId);

		return $move->journal(
			case: $case,
			applied: [
				'fromTitle' => $this->titleOf(caseTypeId: $applied['sourceId']),
				'toTitle' => $this->titleOf(caseTypeId: $move->targetCaseTypeId),
			] + $applied
		);
	}//end reboundCase()

	/**
	 * Log that a running case was rebound.
	 *
	 * @param RebindMove           $move    The move.
	 * @param array<string, mixed> $applied The source case type, the actor and the run.
	 *
	 * @return void
	 */
	private function logRebound(RebindMove $move, array $applied): void {
		$this->logger->info(
			'CaseRebindService: a running case was rebound to another case type',
			[
				'case' => $move->caseId,
				'from' => $applied['sourceId'],
				'to' => $move->targetCaseTypeId,
				'actor' => $applied['actorUid'],
				'runMigrated' => $applied['run']['migrated'],
			]
		);
	}//end logRebound()

	/**
	 * The title of one case type, in the reader's language.
	 *
	 * @param string $caseTypeId The case type.
	 *
	 * @return string The title.
	 */
	private function titleOf(string $caseTypeId): string {
		return $this->text->forReader(value: ($this->store->readCaseType(caseTypeId: $caseTypeId)['title'] ?? null));
	}//end titleOf()

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
				return $this->text->forReader(value: ($status['name'] ?? null));
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
			$this->refuseIndeterminate(
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
			$objectService->updateObject(objectId: $caseId, data: $case);
		} catch (Throwable $e) {
			$this->refuseIndeterminate(
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
			throw new RefusedException(
				rule: 'case-store-unavailable',
				sentence: 'The case register could not be reached, so nothing was rebound.',
				status: RefusedException::STATUS_INDETERMINATE,
			);
		}

		return [$objectService, $register, $schema];
	}//end scope()
}//end class
