<?php

/**
 * Splitting a case in two by MOVING what the handler chose.
 *
 * Two complaints arrive on one form and turn out to be about two different
 * departments. `CaseCopyService` opens a second numbered case and relates it
 * back, and `DeelzaakService` hangs a sub-case underneath, and both carry the
 * file across whole. Nobody could say "these four documents and this
 * counter-party go to the new case, the rest stays here", so the split left two
 * cases each holding everything and the handler cleaned up by hand, or did not.
 *
 * 🔴 A SPLIT MOVES, A COPY DUPLICATES (D-1). The material leaves the first
 * case. If it does not leave, the split has produced two cases that both claim
 * the same document, which is the defect rather than the feature. So a chosen
 * item is re-pointed at the new case and a REFERENCE is left behind, because
 * the original file still has to read as a whole.
 *
 * 🔴 THE HANDLER CHOOSES, THE CASE TYPE BOUNDS THE CHOICE (D-2). Which
 * documents belong to which half is a judgment about content and only the
 * handler has it. What may be divided at all is a rule that does not change per
 * case, so it is declared on the case type and the refusal names it.
 *
 * ⚠️ TASKS ARE DECLARABLE AND NOT YET MOVABLE, and this is said here rather
 * than discovered from a surprising refusal. A dossiq task lives in the engine,
 * whose task carries the case as its `objectUuid`, and `EngineTaskGateway`
 * offers create, claim, reassign and complete and NO verb that moves a task to
 * another object. So `tasks` is part of the declared vocabulary, a case type
 * can forbid it, and asking to move one is refused with a rule that names the
 * missing verb rather than silently moving nothing.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Split
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Split;

use DateTimeImmutable;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Divide one case into two, moving documents and parties.
 *
 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
 */
class CaseSplitService {

	use SearchesObjects;

	/**
	 * The kinds a case type may allow a split to divide.
	 *
	 * An absent declaration allows all three, so shipping this changes nothing
	 * for a case type nobody has administered.
	 *
	 * @var array<int, string>
	 */
	public const DIVISIBLE = ['documents', 'parties', 'tasks'];

	/**
	 * Which schema and field each divisible kind lives on.
	 *
	 * The same shape the merge's `x-dossiq-relink` uses, because moving a child
	 * to another case is the same act in both directions: the child's `case`
	 * field is re-pointed and nothing is copied.
	 *
	 * @var array<string, array{schema: string, key: string}>
	 */
	public const KIND_SOURCES = [
		'documents' => ['schema' => 'case_document_schema', 'key' => 'case'],
		'parties' => ['schema' => 'role_schema', 'key' => 'case'],
	];

	/**
	 * The relation a split writes, the one a copy already writes.
	 *
	 * @var string
	 */
	public const RELATION = 'vervolg';

	/**
	 * The refusal when the case cannot be read.
	 *
	 * @var string
	 */
	public const CASE_UNREADABLE = 'split-case-unreadable';

	/**
	 * The refusal when the case type forbids dividing a chosen kind.
	 *
	 * @var string
	 */
	public const FORBIDDEN = 'split-forbidden-by-case-type';

	/**
	 * The refusal when a kind cannot be moved at all yet.
	 *
	 * @var string
	 */
	public const UNMOVABLE = 'split-kind-not-movable';

	/**
	 * The refusal when nothing was chosen.
	 *
	 * @var string
	 */
	public const NOTHING_CHOSEN = 'split-nothing-chosen';

	/**
	 * The refusal when the new case could not be written.
	 *
	 * @var string
	 */
	public const UNWRITABLE = 'split-unwritable';

	/**
	 * How many children one read takes at most.
	 *
	 * @var int
	 */
	public const PAGE_SIZE = 500;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Bridge to OpenRegister and the configured schemas.
	 * @param LoggerInterface $logger          Records every split and every refusal.
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * What this case's type allows a split to divide.
	 *
	 * @param array<string, mixed> $caseType The effective case type.
	 *
	 * @return array<int, string> The allowed kinds.
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-a-case-type-declares-what-a-split-may-divide-req-spl-03
	 */
	public function divisibleFor(array $caseType): array {
		$declared = ($caseType['splitMayDivide'] ?? null);
		if (is_array($declared) === false) {
			// ABSENT IS ALL THREE, not none. An empty default would forbid
			// every split on every case type until somebody administered one,
			// which is a feature that ships switched off and looks broken.
			return self::DIVISIBLE;
		}

		$allowed = [];
		foreach ($declared as $kind) {
			$kind = trim((string)$kind);
			if (in_array($kind, self::DIVISIBLE, true) === true) {
				$allowed[] = $kind;
			}
		}

		return $allowed;
	}//end divisibleFor()

	/**
	 * Split a case, moving the chosen material to a new one.
	 *
	 * @param string                                                                             $caseId The case being split.
	 * @param string                                                                             $title  The new case's title.
	 * @param array{documents?: array<int, string>, parties?: array<int, string>, tasks?: array<int, string>, partiesOnBoth?: array<int, string>} $chosen What goes to the new case.
	 * @param string                                                                             $actor  Who split it.
	 *
	 * @return array{case: array<string, mixed>, moved: array<int, array<string, mixed>>} The new case and what moved.
	 *
	 * @throws RefusedException When the case cannot be read, the case type forbids the division, or the write fails.
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-a-case-splits-by-moving-what-was-chosen-req-spl-01
	 */
	public function split(string $caseId, string $title, array $chosen, string $actor): array {
		$caseId = trim($caseId);
		$case = $this->requireCase(caseId: $caseId);
		$caseType = $this->caseTypeOf(case: $case);

		$this->refuseForbiddenKinds(chosen: $chosen, caseType: $caseType);

		$moving = $this->itemsToMove(caseId: $caseId, chosen: $chosen);
		$staying = $this->partiesOnBoth(caseId: $caseId, chosen: $chosen);
		if ($moving === [] && $staying === []) {
			throw new RefusedException(
				rule: self::NOTHING_CHOSEN,
				sentence: 'Choose what goes to the new case, so the split divides something.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		$newCase = $this->openNewCase(source: $case, title: $title, actor: $actor);
		$newId = $this->uuidOf(row: $newCase);
		$now = (new DateTimeImmutable())->format('c');

		$moved = [];
		foreach ($moving as $item) {
			if ($this->repoint(item: $item, toId: $newId) === false) {
				continue;
			}

			$moved[] = [
				'schema' => $item['schema'],
				'id' => $item['id'],
				'movedTo' => $newId,
				'movedAt' => $now,
			];
		}

		foreach ($staying as $item) {
			$this->duplicateOnto(item: $item, toId: $newId);
		}

		// THE REFERENCE, WRITTEN AND NOT DERIVED. Once a document has moved it
		// no longer names this case, so nothing could reconstruct what left.
		$this->patchCase(
			caseId: $caseId,
			case: $case,
			changes: [
				'splitInto' => $newId,
				'splitMovedItems' => array_merge(
					$this->existingMovedItems(case: $case),
					$moved,
				),
			],
		);

		$this->logger->info(
			'Dossiq split: a case was divided',
			['case' => $caseId, 'into' => $newId, 'moved' => count($moved), 'onBoth' => count($staying)],
		);

		return ['case' => $newCase, 'moved' => $moved];
	}//end split()

	/**
	 * Refuse a division the case type does not allow, or that cannot be made.
	 *
	 * @param array<string, mixed> $chosen   What the handler picked.
	 * @param array<string, mixed> $caseType The effective case type.
	 *
	 * @return void
	 *
	 * @throws RefusedException Naming the rule that refused.
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-a-case-type-declares-what-a-split-may-divide-req-spl-03
	 */
	private function refuseForbiddenKinds(array $chosen, array $caseType): void {
		$allowed = $this->divisibleFor(caseType: $caseType);

		foreach (self::DIVISIBLE as $kind) {
			if ($this->chosenIds(chosen: $chosen, kind: $kind) === []) {
				continue;
			}

			if (in_array($kind, $allowed, true) === false) {
				throw new RefusedException(
					rule: self::FORBIDDEN,
					sentence: 'This case type does not allow a split to divide ' . $kind . '.',
					status: RefusedException::STATUS_REFUSED,
				);
			}

			if (array_key_exists($kind, self::KIND_SOURCES) === false) {
				throw new RefusedException(
					rule: self::UNMOVABLE,
					sentence: 'A task cannot be moved to another case yet: the engine has no verb for it, '
						. 'so nothing was split rather than the task being silently left behind.',
					status: RefusedException::STATUS_REFUSED,
				);
			}
		}
	}//end refuseForbiddenKinds()

	/**
	 * The stored rows the handler chose, checked against the case they are on.
	 *
	 * 🔴 EVERY CHOSEN ID IS READ BACK OFF THIS CASE. Taking the ids on trust
	 * would let a caller move a document off somebody else's case by naming its
	 * id, which is an IDOR wearing a split's clothes.
	 *
	 * @param string               $caseId The case being split.
	 * @param array<string, mixed> $chosen What the handler picked.
	 *
	 * @return array<int, array{schema: string, key: string, id: string, row: array<string, mixed>}> The rows.
	 */
	private function itemsToMove(string $caseId, array $chosen): array {
		$items = [];

		foreach (self::KIND_SOURCES as $kind => $source) {
			$wanted = $this->chosenIds(chosen: $chosen, kind: $kind);
			if ($wanted === []) {
				continue;
			}

			foreach ($this->childrenOf(caseId: $caseId, source: $source) as $row) {
				$id = $this->uuidOf(row: $row);
				if ($id !== '' && in_array($id, $wanted, true) === true) {
					$items[] = ['schema' => $source['schema'], 'key' => $source['key'], 'id' => $id, 'row' => $row];
				}
			}
		}

		return $items;
	}//end itemsToMove()

	/**
	 * The parties the handler marked as relevant to both halves.
	 *
	 * A party on a case is a ROLE, not a copy of a person, so a party relevant
	 * to both halves is present on both with its role intact (D-3). These are
	 * duplicated rather than moved, and they are the only thing this service
	 * duplicates.
	 *
	 * @param string               $caseId The case being split.
	 * @param array<string, mixed> $chosen What the handler picked.
	 *
	 * @return array<int, array{schema: string, key: string, id: string, row: array<string, mixed>}> The rows.
	 */
	private function partiesOnBoth(string $caseId, array $chosen): array {
		$wanted = $this->chosenIds(chosen: $chosen, kind: 'partiesOnBoth');
		if ($wanted === []) {
			return [];
		}

		$source = self::KIND_SOURCES['parties'];
		$items = [];
		foreach ($this->childrenOf(caseId: $caseId, source: $source) as $row) {
			$id = $this->uuidOf(row: $row);
			if ($id !== '' && in_array($id, $wanted, true) === true) {
				$items[] = ['schema' => $source['schema'], 'key' => $source['key'], 'id' => $id, 'row' => $row];
			}
		}

		return $items;
	}//end partiesOnBoth()

	/**
	 * The ids the handler chose for one kind.
	 *
	 * @param array<string, mixed> $chosen What the handler picked.
	 * @param string               $kind   The kind.
	 *
	 * @return array<int, string> The ids.
	 */
	private function chosenIds(array $chosen, string $kind): array {
		$ids = [];
		foreach ((array)($chosen[$kind] ?? []) as $id) {
			$id = trim((string)$id);
			if ($id !== '') {
				$ids[] = $id;
			}
		}

		return $ids;
	}//end chosenIds()

	/**
	 * The child rows of one kind on this case.
	 *
	 * @param string                            $caseId The case.
	 * @param array{schema: string, key: string} $source Which schema and field.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function childrenOf(string $caseId, array $source): array {
		try {
			[$objectService, $register] = $this->context();

			return $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $this->schema(key: $source['schema']),
				filters: [$source['key'] => $caseId, '_limit' => self::PAGE_SIZE],
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq split: the children of a case could not be read',
				['case' => $caseId, 'schema' => $source['schema'], 'exception' => $e->getMessage()],
			);

			return [];
		}
	}//end childrenOf()

	/**
	 * Point one child at the new case.
	 *
	 * @param array{schema: string, key: string, id: string, row: array<string, mixed>} $item The child.
	 * @param string                                                                    $toId The new case.
	 *
	 * @return bool True when it moved.
	 */
	private function repoint(array $item, string $toId): bool {
		$payload = array_merge($item['row'], [$item['key'] => $toId]);
		unset($payload['@self'], $payload['id'], $payload['uuid']);

		try {
			[$objectService, $register] = $this->context();
			$objectService->saveObject(
				object: $payload,
				register: $register,
				schema: $this->schema(key: $item['schema']),
				uuid: $item['id'],
			);

			return true;
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq split: an item did not move',
				['schema' => $item['schema'], 'id' => $item['id'], 'exception' => $e->getMessage()],
			);

			return false;
		}
	}//end repoint()

	/**
	 * Put a copy of one child on the new case, leaving the original where it is.
	 *
	 * @param array{schema: string, key: string, id: string, row: array<string, mixed>} $item The child.
	 * @param string                                                                    $toId The new case.
	 *
	 * @return void
	 */
	private function duplicateOnto(array $item, string $toId): void {
		$payload = array_merge($item['row'], [$item['key'] => $toId]);
		unset($payload['@self'], $payload['id'], $payload['uuid']);

		try {
			[$objectService, $register] = $this->context();
			$objectService->saveObject(
				object: $payload,
				register: $register,
				schema: $this->schema(key: $item['schema']),
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq split: a party relevant to both halves reached only one of them',
				['schema' => $item['schema'], 'id' => $item['id'], 'exception' => $e->getMessage()],
			);
		}
	}//end duplicateOnto()

	/**
	 * Open the second case, naming the first.
	 *
	 * The relation is written as `relatedCases`, a JSON-ENCODED string of typed
	 * relations, because that is what the schema declares and what
	 * `CaseRelationCodec` reads. Writing an array here would store a shape
	 * nothing reads and the Related tab would be empty on every split.
	 *
	 * @param array<string, mixed> $source The case being split.
	 * @param string               $title  The new case's title.
	 * @param string               $actor  Who split it.
	 *
	 * @return array<string, mixed> The new case.
	 *
	 * @throws RefusedException When the write is refused.
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-the-split-is-related-in-both-directions-req-spl-02
	 */
	private function openNewCase(array $source, string $title, string $actor): array {
		$sourceId = $this->uuidOf(row: $source);
		$title = trim($title);
		if ($title === '') {
			$title = 'Split from ' . (string)($source['title'] ?? '');
		}

		$payload = [
			'title' => $title,
			'caseType' => (string)($source['caseType'] ?? ''),
			'status' => (string)($source['status'] ?? ''),
			'assignedGroup' => (string)($source['assignedGroup'] ?? ''),
			'assignee' => (string)($source['assignee'] ?? ''),
			'impact' => (string)($source['impact'] ?? 'medium'),
			'urgency' => (string)($source['urgency'] ?? 'medium'),
			'splitFrom' => $sourceId,
			'relatedCases' => (string)json_encode(
				[
					[
						'caseId' => $sourceId,
						'aardRelatie' => self::RELATION,
						'toelichting' => 'Split from this case by ' . $actor,
					],
				]
			),
		];

		try {
			[$objectService, $register] = $this->context();
			$saved = $this->saveObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $this->schema(key: 'case_schema'),
				object: $payload,
			);
		} catch (Throwable $e) {
			throw new RefusedException(
				rule: self::UNWRITABLE,
				sentence: 'The second case could not be opened, so nothing was moved.',
				status: RefusedException::STATUS_INDETERMINATE,
				previous: $e,
			);
		}

		if ($saved === null) {
			throw new RefusedException(
				rule: self::UNWRITABLE,
				sentence: 'The second case could not be opened, so nothing was moved.',
				status: RefusedException::STATUS_INDETERMINATE,
			);
		}

		return $saved;
	}//end openNewCase()

	/**
	 * The items an earlier split already moved off this case.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function existingMovedItems(array $case): array {
		$existing = [];
		foreach ((array)($case['splitMovedItems'] ?? []) as $row) {
			if (is_array($row) === true) {
				$existing[] = $row;
			}
		}

		return $existing;
	}//end existingMovedItems()

	/**
	 * Write changes onto the case being split.
	 *
	 * @param string               $caseId  The case uuid.
	 * @param array<string, mixed> $case    The case as it was read.
	 * @param array<string, mixed> $changes The fields to write.
	 *
	 * @return void
	 */
	private function patchCase(string $caseId, array $case, array $changes): void {
		$payload = array_merge($case, $changes);
		unset($payload['@self'], $payload['id'], $payload['uuid']);

		try {
			[$objectService, $register] = $this->context();
			$objectService->saveObject(
				object: $payload,
				register: $register,
				schema: $this->schema(key: 'case_schema'),
				uuid: $caseId,
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq split: the reference to what moved could not be written',
				['case' => $caseId, 'exception' => $e->getMessage()],
			);
		}
	}//end patchCase()

	/**
	 * The stored case, or a refusal.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return array<string, mixed> The case.
	 *
	 * @throws RefusedException When it cannot be read.
	 */
	private function requireCase(string $caseId): array {
		try {
			[$objectService, $register] = $this->context();
			$case = $this->findObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $this->schema(key: 'case_schema'),
				id: $caseId,
			);
		} catch (Throwable $e) {
			throw new RefusedException(
				rule: self::CASE_UNREADABLE,
				sentence: 'We could not read that case, so it was not split.',
				status: RefusedException::STATUS_INDETERMINATE,
				previous: $e,
			);
		}

		if ($case === null) {
			throw new RefusedException(
				rule: self::CASE_UNREADABLE,
				sentence: 'We could not read that case, so it was not split.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		return $case;
	}//end requireCase()

	/**
	 * The case type behind a case, or an empty array.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<string, mixed> The case type.
	 */
	private function caseTypeOf(array $case): array {
		$caseTypeId = trim((string)($case['caseType'] ?? ''));
		if ($caseTypeId === '') {
			return [];
		}

		try {
			[$objectService, $register] = $this->context();
			$caseType = $this->findObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $this->schema(key: 'case_type_schema'),
				id: $caseTypeId,
			);
		} catch (Throwable $e) {
			return [];
		}

		return ($caseType ?? []);
	}//end caseTypeOf()

	/**
	 * The uuid of a stored row, however the register spelled it.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string The uuid, or an empty string.
	 */
	private function uuidOf(array $row): string {
		return trim((string)($row['id'] ?? ($row['uuid'] ?? '')));
	}//end uuidOf()

	/**
	 * The object service and the register, or an exception.
	 *
	 * @return array{0: object, 1: string} The service and the register.
	 *
	 * @throws RuntimeException When OpenRegister is absent or unconfigured.
	 */
	private function context(): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$register = $this->settingsService->getConfigValue('register');
		if ($register === '') {
			throw new RuntimeException('Dossier register not configured');
		}

		return [$objectService, $register];
	}//end context()

	/**
	 * A configured schema, or an exception naming the key.
	 *
	 * @param string $key The configuration key.
	 *
	 * @return string The schema id or slug.
	 *
	 * @throws RuntimeException When the key is unset.
	 */
	private function schema(string $key): string {
		$schema = $this->settingsService->getConfigValue($key);
		if ($schema === '') {
			throw new RuntimeException('Dossiq schema ' . $key . ' not configured');
		}

		return $schema;
	}//end schema()
}//end class
