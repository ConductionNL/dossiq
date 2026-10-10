<?php

/**
 * Dossiq Freeze Definition.
 *
 * What a package declares about one kind of record that freezes: which
 * schema, at which statuses, which fields stop moving, which fields are
 * written once, what goes into the seal, and how a successor is numbered.
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

use InvalidArgumentException;

/**
 * One `freeze[]` entry, validated.
 *
 * It names no procedure: the schema, the statuses and the fields are data.
 * An entry the schema of the configuration would refuse is refused here too,
 * because a freeze that silently freezes nothing is the failure that matters.
 *
 * @spec openspec/specs/frozen-record/spec.md
 */
final class FreezeDefinition {

	/**
	 * The refusal rule a frozen record answers with, unless the entry names another.
	 *
	 * @var string
	 */
	public const DEFAULT_RULE = 'record-frozen';

	/**
	 * The sentence a frozen record answers with, unless the entry names another.
	 *
	 * @var string
	 */
	public const DEFAULT_MESSAGE = 'This record is frozen and cannot be changed or deleted. Issue a successor instead.';

	/**
	 * Keys that are envelope, not content, and never count as a changed field.
	 *
	 * @var array<int, string>
	 */
	private const ENVELOPE_KEYS = ['id', 'uuid'];

	/**
	 * Constructor.
	 *
	 * @param string $schema The schema id or slug the entry guards.
	 * @param array<int, string> $frozenStatuses The statuses from which the record is frozen.
	 * @param string $statusField The field holding the status.
	 * @param array<int, string> $fields Frozen fields; empty means every field not listed as mutable.
	 * @param array<int, string> $mutableFields Fields that still move on a frozen record, when $fields is empty.
	 * @param array<int, string> $writeOnce Fields written once and never again, at any status.
	 * @param array<int, string> $sealFields Fields hashed into the seal when the record freezes; empty for no seal.
	 * @param string $sealField Where the seal is stored.
	 * @param array<string, string>|null $successor Successor numbering: series, defaultPrefix, numberField,
	 *                                              supersedesField, supersededByField; null for none.
	 * @param string $rule The refusal rule.
	 * @param string $message The refusal sentence.
	 *
	 * @throws InvalidArgumentException When the entry declares no schema or no frozen status.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) A value object for one configuration entry: each
	 *                                                 parameter is one key of that entry, named.
	 */
	public function __construct(
		public readonly string $schema,
		public readonly array $frozenStatuses,
		public readonly string $statusField = 'currentStatus',
		public readonly array $fields = [],
		public readonly array $mutableFields = [],
		public readonly array $writeOnce = [],
		public readonly array $sealFields = [],
		public readonly string $sealField = 'seal',
		public readonly ?array $successor = null,
		public readonly string $rule = self::DEFAULT_RULE,
		public readonly string $message = self::DEFAULT_MESSAGE,
	) {
		if (trim($schema) === '') {
			throw new InvalidArgumentException('A freeze entry must name the schema it guards.');
		}

		if ($frozenStatuses === []) {
			throw new InvalidArgumentException(sprintf('The freeze entry for %s names no status at which the record freezes.', $schema));
		}

		if ($successor !== null && trim(($successor['series'] ?? '')) === '') {
			throw new InvalidArgumentException(sprintf('The freeze entry for %s declares a successor without a series.', $schema));
		}
	}//end __construct()

	/**
	 * Read an entry from configuration.
	 *
	 * @param array<string, mixed> $entry One `freeze[]` entry.
	 *
	 * @return self The definition.
	 *
	 * @throws InvalidArgumentException When the entry is incomplete.
	 *
	 * @spec openspec/specs/frozen-record/spec.md
	 */
	public static function fromArray(array $entry): self {
		$successor = null;
		if (is_array(($entry['successor'] ?? null)) === true) {
			$raw = $entry['successor'];
			$successor = [
				'series' => trim((string)($raw['series'] ?? '')),
				'defaultPrefix' => trim((string)($raw['defaultPrefix'] ?? '')),
				'numberField' => self::name(value: ($raw['numberField'] ?? null), default: 'reference'),
				'supersedesField' => self::name(value: ($raw['supersedesField'] ?? null), default: 'supersedes'),
				'supersededByField' => self::name(value: ($raw['supersededByField'] ?? null), default: 'supersededBy'),
			];
		}

		return new self(
			schema: trim((string)($entry['schema'] ?? '')),
			frozenStatuses: self::names(value: ($entry['frozenStatuses'] ?? [])),
			statusField: self::name(value: ($entry['statusField'] ?? null), default: 'currentStatus'),
			fields: self::names(value: ($entry['fields'] ?? [])),
			mutableFields: self::names(value: ($entry['mutableFields'] ?? [])),
			writeOnce: self::names(value: ($entry['writeOnce'] ?? [])),
			sealFields: self::names(value: ($entry['sealFields'] ?? [])),
			sealField: self::name(value: ($entry['sealField'] ?? null), default: 'seal'),
			successor: $successor,
			rule: self::name(value: ($entry['rule'] ?? null), default: self::DEFAULT_RULE),
			message: self::name(value: ($entry['message'] ?? null), default: self::DEFAULT_MESSAGE),
		);
	}//end fromArray()

	/**
	 * Whether a status is one at which the record is frozen.
	 *
	 * @param string $status The status.
	 *
	 * @return bool True when frozen.
	 *
	 * @spec openspec/specs/frozen-record/spec.md
	 */
	public function freezesAt(string $status): bool {
		return in_array($status, $this->frozenStatuses, true);
	}//end freezesAt()

	/**
	 * Every write-once field: the declared ones, the seal and the successor's forward pointer.
	 *
	 * @return array<int, string> Field names.
	 *
	 * @spec openspec/specs/frozen-record/spec.md
	 */
	public function writeOnceFields(): array {
		$fields = $this->writeOnce;
		if ($this->sealFields !== []) {
			$fields[] = $this->sealField;
		}

		if ($this->successor !== null) {
			$fields[] = $this->successor['supersededByField'];
		}

		return array_values(array_unique($fields));
	}//end writeOnceFields()

	/**
	 * Whether a field is frozen once the record is.
	 *
	 * With an explicit field list, the list plus the successor's number and
	 * backward pointer. Without one, every content field except the status,
	 * the mutable fields and the write-once fields (which have their own rule).
	 *
	 * @param string $field The field name.
	 *
	 * @return bool True when the field may no longer move.
	 *
	 * @spec openspec/specs/frozen-record/spec.md
	 */
	public function isFrozenField(string $field): bool {
		if ($field === '' || $field[0] === '@' || $field[0] === '_' || in_array($field, self::ENVELOPE_KEYS, true) === true) {
			return false;
		}

		if ($field === $this->statusField || in_array($field, $this->writeOnceFields(), true) === true) {
			return false;
		}

		if ($this->fields === []) {
			return in_array($field, $this->mutableFields, true) === false;
		}

		$frozen = $this->fields;
		if ($this->successor !== null) {
			$frozen[] = $this->successor['numberField'];
			$frozen[] = $this->successor['supersedesField'];
		}

		return in_array($field, $frozen, true);
	}//end isFrozenField()

	/**
	 * A trimmed name, or the default when none is given.
	 *
	 * @param mixed $value The configured value.
	 * @param string $default The default.
	 *
	 * @return string The name.
	 */
	private static function name(mixed $value, string $default): string {
		if (is_string($value) === true && trim($value) !== '') {
			return trim($value);
		}

		return $default;
	}//end name()

	/**
	 * A list of non-empty trimmed names.
	 *
	 * @param mixed $value The configured list.
	 *
	 * @return array<int, string> The names.
	 */
	private static function names(mixed $value): array {
		$names = [];
		foreach ((array)$value as $item) {
			if (is_string($item) === true && trim($item) !== '') {
				$names[] = trim($item);
			}
		}

		return array_values(array_unique($names));
	}//end names()
}//end class
