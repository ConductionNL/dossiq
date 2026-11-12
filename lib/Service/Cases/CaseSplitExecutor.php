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
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Cases;

use DateTimeImmutable;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Open the second case, move what the plan names, record what left.
 *
 * @spec openspec/specs/case-management/spec.md
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
	 * @param CaseSplitPolicy  $policy          What the case type allows to be divided.
	 * @param CaseSplitPlan    $plan            Which rows move, and what each leaves behind.
	 * @param LoggerInterface  $logger          Records every split and every refusal.
	 * @param CaseSplitStore   $store           Where a split reads and writes.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function __construct(
		private readonly CaseSplitPolicy $policy,
		private readonly CaseSplitPlan $plan,
		private readonly LoggerInterface $logger,
		private readonly CaseSplitStore $store,
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
	 * @spec openspec/specs/case-management/spec.md#requirement-a-split-divides-a-case-rather-than-duplicating-it-req-cm-50
	 */
	public function split(string $caseId, string $title, array $chosen, string $actor): array {
		$caseId = trim($caseId);
		$case = $this->store->requireCase(caseId: $caseId);
		$this->refuseSelection(chosen: $chosen, caseType: $this->caseTypeOf(case: $case));
		$selection = $this->selectionOf(caseId: $caseId, chosen: $chosen);

		$newCase = $this->openSecondCase(source: $case, title: $title, actor: $actor);
		$newId = $this->store->uuidOf(row: $newCase);
		$planned = $this->plan->forSelection(sourceId: $caseId, newId: $newId, chosen: $selection['rows']);
		$moved = array_values(
			array_filter($planned['moves'], fn (array $move): bool => $this->apply(move: $move, rows: $selection['rows']))
		);
		foreach ($selection['onBoth'] as $item) {
			$this->duplicateOnto(item: $item, toId: $newId);
		}

		$split = [
			'caseId' => $caseId,
			'newId' => $newId,
			'newNumber' => trim((string)($newCase['identifier'] ?? $newId)),
			'references' => $planned['references'],
		];
		$note = $this->recordOnOriginal(case: $case, split: $split);
		$this->logger->info(
			'Dossiq split: a case was divided',
			['case' => $caseId, 'into' => $newId, 'moved' => count($moved), 'refused' => count($planned['refused']), 'onBoth' => count($selection['onBoth'])],
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
	 * @spec openspec/specs/case-management/spec.md#requirement-a-case-type-declares-what-a-split-may-divide-req-cm-52
	 */
	private function refuseSelection(array $chosen, ?array $caseType): void {
		$selected = array_values(
			array_filter(
				CaseSplitPolicy::PARTS,
				fn (string $part): bool => $this->idsFor(chosen: $chosen, part: $part) !== []
			)
		);

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

		if (array_diff($selected, array_keys(self::PART_SOURCES)) !== []) {
			throw new RefusedException(
				rule: self::UNMOVABLE,
				sentence: 'A task cannot be moved to another case yet: the engine has no verb for it, '
					. 'so nothing was split rather than the task being silently left behind.',
				status: RefusedException::STATUS_REFUSED,
			);
		}
	}//end refuseSelection()

	/**
	 * The stored rows to move and the parties to put on both halves.
	 *
	 * @param string               $caseId The case being split.
	 * @param array<string, mixed> $chosen What the handler picked.
	 *
	 * @return array{rows: array<string, array<int, array<string, mixed>>>, onBoth: array<int, array<string, mixed>>}
	 *         The rows by part, and the parties for both halves.
	 *
	 * @throws RefusedException When neither holds anything.
	 */
	private function selectionOf(string $caseId, array $chosen): array {
		$rows = $this->rowsFor(caseId: $caseId, chosen: $chosen);
		$onBoth = ($this->rowsFor(caseId: $caseId, chosen: ['parties' => ($chosen['partiesOnBoth'] ?? [])])['parties'] ?? []);

		if ($rows === [] && $onBoth === []) {
			throw new RefusedException(
				rule: self::NOTHING_CHOSEN,
				sentence: 'Choose what goes to the new case, so the split divides something.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		return ['rows' => $rows, 'onBoth' => $onBoth];
	}//end selectionOf()

	/**
	 * The stored rows behind the chosen ids, by part.
	 *
	 * The rows carry their own `case`, which is what lets the plan refuse an
	 * item that belongs to another case. Handing it the client's ids instead
	 * would give it back exactly what the client claimed. An id the register
	 * does not answer for this case is left out here AND refused by the plan,
	 * which sees it only when the register answered a row naming another case.
	 *
	 * @param string               $caseId The case being split.
	 * @param array<string, mixed> $chosen What the handler picked.
	 *
	 * @return array<string, array<int, array<string, mixed>>> The rows, by part.
	 */
	private function rowsFor(string $caseId, array $chosen): array {
		$rows = [];
		foreach (array_keys(self::PART_SOURCES) as $part) {
			$wanted = $this->idsFor(chosen: $chosen, part: $part);
			if ($wanted !== []) {
				$rows[$part] = $this->rowsWithIds(rows: $this->rowsForPart(caseId: $caseId, part: $part), ids: $wanted);
			}
		}

		return array_filter($rows, fn (array $found): bool => $found !== []);
	}//end rowsFor()

	/**
	 * Every stored row of one part on the case.
	 *
	 * @param string $caseId The case being split.
	 * @param string $part   A part of PART_SOURCES.
	 *
	 * @return array<int, array<string, mixed>> The rows, or none when the register cannot be read.
	 */
	private function rowsForPart(string $caseId, string $part): array {
		$source = self::PART_SOURCES[$part];

		try {
			[$objectService, $register] = $this->store->context();

			return $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $this->store->schema(key: $source['schema']),
				filters: [$source['key'] => $caseId, '_limit' => self::PAGE_SIZE],
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq split: the children of a case could not be read',
				['case' => $caseId, 'part' => $part, 'exception' => $e->getMessage()],
			);

			return [];
		}
	}//end rowsForPart()

	/**
	 * The rows whose id is one of these, each carrying that id.
	 *
	 * @param array<int, array<string, mixed>> $rows The stored rows.
	 * @param array<int, string>               $ids  The chosen ids.
	 *
	 * @return array<int, array<string, mixed>> The chosen rows.
	 */
	private function rowsWithIds(array $rows, array $ids): array {
		$chosen = [];
		foreach ($rows as $row) {
			$row['id'] = $this->store->uuidOf(row: $row);
			if ($row['id'] !== '' && in_array($row['id'], $ids, true) === true) {
				$chosen[] = $row;
			}
		}

		return $chosen;
	}//end rowsWithIds()

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
		foreach (($rows[$part] ?? []) as $row) {
			if ($id !== '' && $this->store->uuidOf(row: $row) === $id) {
				return $this->moveRow(row: $row, move: ['part' => $part, 'id' => $id, 'changes' => ($move['changes'] ?? [])]);
			}
		}

		return false;
	}//end apply()

	/**
	 * Write one row with the move's changes.
	 *
	 * @param array<string, mixed>                                $row  The stored row.
	 * @param array{part: string, id: string, changes: mixed} $move The planned move.
	 *
	 * @return bool True when the row moved.
	 */
	private function moveRow(array $row, array $move): bool {
		$payload = array_merge($row, (array)$move['changes']);
		unset($payload['@self'], $payload['id'], $payload['uuid']);

		try {
			[$objectService, $register] = $this->store->context();
			$objectService->saveObject(
				object: $payload,
				register: $register,
				schema: $this->store->schema(key: self::PART_SOURCES[$move['part']]['schema']),
				uuid: $move['id'],
			);

			return true;
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq split: an item did not move',
				['part' => $move['part'], 'id' => $move['id'], 'exception' => $e->getMessage()],
			);

			return false;
		}
	}//end moveRow()

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
			[$objectService, $register] = $this->store->context();
			$objectService->saveObject(
				object: $payload,
				register: $register,
				schema: $this->store->schema(key: self::PART_SOURCES['parties']['schema']),
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
	 * @param array<string, mixed> $source The case being split.
	 * @param string               $title  The new case's title.
	 * @param string               $actor  Who split it.
	 *
	 * @return array<string, mixed> The new case.
	 *
	 * @throws RefusedException When the write is refused.
	 *
	 * @spec openspec/specs/case-management/spec.md#requirement-a-split-divides-a-case-rather-than-duplicating-it-req-cm-50
	 */
	private function openSecondCase(array $source, string $title, string $actor): array {
		$this->store->refuseUnreachableStatus(source: $source);
		$payload = $this->secondCaseOf(source: $source, title: $title);
		$payload['relatedCases'] = $this->relationBack(sourceId: $payload['splitFrom'], actor: $actor);

		return $this->takeOverStatus(
			newCase: $this->saveSecondCase(payload: $payload),
			move: ['source' => $source, 'actor' => $actor],
		);
	}//end openSecondCase()

	/**
	 * Move the second case from its initial status into the status of the first.
	 *
	 * OpenRegister opens a case in its case type's initial status, so the
	 * status the original held is entered as a recorded move afterwards.
	 *
	 * @param array<string, mixed> $newCase The second case as stored.
	 * @param array<string, mixed> $move    The case being split (`source`) and who split it (`actor`).
	 *
	 * @return array<string, mixed> The second case, in the status of the first.
	 *
	 * @throws RefusedException When the move cannot be saved or recorded.
	 */
	private function takeOverStatus(array $newCase, array $move): array {
		$source = $move['source'];
		$status = trim((string)($source['status'] ?? ''));
		if ($status === '' || $status === trim((string)($newCase['status'] ?? ''))) {
			return $newCase;
		}

		$origin = trim((string)($source['identifier'] ?? $this->store->uuidOf(row: $source)));
		try {
			return $this->store->enterStatus(
				case: $newCase,
				move: ['toStatus' => $status, 'label' => 'Split from ' . $origin, 'actor' => (string)$move['actor']],
			);
		} catch (Throwable $e) {
			throw new RefusedException(
				rule: self::UNWRITABLE,
				sentence: $this->store->undoSecondCase(newCase: $newCase),
				status: RefusedException::STATUS_INDETERMINATE,
				previous: $e,
			);
		}
	}//end takeOverStatus()


	/**
	 * The second case's fields, taken from the first.
	 *
	 * @param array<string, mixed> $source The case being split.
	 * @param string               $title  The new case's title.
	 *
	 * @return array<string, string> The fields.
	 */
	private function secondCaseOf(array $source, string $title): array {
		$title = trim($title);
		if ($title === '') {
			$title = 'Split from ' . (string)($source['title'] ?? '');
		}

		return [
			'title' => $title,
			'caseType' => (string)($source['caseType'] ?? ''),
			'assignedGroup' => (string)($source['assignedGroup'] ?? ''),
			'assignee' => (string)($source['assignee'] ?? ''),
			'impact' => (string)($source['impact'] ?? 'medium'),
			'urgency' => (string)($source['urgency'] ?? 'medium'),
			'splitFrom' => $this->store->uuidOf(row: $source),
		];
	}//end secondCaseOf()

	/**
	 * The second case's relation back to the first.
	 *
	 * Written as `relatedCases`, a JSON-ENCODED string of typed relations,
	 * because that is what the schema declares and what `CaseRelationCodec`
	 * reads. An array there stores a shape nothing reads and the Related tab
	 * is empty on every split.
	 *
	 * @param string $sourceId The case being split.
	 * @param string $actor    Who split it.
	 *
	 * @return string The encoded relations.
	 */
	private function relationBack(string $sourceId, string $actor): string {
		$relation = [
			'caseId' => $sourceId,
			'aardRelatie' => CaseSplitPlan::RELATION,
			'toelichting' => 'Split from this case by ' . $actor,
		];

		return (string)json_encode([$relation]);
	}//end relationBack()

	/**
	 * Write the second case.
	 *
	 * @param array<string, string> $payload The new case.
	 *
	 * @return array<string, mixed> The stored case.
	 *
	 * @throws RefusedException When the write throws or answers no case.
	 */
	private function saveSecondCase(array $payload): array {
		$failure = null;
		try {
			[$objectService, $register] = $this->store->context();
			$saved = $this->saveObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $this->store->schema(key: 'case_schema'),
				object: $payload,
			);
		} catch (Throwable $e) {
			[$saved, $failure] = [null, $e];
		}

		if ($saved === null) {
			throw new RefusedException(
				rule: self::UNWRITABLE,
				sentence: 'The second case could not be opened, so nothing was moved.',
				status: RefusedException::STATUS_INDETERMINATE,
				previous: $failure,
			);
		}

		return $saved;
	}//end saveSecondCase()

	/**
	 * Write onto the original what left it, and where it went.
	 *
	 * @param array<string, mixed>                $case  The case as it was read.
	 * @param array<string, string|array<mixed>> $split The case that was split (caseId), the case the material
	 *        went to (newId), its number (newNumber), and the plan's reference entries (references).
	 *
	 * @return string The plan's one-line note, as recorded.
	 */
	private function recordOnOriginal(array $case, array $split): string {
		$note = $this->plan->noteFor(references: $split['references'], newNumber: $split['newNumber']);
		$payload = array_merge(
			$case,
			[
				'splitInto' => $split['newId'],
				'splitMovedItems' => $this->movedItems(case: $case, references: $split['references']),
				'splitNote' => $note,
			]
		);
		unset($payload['@self'], $payload['id'], $payload['uuid']);

		try {
			[$objectService, $register] = $this->store->context();
			$objectService->saveObject(
				object: $payload,
				register: $register,
				schema: $this->store->schema(key: 'case_schema'),
				uuid: $split['caseId'],
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq split: the reference to what moved could not be written',
				['case' => $split['caseId'], 'exception' => $e->getMessage()],
			);
		}

		return $note;
	}//end recordOnOriginal()

	/**
	 * The original's earlier moved items followed by these, stamped now.
	 *
	 * @param array<string, mixed>             $case       The case as it was read.
	 * @param array<int, array<string, mixed>> $references The plan's reference entries.
	 *
	 * @return array<int, array<string, mixed>> The moved items.
	 */
	private function movedItems(array $case, array $references): array {
		$now = ['movedAt' => (new DateTimeImmutable())->format('c')];
		$existing = array_filter((array)($case['splitMovedItems'] ?? []), 'is_array');

		return array_merge(
			array_values($existing),
			array_map(static fn (mixed $reference): array => array_merge((array)$reference, $now), array_values($references))
		);
	}//end movedItems()

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
	 * @spec openspec/specs/case-management/spec.md#requirement-the-picker-says-what-may-be-divided-before-the-handler-chooses-req-cm-49
	 */
	public function divisibleParts(string $caseId): array {
		$case = $this->store->requireCase(caseId: trim($caseId));

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
			[$objectService, $register] = $this->store->context();

			return $this->findObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $this->store->schema(key: 'case_type_schema'),
				id: $caseTypeId,
			);
		} catch (Throwable $e) {
			return null;
		}
	}//end caseTypeOf()



}//end class
