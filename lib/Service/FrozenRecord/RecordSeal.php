<?php

/**
 * Dossiq Record Seal.
 *
 * Hashes the declared fields of a record when it freezes, so anyone can
 * later check that what is stored is what was frozen.
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
use DateTimeInterface;

/**
 * A SHA-256 over a canonical form of the sealed fields.
 *
 * CANONICAL means the same values always give the same bytes: object keys
 * are sorted at every depth, lists keep their order, and a field the record
 * lacks is hashed as null, so adding an unrelated field to the record later
 * does not break the seal while emptying a sealed one does.
 *
 * The seal is stored on the record through OpenRegister's ordinary save, so
 * OpenRegister's own hash-chained audit trail records it as well.
 *
 * @spec openspec/specs/frozen-record/spec.md
 */
class RecordSeal {

	/**
	 * The hash algorithm, stored with the seal so a later change of algorithm can still verify old seals.
	 *
	 * @var string
	 */
	public const ALGORITHM = 'sha256';

	/**
	 * Seal a record's declared fields.
	 *
	 * @param FreezeDefinition $definition The freeze entry.
	 * @param array<string, mixed> $record The record as it freezes.
	 * @param DateTimeImmutable|null $moment When it was sealed; now when null.
	 *
	 * @return array{algorithm: string, hash: string, fields: array<int, string>, sealedAt: string} The seal.
	 *
	 * @spec openspec/specs/frozen-record/spec.md
	 */
	public function seal(FreezeDefinition $definition, array $record, ?DateTimeImmutable $moment = null): array {
		return [
			'algorithm' => self::ALGORITHM,
			'hash' => $this->hash(fields: $definition->sealFields, record: $record),
			'fields' => $definition->sealFields,
			'sealedAt' => ($moment ?? new DateTimeImmutable())->format(DateTimeInterface::ATOM),
		];
	}//end seal()

	/**
	 * Whether the record still matches its seal.
	 *
	 * The fields are the ones the SEAL names, not the ones the configuration
	 * names today: a package that later seals one more field must not make
	 * every earlier seal fail.
	 *
	 * @param array<string, mixed> $record The record as it is stored now.
	 * @param array<string, mixed> $seal The stored seal.
	 *
	 * @return bool True when the hash matches.
	 *
	 * @spec openspec/specs/frozen-record/spec.md
	 */
	public function verify(array $record, array $seal): bool {
		if (($seal['algorithm'] ?? null) !== self::ALGORITHM || is_string(($seal['hash'] ?? null)) === false) {
			return false;
		}

		$fields = [];
		foreach ((array)($seal['fields'] ?? []) as $field) {
			if (is_string($field) === true) {
				$fields[] = $field;
			}
		}

		if ($fields === []) {
			return false;
		}

		return hash_equals($seal['hash'], $this->hash(fields: $fields, record: $record));
	}//end verify()

	/**
	 * The hash of the named fields' canonical form.
	 *
	 * @param array<int, string> $fields The fields.
	 * @param array<string, mixed> $record The record.
	 *
	 * @return string The hex digest.
	 */
	private function hash(array $fields, array $record): string {
		$sealed = [];
		foreach ($fields as $field) {
			$sealed[$field] = $this->canonical(value: ($record[$field] ?? null));
		}

		ksort($sealed, SORT_STRING);

		return hash(
			self::ALGORITHM,
			(string)json_encode($sealed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)
		);
	}//end hash()

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
}//end class
