<?php

/**
 * Dossiq Case Merge Service
 *
 * Dossiq's half of a merge. OpenRegister owns the merge itself (ADR-045): it
 * relinks its own source records, flips `mergeState`, writes the
 * `mergeOperation` row and raises the event. What is left over is the part
 * only a case management system knows about, and that is what this service
 * does:
 *
 *   - the dossiq-owned rows that hang off a case (roles, documents, objects,
 *     properties, contact moments, decisions) move to the survivor, and the
 *     moves are written down so a reversal can put them back;
 *   - the merged case says where it went (`mergedInto`) and how it ended
 *     (`endingAct: merged`);
 *   - its running term is completed, because the survivor's term is the one
 *     the applicant is owed an answer within.
 *
 * The rule lives on the schema, not here. `x-openregister-merge` on `case`
 * names the reversal window, the state field and which kinds relink, and this
 * service reads it. A rule read from the shipped register is the same rule the
 * import wrote, so the two cannot drift apart in one direction only.
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
 * @spec openspec/changes/case-merge/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\Service\Settings\SchemaSlugMap;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The dossiq consequences of an OpenRegister merge on a `case`.
 *
 * @spec openspec/changes/case-merge/specs/case-management/spec.md#requirement-two-cases-merge-into-one-through-the-platform-req-cm-37
 */
class CaseMergeService {
	use SearchesObjects;

	/**
	 * The register every dossiq schema lives in.
	 */
	private const REGISTER_CONFIG_KEY = 'register';

	/**
	 * The `case` schema's config key.
	 */
	private const CASE_SCHEMA_CONFIG_KEY = 'case_schema';

	/**
	 * The shipped register, which is where the merge rule is declared.
	 */
	private const REGISTER_JSON = __DIR__ . '/../Settings/dossiq_register.json';

	/**
	 * Term statuses that still count as running, and so are the ones a merge
	 * closes. Mirrors `CaseDeleteGuardListener::OPEN_TERM_STATUSES`, which asks
	 * the same question about the same rows.
	 */
	private const OPEN_TERM_STATUSES = ['lopend', 'verlengd', 'paused'];

	/**
	 * The ending act a merged case carries.
	 */
	public const ENDING_ACT_MERGED = 'merged';

	/**
	 * How far `resolveSurvivor()` follows the chain before it gives up. A
	 * merge of a merge is ordinary; a cycle is not, and a cycle with no
	 * ceiling is a request that never returns.
	 */
	private const MAX_HOPS = 16;

	/**
	 * OpenRegister's merge engine, reached by name so dossiq still enables
	 * without it.
	 */
	private const MERGE_SERVICE_CLASS = 'OCA\\OpenRegister\\Service\\Merge\\MergeService';

	/**
	 * The merge rule, read once per request.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $rule = null;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Register and schema ids, and the object service.
	 * @param TermijnService  $termijnService  The one writer of a term instance.
	 * @param LoggerInterface $logger          Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly TermijnService $termijnService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The `x-openregister-merge` rule the `case` schema declares.
	 *
	 * @return array<string, mixed> The rule, empty when the register cannot be read.
	 *
	 * @spec openspec/changes/case-merge/specs/case-management/spec.md#requirement-two-cases-merge-into-one-through-the-platform-req-cm-37
	 */
	public function mergeRule(): array {
		if ($this->rule !== null) {
			return $this->rule;
		}

		$this->rule = [];

		try {
			$raw = file_get_contents(self::REGISTER_JSON);
			$decoded = json_decode((string)$raw, true);
			$case = ($decoded['components']['schemas']['case'] ?? []);
			$declared = ($case['configuration']['x-openregister-merge'] ?? []);
			if (is_array($declared) === true) {
				$this->rule = $declared;
			}
		} catch (Throwable $e) {
			$this->logger->warning('Dossiq: the case merge rule could not be read: ' . $e->getMessage());
		}

		return $this->rule;
	}//end mergeRule()

	/**
	 * Follow `mergedInto` until it reaches a case that carries none.
	 *
	 * This is what makes an old case number keep working: mail matching and
	 * the public status page ask this question and act on the answer, so a
	 * reply to a merged case lands where the work actually is.
	 *
	 * An unreadable case, a cycle or a chain longer than {@see MAX_HOPS}
	 * answers the id it was given. That is the safe direction: filing a reply
	 * on the case it names is wrong only in the way it was already wrong.
	 *
	 * @param string $caseId The case uuid a caller matched.
	 *
	 * @return string The surviving case's uuid, or the input when there is none.
	 *
	 * @spec openspec/changes/case-merge/specs/case-management/spec.md#requirement-the-old-number-still-finds-the-case-req-cm-38
	 */
	public function resolveSurvivor(string $caseId): string {
		$current = trim($caseId);
		if ($current === '') {
			return $caseId;
		}

		$seen = [];
		for ($hop = 0; $hop < self::MAX_HOPS; $hop++) {
			if (isset($seen[$current]) === true) {
				$this->logger->warning('Dossiq: a merged-case chain loops at "' . $current . '"');
				return $caseId;
			}

			$seen[$current] = true;

			$case = $this->readCase(caseId: $current);
			if ($case === null) {
				return $current;
			}

			$next = $this->referencedId(value: ($case['mergedInto'] ?? null));
			if ($next === '' || $next === $current) {
				return $current;
			}

			$current = $next;
		}

		$this->logger->warning('Dossiq: a merged-case chain is longer than ' . self::MAX_HOPS . ' hops');

		return $caseId;
	}//end resolveSurvivor()

	/**
	 * Whether this case may be merged away.
	 *
	 * The same two refusals a delete carries, for the same reason: a case that
	 * has been decided is a record of what was decided, and a merge would make
	 * that record point somewhere else.
	 *
	 * @param array<string, mixed> $case The case payload.
	 *
	 * @return bool True when it may be merged away.
	 *
	 * @spec openspec/changes/case-merge/specs/case-management/spec.md#requirement-two-cases-merge-into-one-through-the-platform-req-cm-37
	 */
	public function isMergeable(array $case): bool {
		return $this->refusalFor(case: $case) === '';
	}//end isMergeable()

	/**
	 * Which rule refuses this case as a merge source, if any.
	 *
	 * The rule is named rather than counted, because a caseworker who is told
	 * no is owed the reason: a decided case and an already merged one are
	 * refused for different reasons and have different ways out.
	 *
	 * @param array<string, mixed> $case The case payload.
	 *
	 * @return string The rule, empty when the case may be merged away.
	 *
	 * @spec openspec/changes/case-merge/specs/case-management/spec.md#requirement-two-cases-merge-into-one-through-the-platform-req-cm-37
	 */
	public function refusalFor(array $case): string {
		if (($case['isFinalStatus'] ?? false) === true) {
			return 'final-status';
		}

		if ($this->referencedId(value: ($case['besluitDocument'] ?? null)) !== '') {
			return 'signed-beschikking';
		}

		if ($this->referencedId(value: ($case['mergedInto'] ?? null)) !== '') {
			return 'already-merged';
		}

		return '';
	}//end refusalFor()

	/**
	 * Ask OpenRegister to merge one case into another.
	 *
	 * Dossiq decides whether this case may be merged away, because the rules
	 * that refuse it are case management's; OpenRegister does the merge,
	 * because the merge is the platform's (ADR-045). The refusal is written
	 * here and not only in the browser: an action the browser hides is still
	 * an endpoint anyone may call.
	 *
	 * @param string $mergedId   The case to merge away.
	 * @param string $survivorId The case it becomes part of.
	 * @param string $reason     What the caseworker typed.
	 * @param string $actor      The acting user's uid.
	 *
	 * @return array{refused?: string, operation?: array<string, mixed>} The outcome.
	 *
	 * @spec openspec/changes/case-merge/specs/case-management/spec.md#requirement-two-cases-merge-into-one-through-the-platform-req-cm-37
	 */
	public function requestMerge(string $mergedId, string $survivorId, string $reason, string $actor): array {
		if ($mergedId === '' || $survivorId === '' || $mergedId === $survivorId) {
			return ['refused' => 'same-case'];
		}

		$source = $this->readCase(caseId: $mergedId);
		$survivor = $this->readCase(caseId: $survivorId);
		if ($source === null || $survivor === null) {
			return ['refused' => 'unknown-case'];
		}

		$refusal = $this->refusalFor(case: $source);
		if ($refusal !== '') {
			return ['refused' => $refusal];
		}

		if ($this->referencedId(value: ($survivor['mergedInto'] ?? null)) !== '') {
			return ['refused' => 'survivor-already-merged'];
		}

		$merger = $this->settingsService->getOpenRegisterClass(class: self::MERGE_SERVICE_CLASS);
		if ($merger === null || method_exists($merger, 'executeMerge') === false) {
			return ['refused' => 'platform-unavailable'];
		}

		try {
			$operation = $merger->executeMerge($mergedId, $survivorId, $reason, $actor);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: OpenRegister refused the merge of "' . $mergedId . '": ' . $e->getMessage()
			);
			return ['refused' => 'platform-refused'];
		}

		return ['operation' => (array)$operation];
	}//end requestMerge()

	/**
	 * Apply dossiq's consequences of a merge.
	 *
	 * @param string $mergedId   The case that was merged away.
	 * @param string $survivorId The case it was merged into.
	 *
	 * @return bool True when the merged case was updated.
	 *
	 * @spec openspec/changes/case-merge/specs/case-management/spec.md#requirement-two-cases-merge-into-one-through-the-platform-req-cm-37
	 */
	public function applyMerge(string $mergedId, string $survivorId): bool {
		if ($mergedId === '' || $survivorId === '' || $mergedId === $survivorId) {
			return false;
		}

		$moved = $this->relink(fromId: $mergedId, toId: $survivorId);

		$written = $this->patchCase(
			caseId: $mergedId,
			changes: [
				'mergedInto' => $survivorId,
				'endingAct' => self::ENDING_ACT_MERGED,
				'mergeRelinked' => $moved,
			]
		);

		$this->closeTerm(caseId: $mergedId, survivorId: $survivorId);

		return $written;
	}//end applyMerge()

	/**
	 * Undo dossiq's consequences when the platform reverses the merge.
	 *
	 * @param string $mergedId   The case that was merged away.
	 * @param string $survivorId The case it had been merged into.
	 *
	 * @return bool True when the merged case was updated.
	 *
	 * @spec openspec/changes/case-merge/specs/case-management/spec.md#requirement-two-cases-merge-into-one-through-the-platform-req-cm-37
	 */
	public function applyReversal(string $mergedId, string $survivorId): bool {
		if ($mergedId === '') {
			return false;
		}

		$case = $this->readCase(caseId: $mergedId);
		$moved = (array)(($case['mergeRelinked'] ?? []));
		$this->moveBack(moves: $moved, toId: $mergedId);

		$written = $this->patchCase(
			caseId: $mergedId,
			changes: [
				'mergedInto' => null,
				'endingAct' => null,
				'mergeRelinked' => [],
			]
		);

		$this->rearmTerm(caseId: $mergedId, survivorId: $survivorId);

		return $written;
	}//end applyReversal()

	/**
	 * Move every declared kind of row from the merged case to the survivor.
	 *
	 * @param string $fromId The merged case.
	 * @param string $toId   The survivor.
	 *
	 * @return array<int, array{schema: string, id: string}> The moves, for a reversal.
	 */
	private function relink(string $fromId, string $toId): array {
		$moves = [];

		foreach ($this->relinkDeclarations() as $declaration) {
			$slug = (string)($declaration['schema'] ?? '');
			$field = (string)($declaration['field'] ?? '');
			$schemaId = $this->schemaId(slug: $slug);
			if ($slug === '' || $field === '' || $schemaId === '') {
				continue;
			}

			foreach ($this->rowsFor(schemaId: $schemaId, filters: [$field => $fromId]) as $row) {
				$id = (string)($row['id'] ?? '');
				if ($id === '') {
					continue;
				}

				if ($this->patchRow(schemaId: $schemaId, id: $id, changes: [$field => $toId]) === false) {
					continue;
				}

				$moves[] = [
					'schema' => $slug,
					'id' => $id,
				];
			}
		}

		return $moves;
	}//end relink()

	/**
	 * Put the recorded moves back on the case they came from.
	 *
	 * @param array<int, mixed> $moves The moves written at merge time.
	 * @param string            $toId  The case they belong to again.
	 *
	 * @return void
	 */
	private function moveBack(array $moves, string $toId): void {
		$fields = [];
		foreach ($this->relinkDeclarations() as $declaration) {
			$fields[(string)($declaration['schema'] ?? '')] = (string)($declaration['field'] ?? '');
		}

		foreach ($moves as $move) {
			if (is_array($move) === false) {
				continue;
			}

			$slug = (string)($move['schema'] ?? '');
			$id = (string)($move['id'] ?? '');
			$field = (string)($fields[$slug] ?? '');
			$schemaId = $this->schemaId(slug: $slug);
			if ($id === '' || $field === '' || $schemaId === '') {
				continue;
			}

			$this->patchRow(schemaId: $schemaId, id: $id, changes: [$field => $toId]);
		}
	}//end moveBack()

	/**
	 * The declared relink pairs.
	 *
	 * @return array<int, array<string, mixed>> Each with a `schema` slug and a `field`.
	 */
	private function relinkDeclarations(): array {
		$declared = ($this->mergeRule()['x-dossiq-relink'] ?? []);
		if (is_array($declared) === false) {
			return [];
		}

		return array_values(array_filter($declared, static fn (mixed $row): bool => is_array($row) === true));
	}//end relinkDeclarations()

	/**
	 * Complete the merged case's running term, naming the merge as the reason.
	 *
	 * @param string $caseId     The merged case.
	 * @param string $survivorId The survivor, named in the term event.
	 *
	 * @return void
	 */
	private function closeTerm(string $caseId, string $survivorId): void {
		$instance = $this->termijnService->getTermijnInstanceForZaak(caseId: $caseId);
		if ($instance === null) {
			return;
		}

		if (in_array((string)($instance['status'] ?? ''), self::OPEN_TERM_STATUSES, true) === false) {
			return;
		}

		$this->termijnService->markTermijnCompleted(
			termInstanceId: (string)($instance['id'] ?? ''),
			rationale: 'Termijn voltooid: zaak samengevoegd met ' . $survivorId
		);
	}//end closeTerm()

	/**
	 * Re-arm the term the merge completed, from the completed instance's own
	 * dates. A reversal means the merge never should have happened, so the
	 * clock the applicant was owed runs again from where it stood.
	 *
	 * @param string $caseId     The case that is its own case again.
	 * @param string $survivorId The case it had been merged into.
	 *
	 * @return void
	 */
	private function rearmTerm(string $caseId, string $survivorId): void {
		$completed = null;
		foreach ($this->termijnService->instancesForCase(caseId: $caseId) as $instance) {
			if ((string)($instance['status'] ?? '') === 'completed') {
				$completed = $instance;
				break;
			}
		}

		if ($completed === null) {
			return;
		}

		unset($completed['id'], $completed['@self'], $completed['voltooiDatum']);
		$completed['status'] = 'lopend';
		$completed['case'] = $caseId;

		$this->termijnService->saveTermInstance(instance: $completed);

		$this->logger->info(
			'Dossiq: the term of case "' . $caseId . '" runs again after the merge into "'
			. $survivorId . '" was reversed'
		);
	}//end rearmTerm()

	/**
	 * Read a case as an array.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return array<string, mixed>|null The case, or null when it cannot be read.
	 */
	private function readCase(string $caseId): ?array {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->registerId();
		$schema = $this->caseSchemaId();
		if ($objectService === null || $register === '' || $schema === '') {
			return null;
		}

		try {
			return $this->findObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				id: $caseId
			);
		} catch (Throwable $e) {
			$this->logger->warning('Dossiq: case "' . $caseId . '" could not be read: ' . $e->getMessage());
			return null;
		}
	}//end readCase()

	/**
	 * Write a few fields onto a case.
	 *
	 * @param string               $caseId  The case uuid.
	 * @param array<string, mixed> $changes The fields to write.
	 *
	 * @return bool True when the write landed.
	 */
	private function patchCase(string $caseId, array $changes): bool {
		return $this->patchRow(schemaId: $this->caseSchemaId(), id: $caseId, changes: $changes);
	}//end patchCase()

	/**
	 * Write a few fields onto one row of any dossiq schema.
	 *
	 * @param string               $schemaId The schema id.
	 * @param string               $id       The row uuid.
	 * @param array<string, mixed> $changes  The fields to write.
	 *
	 * @return bool True when the write landed.
	 */
	private function patchRow(string $schemaId, string $id, array $changes): bool {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->registerId();
		if ($objectService === null || $register === '' || $schemaId === '') {
			return false;
		}

		try {
			return $this->patchObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schemaId,
				id: $id,
				changes: $changes
			) !== null;
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: a merge write on "' . $id . '" failed: ' . $e->getMessage()
			);
			return false;
		}
	}//end patchRow()

	/**
	 * Every row of a schema that points at one case.
	 *
	 * @param string               $schemaId The schema id.
	 * @param array<string, mixed> $filters  The field filter.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function rowsFor(string $schemaId, array $filters): array {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->registerId();
		if ($objectService === null || $register === '') {
			return [];
		}

		try {
			return $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $schemaId,
				filters: $filters
			);
		} catch (Throwable $e) {
			$this->logger->warning('Dossiq: a merge read failed: ' . $e->getMessage());
			return [];
		}
	}//end rowsFor()

	/**
	 * The configured register id.
	 *
	 * @return string The id, empty when unconfigured.
	 */
	private function registerId(): string {
		return (string)$this->settingsService->getConfigValue(self::REGISTER_CONFIG_KEY);
	}//end registerId()

	/**
	 * The configured `case` schema id.
	 *
	 * @return string The id, empty when unconfigured.
	 */
	private function caseSchemaId(): string {
		return (string)$this->settingsService->getConfigValue(self::CASE_SCHEMA_CONFIG_KEY);
	}//end caseSchemaId()

	/**
	 * The configured schema id behind a schema slug.
	 *
	 * @param string $slug The schema slug as the register declares it.
	 *
	 * @return string The id, empty when the slug is unknown or unconfigured.
	 */
	private function schemaId(string $slug): string {
		$configKey = (string)(SchemaSlugMap::SLUG_TO_CONFIG_KEY[$slug] ?? '');
		if ($configKey === '') {
			return '';
		}

		return (string)$this->settingsService->getConfigValue($configKey);
	}//end schemaId()

	/**
	 * The uuid behind a reference, which OpenRegister hands back either as the
	 * bare id or as the extended object it points at.
	 *
	 * @param mixed $value The stored reference.
	 *
	 * @return string The uuid, empty when there is none.
	 */
	private function referencedId(mixed $value): string {
		if (is_string($value) === true) {
			return trim($value);
		}

		if (is_array($value) === true) {
			return trim((string)($value['id'] ?? ''));
		}

		return '';
	}//end referencedId()

}//end class
