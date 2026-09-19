<?php

/**
 * Carrying out the split that {@see CaseSplitPlan} plans.
 *
 * 🔴 THE PLAN AND THE POLICY DECIDE; THIS CLASS ONLY WRITES. `CaseSplitPolicy`
 * answers what the case type allows and `CaseSplitPlan` answers which rows move
 * and what reference each leaves. Both are pure, and both shipped with no
 * caller: `git grep` found no code outside their own tests that asked either of
 * them anything, so a handler could not split a case and nothing said why. This
 * is the executor they were written for, and it re-decides nothing. A second
 * opinion about what may be divided would eventually disagree with the first.
 *
 * 🔴 THE ROWS ARE READ OFF THE CASE, NOT TAKEN FROM THE REQUEST. The plan
 * refuses an item whose own `case` names another case, and it can only do that
 * if somebody hands it the item's stored `case` value. Passing the client's ids
 * through would hand the plan exactly what the client claimed and turn its
 * guard into a formality.
 *
 * ⚠️ A TASK IS DECLARABLE AND NOT MOVABLE. `CaseSplitPolicy::PARTS` includes
 * `tasks` and a case type can forbid it, but a dossiq task lives in the engine,
 * whose task carries the case as its `objectUuid`, and `EngineTaskGateway`
 * offers create, claim, reassign and complete and no verb that moves a task to
 * another object. Asking to move one is refused with a sentence naming the
 * missing verb, rather than accepted and silently ignored.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Cases
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

namespace OCA\Dossiq\Service\Cases;

use DateTimeImmutable;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Open the second case, move what the plan names, record what left.
 *
 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
 */
class CaseSplitExecutor {

	use SearchesObjects;

	/**
	 * Which schema each divisible part lives on, and the field naming the case.
	 *
	 * `tasks` is absent on purpose: see the note on this class. The map is
	 * PERSISTENCE and belongs here rather than on the policy, which answers a
	 * question about a case type and not about storage.
	 *
	 * @var array<string, array{schema: string, key: string}>
	 */
	public const PART_SOURCES = [
		'documents' => ['schema' => 'case_document_schema', 'key' => 'case'],
		'parties' => ['schema' => 'role_schema', 'key' => 'case'],
	];

	/**
	 * The refusal when the case cannot be read.
	 *
	 * @var string
	 */
	public const CASE_UNREADABLE = 'split-case-unreadable';

	/**
	 * The refusal the case type's own declaration produced.
	 *
	 * @var string
	 */
	public const FORBIDDEN = 'split-forbidden-by-case-type';

	/**
	 * The refusal when a part cannot be moved at all yet.
	 *
	 * @var string
	 */
	public const UNMOVABLE = 'split-part-not-movable';

	/**
	 * The refusal when nothing was chosen.
	 *
	 * @var string
	 */
	public const NOTHING_CHOSEN = 'split-nothing-chosen';

	/**
	 * The refusal when the second case could not be opened.
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
	 * @param SettingsService  $settingsService Bridge to OpenRegister and the configured schemas.
	 * @param CaseSplitPolicy  $policy          What the case type allows to be divided.
	 * @param CaseSplitPlan    $plan            Which rows move, and what each leaves behind.
	 * @param LoggerInterface  $logger          Records every split and every refusal.
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly CaseSplitPolicy $policy,
		private readonly CaseSplitPlan $plan,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Split a case, moving what the handler chose.
	 *
	 * @param string $caseId The case being split.
	 * @param string $title  The new case's title.
	 * @param array<string, array<int, string>> $chosen The ids the handler
	 *        picked, under the keys `documents`, `parties`, `tasks` and
	 *        `partiesOnBoth`. Written as a map rather than a shape because the
	 *        line the shape needs does not fit, and the plan below is what
	 *        actually refuses an unknown key.
	 * @param string $actor  Who split it.
	 *
	 * @return array{case: array<string, mixed>, moved: array<int, array<string, mixed>>, refused: array<int, array<string, mixed>>, note: string}
	 *         The new case, what moved, what the plan refused as not this case's, and the note recorded on the original.
	 *
	 * @throws RefusedException When the case cannot be read, the case type refuses, or the write fails.
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-a-split-divides-a-case-rather-than-duplicating-it-req-cm-50
	 */
	public function split(string $caseId, string $title, array $chosen, string $actor): array {
		$caseId = trim($caseId);
		$case = $this->requireCase(caseId: $caseId);
		$caseType = $this->caseTypeOf(case: $case);

		$this->refuseSelection(chosen: $chosen, caseType: $caseType);

		$rows = $this->rowsFor(caseId: $caseId, chosen: $chosen);
		$onBoth = $this->rowsForPart(
			caseId: $caseId,
			part: 'parties',
			wanted: $this->idsFor(chosen: $chosen, part: 'partiesOnBoth'),
		);

		if ($rows === [] && $onBoth === []) {
			throw new RefusedException(
				rule: self::NOTHING_CHOSEN,
				sentence: 'Choose what goes to the new case, so the split divides something.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		$newCase = $this->openSecondCase(source: $case, title: $title, actor: $actor);
		$newId = $this->uuidOf(row: $newCase);

		$planned = $this->plan->forSelection(sourceId: $caseId, newId: $newId, chosen: $rows);

		$moved = [];
		foreach ($planned['moves'] as $move) {
			if ($this->apply(move: $move, rows: $rows) === true) {
				$moved[] = $move;
			}
		}

		foreach ($onBoth as $item) {
			$this->duplicateOnto(item: $item, toId: $newId);
		}

		$note = $this->plan->noteFor(
			references: $planned['references'],
			newNumber: trim((string)($newCase['identifier'] ?? $newId)),
		);

		$this->recordOnOriginal(
			caseId: $caseId,
			case: $case,
			newId: $newId,
			references: $planned['references'],
			note: $note,
		);

		$this->logger->info(
			'Dossiq split: a case was divided',
			[
				'case' => $caseId,
				'into' => $newId,
				'moved' => count($moved),
				'refused' => count($planned['refused']),
				'onBoth' => count($onBoth),
			],
		);

		return ['case' => $newCase, 'moved' => $moved, 'refused' => $planned['refused'], 'note' => $note];
	}//end split()

	/**
	 * Refuse a selection the case type forbids, or that cannot be carried out.
	 *
	 * @param array<string, mixed>      $chosen   What the handler picked.
	 * @param array<string, mixed>|null $caseType The case type.
	 *
	 * @return void
	 *
	 * @throws RefusedException Carrying the policy's own sentence.
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-a-case-type-declares-what-a-split-may-divide-req-cm-52
	 */
	private function refuseSelection(array $chosen, ?array $caseType): void {
		$selected = [];
		foreach (CaseSplitPolicy::PARTS as $part) {
			if ($this->idsFor(chosen: $chosen, part: $part) !== []) {
				$selected[] = $part;
			}
		}

		// THE POLICY'S OWN SENTENCE, not a second one written here. It names
		// what is still possible as well as what is not, and a refusal written
		// twice is a refusal that will eventually say two different things.
		$refusal = $this->policy->whyRefused(selected: $selected, caseType: $caseType);
		if ($refusal !== '') {
			throw new RefusedException(
				rule: self::FORBIDDEN,
				sentence: $refusal,
				status: RefusedException::STATUS_REFUSED,
			);
		}

		foreach ($selected as $part) {
			if (array_key_exists($part, self::PART_SOURCES) === false) {
				throw new RefusedException(
					rule: self::UNMOVABLE,
					sentence: 'A task cannot be moved to another case yet: the engine has no verb for it, '
						. 'so nothing was split rather than the task being silently left behind.',
					status: RefusedException::STATUS_REFUSED,
				);
			}
		}
	}//end refuseSelection()

	/**
	 * The stored rows behind the chosen ids, by part.
	 *
	 * The rows carry their own `case`, which is what lets the plan refuse an
	 * item that belongs to another case. Handing it the client's ids instead
	 * would give it back exactly what the client claimed.
	 *
	 * @param string               $caseId The case being split.
	 * @param array<string, mixed> $chosen What the handler picked.
	 *
	 * @return array<string, array<int, array<string, mixed>>> The rows, by part.
	 */
	private function rowsFor(string $caseId, array $chosen): array {
		$rows = [];
		foreach (self::PART_SOURCES as $part => $source) {
			$found = $this->rowsForPart(
				caseId: $caseId,
				part: $part,
				wanted: $this->idsFor(chosen: $chosen, part: $part),
			);
			if ($found !== []) {
				$rows[$part] = $found;
			}
		}

		return $rows;
	}//end rowsFor()

	/**
	 * The stored rows of one part whose ids the handler chose.
	 *
	 * An id the register does not answer for this case is left out here AND
	 * refused by the plan, which sees it only when the register answered a row
	 * naming another case. Both paths end in something the caller can read.
	 *
	 * @param string             $caseId The case being split.
	 * @param string             $part   The part.
	 * @param array<int, string> $wanted The chosen ids.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function rowsForPart(string $caseId, string $part, array $wanted): array {
		if ($wanted === [] || array_key_exists($part, self::PART_SOURCES) === false) {
			return [];
		}

		$source = self::PART_SOURCES[$part];

		try {
			[$objectService, $register] = $this->context();
			$rows = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $this->schema(key: $source['schema']),
				filters: [$source['key'] => $caseId, '_limit' => self::PAGE_SIZE],
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq split: the children of a case could not be read',
				['case' => $caseId, 'part' => $part, 'exception' => $e->getMessage()],
			);

			return [];
		}

		$chosen = [];
		foreach ($rows as $row) {
			$id = $this->uuidOf(row: $row);
			if ($id !== '' && in_array($id, $wanted, true) === true) {
				$row['id'] = $id;
				$chosen[] = $row;
			}
		}

		return $chosen;
	}//end rowsForPart()

	/**
	 * Apply one planned move.
	 *
	 * @param array<string, mixed>                            $move The planned move.
	 * @param array<string, array<int, array<string, mixed>>> $rows The rows, by part.
	 *
	 * @return bool True when the row moved.
	 */
	private function apply(array $move, array $rows): bool {
		$part = (string)($move['part'] ?? '');
		$id = (string)($move['id'] ?? '');
		$source = (self::PART_SOURCES[$part] ?? null);
		if ($source === null || $id === '') {
			return false;
		}

		$row = null;
		foreach (($rows[$part] ?? []) as $candidate) {
			if ($this->uuidOf(row: $candidate) === $id) {
				$row = $candidate;
				break;
			}
		}

		if ($row === null) {
			return false;
		}

		$payload = array_merge($row, (array)($move['changes'] ?? []));
		unset($payload['@self'], $payload['id'], $payload['uuid']);

		try {
			[$objectService, $register] = $this->context();
			$objectService->saveObject(
				object: $payload,
				register: $register,
				schema: $this->schema(key: $source['schema']),
				uuid: $id,
			);

			return true;
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq split: an item did not move',
				['part' => $part, 'id' => $id, 'exception' => $e->getMessage()],
			);

			return false;
		}
	}//end apply()

	/**
	 * Put a copy of one party on the new case, leaving the original where it is.
	 *
	 * The only thing this class duplicates, and only because a party is a ROLE
	 * rather than a copy of a person: one relevant to both halves belongs on
	 * both, with its role intact.
	 *
	 * @param array<string, mixed> $item The stored role.
	 * @param string               $toId The new case.
	 *
	 * @return void
	 */
	private function duplicateOnto(array $item, string $toId): void {
		$payload = array_merge($item, ['case' => $toId]);
		unset($payload['@self'], $payload['id'], $payload['uuid']);

		try {
			[$objectService, $register] = $this->context();
			$objectService->saveObject(
				object: $payload,
				register: $register,
				schema: $this->schema(key: self::PART_SOURCES['parties']['schema']),
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq split: a party relevant to both halves reached only one of them',
				['id' => ($item['id'] ?? ''), 'exception' => $e->getMessage()],
			);
		}
	}//end duplicateOnto()

	/**
	 * Open the second case, naming the first.
	 *
	 * The relation is written as `relatedCases`, a JSON-ENCODED string of typed
	 * relations, because that is what the schema declares and what
	 * `CaseRelationCodec` reads. An array there stores a shape nothing reads
	 * and the Related tab is empty on every split.
	 *
	 * @param array<string, mixed> $source The case being split.
	 * @param string               $title  The new case's title.
	 * @param string               $actor  Who split it.
	 *
	 * @return array<string, mixed> The new case.
	 *
	 * @throws RefusedException When the write is refused.
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-a-split-divides-a-case-rather-than-duplicating-it-req-cm-50
	 */
	private function openSecondCase(array $source, string $title, string $actor): array {
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
						'aardRelatie' => CaseSplitPlan::RELATION,
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
	}//end openSecondCase()

	/**
	 * Write onto the original what left it, and where it went.
	 *
	 * @param string                           $caseId     The case that was split.
	 * @param array<string, mixed>             $case       The case as it was read.
	 * @param string                           $newId      The case the material went to.
	 * @param array<int, array<string, mixed>> $references The plan's reference entries.
	 * @param string                           $note       The plan's one-line note.
	 *
	 * @return void
	 */
	private function recordOnOriginal(string $caseId, array $case, string $newId, array $references, string $note): void {
		$now = (new DateTimeImmutable())->format('c');

		$existing = [];
		foreach ((array)($case['splitMovedItems'] ?? []) as $row) {
			if (is_array($row) === true) {
				$existing[] = $row;
			}
		}

		$recorded = [];
		foreach ($references as $reference) {
			$recorded[] = array_merge((array)$reference, ['movedAt' => $now]);
		}

		$payload = array_merge(
			$case,
			[
				'splitInto' => $newId,
				'splitMovedItems' => array_merge($existing, $recorded),
				'splitNote' => $note,
			]
		);
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
	}//end recordOnOriginal()

	/**
	 * The chosen ids of one part.
	 *
	 * @param array<string, mixed> $chosen What the handler picked.
	 * @param string               $part   The part.
	 *
	 * @return array<int, string> The ids.
	 */
	private function idsFor(array $chosen, string $part): array {
		$ids = [];
		foreach ((array)($chosen[$part] ?? []) as $id) {
			$id = trim((string)$id);
			if ($id !== '') {
				$ids[] = $id;
			}
		}

		return $ids;
	}//end idsFor()

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
	 * Which parts a split may divide on this case.
	 *
	 * 🔴 THE PICKER HAS TO ASK THIS BEFORE IT DRAWS. Without it the dialog
	 * lists every document and party the case holds, the handler ticks one,
	 * confirms, and learns only from the refusal that this case type does not
	 * allow documents to be divided. The rule was always there and always
	 * enforced; what was missing was saying it before the attempt rather than
	 * after it.
	 *
	 * It answers the policy and nothing else. The policy already reads an
	 * absent declaration as "everything may be divided", and an unreadable
	 * case type reaches it as null rather than as a type declaring nothing, so
	 * a case type that cannot be read offers all three rather than refusing
	 * everything.
	 *
	 * @param string $caseId The case being looked at.
	 *
	 * @return array<int, string> The parts this case type allows, in a stable order.
	 *
	 * @throws RefusedException When the case cannot be read.
	 *
	 * @spec openspec/changes/split-picker-asks-the-policy/specs/case-management/spec.md#requirement-the-picker-says-what-may-be-divided-before-the-handler-chooses-req-cm-49
	 */
	public function divisibleParts(string $caseId): array {
		$case = $this->requireCase(caseId: trim($caseId));

		return $this->policy->allowedFor(caseType: $this->caseTypeOf(case: $case));
	}//end divisibleParts()

	/**
	 * The case type behind a case, or null.
	 *
	 * NULL AND AN EMPTY ARRAY ARE DIFFERENT ANSWERS to `CaseSplitPolicy`: it
	 * reads an absent declaration as "everything may be divided", and an
	 * unreadable case type has to reach it as absent rather than as a type
	 * declaring nothing.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<string, mixed>|null The case type.
	 */
	private function caseTypeOf(array $case): ?array {
		$caseTypeId = trim((string)($case['caseType'] ?? ''));
		if ($caseTypeId === '') {
			return null;
		}

		try {
			[$objectService, $register] = $this->context();

			return $this->findObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $this->schema(key: 'case_type_schema'),
				id: $caseTypeId,
			);
		} catch (Throwable $e) {
			return null;
		}
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
