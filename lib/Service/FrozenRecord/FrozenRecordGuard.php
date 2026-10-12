<?php

/**
 * Dossiq Frozen Record Guard.
 *
 * Decides whether a write or a delete may touch a record that a freeze entry
 * covers: a frozen field may not move, a write-once field may not be
 * rewritten, a frozen record may not thaw and may not be deleted.
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

use OCA\Dossiq\Exception\RefusedException;

/**
 * The freeze rule, with no knowledge of which record it guards.
 *
 * THE STORED STATE DECIDES. The incoming payload is read only for what it
 * wants to change, never for what the status is: a payload claiming an
 * early status over a frozen row is a thaw, and a thaw is refused.
 *
 * A SOFT DELETE IS AN UPDATE. OpenRegister's ordinary DELETE stamps the
 * deletion marker and persists it as an update that moves no content field,
 * so the update path checks for that marker as well as the delete path.
 *
 * @spec openspec/specs/frozen-record/spec.md
 */
class FrozenRecordGuard {

	/**
	 * Whether the stored record is frozen.
	 *
	 * @param FreezeDefinition $definition The freeze entry.
	 * @param array<string, mixed> $stored The record as stored.
	 *
	 * @return bool True when frozen.
	 *
	 * @spec openspec/specs/frozen-record/spec.md
	 */
	public function isFrozen(FreezeDefinition $definition, array $stored): bool {
		return $definition->freezesAt(status: (string)($stored[$definition->statusField] ?? ''));
	}//end isFrozen()

	/**
	 * Refuse an update the freeze does not allow.
	 *
	 * @param FreezeDefinition $definition The freeze entry.
	 * @param array<string, mixed> $stored The record as stored.
	 * @param array<string, mixed> $incoming The record as it would be written.
	 *
	 * @return void
	 *
	 * @throws RefusedException 409 when the write touches what the freeze holds.
	 *
	 * @spec openspec/specs/frozen-record/spec.md
	 */
	public function assertUpdatable(FreezeDefinition $definition, array $stored, array $incoming): void {
		$this->assertWriteOnceHeld(definition: $definition, stored: $stored, incoming: $incoming);

		if ($this->isFrozen(definition: $definition, stored: $stored) === false) {
			return;
		}

		if ($this->isMarkedDeleted(record: $incoming) === true && $this->isMarkedDeleted(record: $stored) === false) {
			throw $this->refusal(definition: $definition);
		}

		if (array_key_exists($definition->statusField, $incoming) === true
			&& $definition->freezesAt(status: (string)$incoming[$definition->statusField]) === false
		) {
			throw $this->refusal(definition: $definition);
		}

		if ($this->changedFrozenFields(definition: $definition, stored: $stored, incoming: $incoming) !== []) {
			throw $this->refusal(definition: $definition);
		}
	}//end assertUpdatable()

	/**
	 * Refuse the delete of a frozen record.
	 *
	 * @param FreezeDefinition $definition The freeze entry.
	 * @param array<string, mixed> $stored The record as stored.
	 *
	 * @return void
	 *
	 * @throws RefusedException 409 when the record is frozen.
	 *
	 * @spec openspec/specs/frozen-record/spec.md
	 */
	public function assertDeletable(FreezeDefinition $definition, array $stored): void {
		if ($this->isFrozen(definition: $definition, stored: $stored) === true) {
			throw $this->refusal(definition: $definition);
		}
	}//end assertDeletable()

	/**
	 * Whether this write is the one that freezes the record.
	 *
	 * @param FreezeDefinition $definition The freeze entry.
	 * @param array<string, mixed> $stored The record as stored.
	 * @param array<string, mixed> $incoming The record as it would be written.
	 *
	 * @return bool True when the stored record is not frozen and the written one is.
	 *
	 * @spec openspec/specs/frozen-record/spec.md
	 */
	public function isFreezing(FreezeDefinition $definition, array $stored, array $incoming): bool {
		return $this->isFrozen(definition: $definition, stored: $stored) === false
			&& $this->isFrozen(definition: $definition, stored: $incoming) === true;
	}//end isFreezing()

	/**
	 * The refusal for a write the freeze does not allow.
	 *
	 * @param FreezeDefinition $definition The freeze entry.
	 *
	 * @return RefusedException A 409-carrying refusal.
	 *
	 * @spec openspec/specs/frozen-record/spec.md
	 */
	public function refusal(FreezeDefinition $definition): RefusedException {
		return new RefusedException(
			rule: $definition->rule,
			sentence: $definition->message,
			status: RefusedException::STATUS_REFUSED,
		);
	}//end refusal()

	/**
	 * Refuse a write that rewrites a write-once field that already holds a value.
	 *
	 * @param FreezeDefinition $definition The freeze entry.
	 * @param array<string, mixed> $stored The record as stored.
	 * @param array<string, mixed> $incoming The record as it would be written.
	 *
	 * @return void
	 *
	 * @throws RefusedException 409 when a written value would change.
	 */
	private function assertWriteOnceHeld(FreezeDefinition $definition, array $stored, array $incoming): void {
		foreach ($definition->writeOnceFields() as $field) {
			$was = ($stored[$field] ?? null);
			if ($this->isEmpty(value: $was) === true || array_key_exists($field, $incoming) === false) {
				continue;
			}

			if ($this->same(left: $was, right: $incoming[$field]) === false) {
				throw $this->refusal(definition: $definition);
			}
		}
	}//end assertWriteOnceHeld()

	/**
	 * The frozen fields the incoming payload moves.
	 *
	 * A payload that repeats a stored value changes nothing: the generic
	 * object API sends the whole object on every write.
	 *
	 * @param FreezeDefinition $definition The freeze entry.
	 * @param array<string, mixed> $stored The record as stored.
	 * @param array<string, mixed> $incoming The record as it would be written.
	 *
	 * @return array<int, string> The moved frozen fields.
	 */
	private function changedFrozenFields(FreezeDefinition $definition, array $stored, array $incoming): array {
		$changed = [];
		foreach ($incoming as $field => $value) {
			$field = (string)$field;
			if ($definition->isFrozenField(field: $field) === false) {
				continue;
			}

			if ($this->same(left: ($stored[$field] ?? null), right: $value) === false) {
				$changed[] = $field;
			}
		}

		return $changed;
	}//end changedFrozenFields()

	/**
	 * Whether two values are the same, regardless of object key order.
	 *
	 * @param mixed $left The stored value.
	 * @param mixed $right The incoming value.
	 *
	 * @return bool True when equal.
	 */
	private function same(mixed $left, mixed $right): bool {
		return $this->canonical(value: $left) === $this->canonical(value: $right);
	}//end same()

	/**
	 * Sort object keys at every depth; leave lists in order.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed The canonical value.
	 */
	private function canonical(mixed $value): mixed {
		if (is_array($value) === false) {
			return $value;
		}

		$canonical = array_map(fn (mixed $item): mixed => $this->canonical(value: $item), $value);
		if (array_is_list($canonical) === false) {
			ksort($canonical, SORT_STRING);
		}

		return $canonical;
	}//end canonical()

	/**
	 * Whether a value counts as not yet written.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool True for null, '' and [].
	 */
	private function isEmpty(mixed $value): bool {
		return $value === null || $value === '' || $value === [];
	}//end isEmpty()

	/**
	 * Whether a payload carries OpenRegister's deletion marker.
	 *
	 * @param array<string, mixed> $record The payload (incl. `@self`).
	 *
	 * @return bool True when marked deleted.
	 */
	private function isMarkedDeleted(array $record): bool {
		if (isset($record['@self']) === true && is_array($record['@self']) === true) {
			return empty($record['@self']['deleted']) === false;
		}

		return empty($record['deleted']) === false;
	}//end isMarkedDeleted()
}//end class
