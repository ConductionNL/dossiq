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
use OCA\Dossiq\Service\Cases\CaseRebindGate;
use OCA\Dossiq\Service\Cases\CaseRebindImpact;
use OCA\Dossiq\Service\Cases\CaseRebindTerms;
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

		$targets = [];
		foreach ($this->store->everyCaseType() as $row) {
			$id = $this->store->rowId(row: $row);
			if ($id === '' || $id === $sourceId || ($row['isDraft'] ?? false) === true) {
				continue;
			}

			$targets[] = [
				'id' => $id,
				'title' => $this->text->forReader(value: ($row['title'] ?? null)),
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
				'title' => $this->text->forReader(value: ($this->store->readCaseType(caseTypeId: $sourceId)['title'] ?? null)),
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

		$statuses = [];
		foreach ($this->resolver->statusTypesFor(caseTypeId: $targetCaseTypeId) as $status) {
			$id = $this->store->rowId(row: $status);
			if ($id !== '') {
				$statuses[] = ['id' => $id, 'name' => $this->text->forReader(value: ($status['name'] ?? null))];
			}
		}

		$impact = $this->impact->compute(
			case: $case,
			targetCaseTypeId: $targetCaseTypeId,
			targetStatusId: $targetStatusId,
			remap: $remap,
			answers: $properties
		);

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
				'title' => $this->text->forReader(value: ($this->store->readCaseType(caseTypeId: $targetCaseTypeId)['title'] ?? null)),
			],
			'statuses' => $statuses,
			'missingProperties' => $this->unanswered(impact: $impact),
			'impact' => $impact,
			'results' => $this->gate->resultCompatibility(case: $case, targetCaseTypeId: $targetCaseTypeId),
			// The engine seam rides on the PREVIEW too, not only on the write:
			// a coordinator deciding whether to rebind should read what happens
			// to the run before pressing the button, not afterwards.
			'run' => ['moved' => false, 'reason' => EngineRunMigration::RUN_NOT_MOVED],
			'canRebind' => ($targetStatusId !== '' && $impact['complete'] === true),
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
		$reason = $this->gate->assertMayRebind(actorUid: $actorUid, reason: $reason);

		$case = $this->readCase(caseId: $caseId);
		$sourceId = $this->store->referenceId(value: ($case['caseType'] ?? ''));
		$this->gate->assertTarget(caseId: $caseId, case: $case, targetCaseTypeId: $targetCaseTypeId);
		$this->gate->assertStatus(targetCaseTypeId: $targetCaseTypeId, targetStatusId: $targetStatusId);

		// The SAME computation the preview showed, recomputed from the case as
		// it is now: a case that changed since the preview is refused below
		// rather than rebound on a picture that no longer holds.
		$impact = $this->impact->compute(
			case: $case,
			targetCaseTypeId: $targetCaseTypeId,
			targetStatusId: $targetStatusId,
			remap: $remap,
			answers: $properties
		);
		$this->gate->assertDropConfirmed(dropped: array_column($impact['dropped'], 'name'), confirmed: $confirmDropped);
		$this->gate->assertNothingMissing(missing: $this->unanswered(impact: $impact));

		// D-2 step 2, BEFORE any write: a run that refuses to move leaves a
		// case whose blueprint and whose process disagree.
		$run = $this->engine->migrate(
			caseId: $caseId,
			targetCaseTypeId: $targetCaseTypeId,
			actorUid: $actorUid
		);

		$case = $this->impact->apply(case: $case, impact: $impact);
		$case['caseType'] = $targetCaseTypeId;
		$case['status'] = $targetStatusId;
		$case = $this->rebindTemplate(case: $case, targetCaseTypeId: $targetCaseTypeId);
		$case = $this->journal(
			case: $case,
			sourceId: $sourceId,
			targetCaseTypeId: $targetCaseTypeId,
			targetStatusId: $targetStatusId,
			reason: $reason,
			actorUid: $actorUid,
			impact: $impact
		);

		$this->write(caseId: $caseId, case: $case);

		// THE SLUG, NOT THE UUID: {@see CaseRebindTerms::rearm()} keys the
		// re-arm by the target's slug, because a uuid matches no definition.
		$terms = $this->terms->rearm(caseId: $caseId, targetCaseTypeId: $targetCaseTypeId, reason: $reason);

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
			'properties' => [
				'dropped' => array_column($impact['dropped'], 'name'),
				'ported' => array_map(
					static fn (array $row): array => ['from' => $row['source'], 'to' => $row['target']],
					$impact['ported']
				),
				'answered' => array_column($impact['required'], 'name'),
			],
		];
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
	 * Record the rebind on the case's own journal, with both bindings.
	 *
	 * @param array<string, mixed> $case             The case.
	 * @param string               $sourceId         The case type it leaves.
	 * @param string               $targetCaseTypeId The case type it joins.
	 * @param string               $targetStatusId   The status it lands in.
	 * @param string               $reason           Why.
	 * @param string               $actorUid         Who.
	 * @param array<string, mixed> $impact           What happened to the answers.
	 *
	 * @return array<string, mixed> The case, with the entry appended.
	 *
	 * @spec openspec/changes/case-type-rebind/specs/zaaktype-versioning/spec.md
	 * @spec openspec/specs/zaaktype-versioning/spec.md
	 */
	private function journal(
		array $case,
		string $sourceId,
		string $targetCaseTypeId,
		string $targetStatusId,
		string $reason,
		string $actorUid,
		array $impact,
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
			'fromCaseTypeTitle' => $this->text->forReader(value: ($this->store->readCaseType(caseTypeId: $sourceId)['title'] ?? null)),
			'toCaseType' => $targetCaseTypeId,
			'toCaseTypeTitle' => $this->text->forReader(value: ($this->store->readCaseType(caseTypeId: $targetCaseTypeId)['title'] ?? null)),
			'status' => $targetStatusId,
			'reason' => $reason,
			'actor' => $actorUid,
			// THE DROPPED VALUES ARE KEPT HERE, value and all. They leave the
			// case's `properties` in this same write, and this entry is where
			// a reader finds what the case said before it moved.
			'droppedProperties' => array_map(
				static fn (array $row): array => ['name' => $row['name'], 'value' => $row['value']],
				$impact['dropped']
			),
			'portedProperties' => array_map(
				static fn (array $row): array => [
					'from' => $row['source'],
					'to' => $row['target'],
					'value' => $row['value'],
					'newValue' => $row['newValue'],
				],
				$impact['ported']
			),
			'answeredProperties' => array_column($impact['required'], 'name'),
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
			$objectService->updateObject($register, $schema, $caseId, $case);
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
