<?php

/**
 * Dossiq Frozen Record Successor.
 *
 * Prepares the successor of a frozen record: its own next number from the
 * series the freeze entry names, and the two pointers that chain the pair.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\FrozenRecord
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
 * @spec openspec/specs/frozen-record/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\FrozenRecord;

use DateTimeImmutable;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\DocumentSeries\DocumentSeriesNumberer;

/**
 * A correction of a frozen record is a new record, never an edit.
 *
 * The successor takes the series' next number, not the predecessor's number
 * with a suffix, and the chain stays linear: a record already replaced is
 * not replaced again. Writing the two records is the caller's job; this
 * class only says what each must carry, so the caller saves the successor
 * first and the predecessor's forward pointer second, and a failed second
 * write leaves a successor that still names what it replaces.
 *
 * @spec openspec/specs/frozen-record/spec.md
 */
class FrozenRecordSuccessor {

	/**
	 * Constructor.
	 *
	 * @param DocumentSeriesNumberer $numberer Issues the successor's number.
	 * @param FrozenRecordGuard $guard Says whether the predecessor is frozen.
	 */
	public function __construct(
		private readonly DocumentSeriesNumberer $numberer,
		private readonly FrozenRecordGuard $guard,
	) {
	}//end __construct()

	/**
	 * The fields a successor carries: its number and the backward pointer.
	 *
	 * @param FreezeDefinition $definition The freeze entry.
	 * @param array<string, mixed> $predecessor The frozen record being replaced (incl. its id).
	 * @param string $caseId The case both belong to, for the organisation's series.
	 * @param DateTimeImmutable|null $moment The moment of issuing; now when null.
	 *
	 * @return array<string, string> Field => value, to merge into the successor.
	 *
	 * @throws RefusedException 422 when the entry allows no successor; 409 when the predecessor
	 *                          is not frozen or already replaced; 503 when no number is available.
	 *
	 * @spec openspec/specs/frozen-record/spec.md
	 */
	public function successorFields(
		FreezeDefinition $definition,
		array $predecessor,
		string $caseId,
		?DateTimeImmutable $moment = null,
	): array {
		$successor = $this->replaceableSuccessor(definition: $definition, predecessor: $predecessor);
		$number = $this->numberer->issue(caseId: $caseId, series: $successor['series'], defaultPrefix: $successor['defaultPrefix'], moment: $moment);

		return [
			$successor['numberField'] => $number,
			$successor['supersedesField'] => trim((string)($predecessor['id'] ?? ($predecessor['@self']['id'] ?? ''))),
		];
	}//end successorFields()

	/**
	 * The successor declaration, once the predecessor may be replaced; refused otherwise.
	 *
	 * The checks run in this order: an entry without successor, a record not yet
	 * frozen, a record already replaced.
	 *
	 * @param FreezeDefinition $definition The freeze entry.
	 * @param array<string, mixed> $predecessor The record being replaced.
	 *
	 * @return array<string, string> The successor declaration.
	 *
	 * @throws RefusedException 422 or 409, as successorFields documents.
	 */
	private function replaceableSuccessor(FreezeDefinition $definition, array $predecessor): array {
		$successor = self::successorOf(definition: $definition);
		$this->refuseUnlessFrozen(definition: $definition, predecessor: $predecessor);
		self::refuseIfReplaced(successor: $successor, predecessor: $predecessor);

		return $successor;
	}//end replaceableSuccessor()

	/**
	 * The entry's successor declaration; refused when the entry allows none.
	 *
	 * @param FreezeDefinition $definition The freeze entry.
	 *
	 * @return array<string, string> The successor declaration.
	 *
	 * @throws RefusedException 422 when the entry allows no successor.
	 */
	private static function successorOf(FreezeDefinition $definition): array {
		if ($definition->successor === null) {
			throw new RefusedException(
				rule: 'record-has-no-successor',
				sentence: 'This kind of record cannot be replaced by a successor.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		return $definition->successor;
	}//end successorOf()

	/**
	 * Refuse a successor for a record that is not frozen yet.
	 *
	 * @param FreezeDefinition $definition The freeze entry.
	 * @param array<string, mixed> $predecessor The record being replaced.
	 *
	 * @return void
	 *
	 * @throws RefusedException 409 when the predecessor is not frozen.
	 */
	private function refuseUnlessFrozen(FreezeDefinition $definition, array $predecessor): void {
		if ($this->guard->isFrozen(definition: $definition, stored: $predecessor) === false) {
			throw new RefusedException(
				rule: 'record-not-frozen',
				sentence: 'This record is not frozen yet, so it can still be edited. A successor is only for a frozen record.',
				status: RefusedException::STATUS_REFUSED,
			);
		}
	}//end refuseUnlessFrozen()

	/**
	 * Refuse a second successor for a record that was already replaced.
	 *
	 * @param array<string, string> $successor The successor declaration.
	 * @param array<string, mixed> $predecessor The record being replaced.
	 *
	 * @return void
	 *
	 * @throws RefusedException 409 when the predecessor is already replaced.
	 */
	private static function refuseIfReplaced(array $successor, array $predecessor): void {
		if (trim((string)($predecessor[$successor['supersededByField']] ?? '')) !== '') {
			throw new RefusedException(
				rule: 'record-already-superseded',
				sentence: 'This record has already been replaced. Correct its successor instead.',
				status: RefusedException::STATUS_REFUSED,
			);
		}
	}//end refuseIfReplaced()

	/**
	 * The field the predecessor carries once its successor is saved: the forward pointer.
	 *
	 * @param FreezeDefinition $definition The freeze entry.
	 * @param string $successorId The saved successor's id.
	 *
	 * @return array<string, string> Field => value, to write on the predecessor.
	 *
	 * @throws RefusedException 422 when the entry allows no successor.
	 *
	 * @spec openspec/specs/frozen-record/spec.md
	 */
	public function predecessorFields(FreezeDefinition $definition, string $successorId): array {
		return [self::successorOf(definition: $definition)['supersededByField'] => $successorId];
	}//end predecessorFields()
}//end class
