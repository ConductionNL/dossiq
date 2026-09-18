<?php

/**
 * Two complaints arrived on one form. This divides the case in two.
 *
 * 🔴 A SPLIT MOVES; A COPY DUPLICATES. `CaseCopyService` is right for what it
 * does, a follow-up case that starts from an earlier one, and it carries the
 * file across WHOLE. A split that did the same would produce two cases both
 * claiming the same document, and the handler would clean up by hand or not at
 * all. Here the chosen rows leave the first case, and the typed relation
 * written on both is what keeps the original file readable as a whole.
 *
 * 🔴 THE HANDLER CHOOSES, THE CASE TYPE BOUNDS THE CHOICE. Which document
 * belongs to which half is a judgment about the content and only the handler
 * has it. Whether documents may be divided AT ALL is a rule, and it does not
 * change per case, so it is declared on the case type (`splitDivides`) and
 * refused here by name rather than branched on in a dialog.
 *
 * 🔴 IT DIVIDES DOCUMENTS AND PARTIES, AND NOT TASKS. The change's own tasks
 * name three. dossiq has had no task table since `remove-casetask`: a task is
 * the workflow engine's record, and moving one is the engine's act, not a
 * repointed row here. That half is named in the PR body rather than faked with
 * a second table.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\Exception\RefusedException;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Divides a case in two, moving what the handler chose.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
 */
class CaseSplitService {

	/**
	 * The kinds of material a split can divide.
	 *
	 * `tasks` is deliberately absent: see the class docblock.
	 *
	 * @var array<int, string>
	 */
	public const DIVISIBLE = ['documents', 'parties'];

	/**
	 * The relation the two halves carry, in both directions.
	 *
	 * The same nature `CaseCopyService` already writes for a follow-up, rather
	 * than a second vocabulary for the same idea. OpenRegister's
	 * `relation-types-with-inverses` will own the inverse; until it lands both
	 * sides are written here, as `CaseRelationService` does today.
	 *
	 * @var string
	 */
	public const RELATION = 'vervolg';

	/**
	 * The schema slug holding a document's link to its case.
	 *
	 * @var string
	 */
	private const DOCUMENT_SCHEMA = 'caseDocument';

	/**
	 * The schema slug holding a party's role on a case.
	 *
	 * @var string
	 */
	private const ROLE_SCHEMA = 'role';

	/**
	 * Constructor.
	 *
	 * @param SettingsService     $settings  Bridge to OpenRegister and the schemas.
	 * @param CaseTypeResolver    $caseTypes The effective case type, parents included.
	 * @param CaseRelationService $relations Writes the typed relation on both cases.
	 * @param IL10N               $l10n      The localisation service.
	 * @param LoggerInterface     $logger    Where a partial split is noted.
	 */
	public function __construct(
		private readonly SettingsService $settings,
		private readonly CaseTypeResolver $caseTypes,
		private readonly CaseRelationService $relations,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * What a case of this type may have divided out of it.
	 *
	 * An empty or absent declaration means everything this app can divide,
	 * which is how every case type behaves today: shipping this change must
	 * not narrow a case type nobody has administered yet.
	 *
	 * @param string $caseTypeId The case type.
	 *
	 * @return array<int, string> The kinds allowed.
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
	 */
	public function divisibleFor(string $caseTypeId): array {
		$declared = [];
		try {
			$caseType = $this->caseTypes->effectiveCaseType(caseTypeId: $caseTypeId);
			$declared = ($caseType['splitDivides'] ?? []);
		} catch (Throwable) {
			// An unreadable case type allows what it always allowed. Refusing
			// every split because a read failed would take away a gesture over
			// an outage.
			return self::DIVISIBLE;
		}

		if (is_array($declared) === false || $declared === []) {
			return self::DIVISIBLE;
		}

		return array_values(array_intersect(self::DIVISIBLE, array_map('strval', $declared)));
	}//end divisibleFor()

	/**
	 * Refuse a division the case type does not allow, naming the rule.
	 *
	 * @param string             $caseTypeId The case type.
	 * @param array<int, string> $kinds      The kinds the handler asked to move.
	 *
	 * @return void
	 *
	 * @throws RefusedException When a kind is not allowed.
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
	 */
	public function assertAllowed(string $caseTypeId, array $kinds): void {
		$allowed = $this->divisibleFor(caseTypeId: $caseTypeId);

		foreach ($kinds as $kind) {
			if (in_array($kind, $allowed, true) === true) {
				continue;
			}

			// 🔴 THE RULE IS NAMED, not just the refusal. A handler told "not
			// allowed" goes looking for a permission; one told which
			// declaration refused them goes to the case type.
			throw new RefusedException(
				rule: 'split_not_divisible',
				sentence: $this->l10n->t(
					'This case type does not allow a split to divide %s.',
					[$kind]
				),
				status: 409
			);
		}
	}//end assertAllowed()

	/**
	 * Move the chosen rows onto the second case.
	 *
	 * 🔴 IT REPORTS WHAT IT MOVED, AND WHAT IT COULD NOT. A split that moved
	 * four of six documents and answered success leaves a handler believing a
	 * division that did not happen. The caller shows the count, and a row that
	 * refused is logged with its id.
	 *
	 * @param string             $sourceCaseId The case being divided.
	 * @param string             $targetCaseId The case receiving the material.
	 * @param array<int, string> $documentIds  The caseDocument rows to move.
	 * @param array<int, string> $partyIds     The role rows to move.
	 *
	 * @return array{documents: int, parties: int, refused: array<int, string>} What moved.
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
	 */
	public function moveChosen(
		string $sourceCaseId,
		string $targetCaseId,
		array $documentIds,
		array $partyIds,
	): array {
		$refused = [];

		$documents = $this->repoint(
			schema: self::DOCUMENT_SCHEMA,
			ids: $documentIds,
			sourceCaseId: $sourceCaseId,
			targetCaseId: $targetCaseId,
			refused: $refused,
		);
		$parties = $this->repoint(
			schema: self::ROLE_SCHEMA,
			ids: $partyIds,
			sourceCaseId: $sourceCaseId,
			targetCaseId: $targetCaseId,
			refused: $refused,
		);

		return ['documents' => $documents, 'parties' => $parties, 'refused' => $refused];
	}//end moveChosen()

	/**
	 * Relate the two halves to each other.
	 *
	 * Both directions are written here. OpenRegister's
	 * `relation-types-with-inverses` will own the inverse and this becomes one
	 * call; until then a relation written on one side only is a case that can
	 * be reached from its sibling and not the other way round.
	 *
	 * @param string $sourceCaseId The case being divided.
	 * @param string $targetCaseId The case receiving the material.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
	 */
	public function relate(string $sourceCaseId, string $targetCaseId): void {
		$this->relations->addRelation($sourceCaseId, $targetCaseId, self::RELATION);
		$this->relations->addRelation($targetCaseId, $sourceCaseId, self::RELATION);
	}//end relate()

	/**
	 * Repoint one schema's rows at another case.
	 *
	 * @param string             $schema       The schema slug.
	 * @param array<int, string> $ids          The rows to move.
	 * @param string             $sourceCaseId The case they must currently be on.
	 * @param string             $targetCaseId The case to move them to.
	 * @param array<int, string> $refused      Collects the rows that did not move.
	 *
	 * @return int How many moved.
	 */
	private function repoint(
		string $schema,
		array $ids,
		string $sourceCaseId,
		string $targetCaseId,
		array &$refused,
	): int {
		if ($ids === []) {
			return 0;
		}

		$objectService = $this->settings->getObjectService();
		$register = $this->settings->getConfigValue('register');
		if ($objectService === null || $register === '') {
			$refused = array_merge($refused, $ids);

			return 0;
		}

		$moved = 0;
		foreach ($ids as $id) {
			$id = trim((string)$id);
			if ($id === '') {
				continue;
			}

			try {
				$row = $this->arrayOf(row: $objectService->find($id, $register, $schema));
			} catch (Throwable) {
				$refused[] = $id;
				continue;
			}

			// 🔴 IT MUST BE ON THE CASE BEING SPLIT. Without this a handler
			// could move another case's document onto theirs by passing its
			// id, and the audit trail would record dossiq doing it.
			if (trim((string)($row['case'] ?? '')) !== $sourceCaseId) {
				$refused[] = $id;
				continue;
			}

			$row['case'] = $targetCaseId;

			try {
				$objectService->saveObject(
					object: $row,
					register: $register,
					schema: $schema,
					uuid: $id,
				);
				$moved++;
			} catch (Throwable $e) {
				$refused[] = $id;
				$this->logger->warning(
					'Dossiq split: {schema} {id} did not move to case {case}',
					['schema' => $schema, 'id' => $id, 'case' => $targetCaseId, 'error' => $e->getMessage()]
				);
			}
		}

		return $moved;
	}//end repoint()

	/**
	 * One row as an array, whatever the store handed back.
	 *
	 * @param mixed $row The row.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function arrayOf(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$serialised = $row->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		return [];
	}//end arrayOf()
}//end class
