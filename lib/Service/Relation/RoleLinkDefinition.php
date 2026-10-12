<?php

/**
 * Dossiq relation role definition.
 *
 * One entry of a case type's `relations[]`: a role a case can be linked with,
 * what the role points at, the relation type it writes and the record it opens.
 * This class is the single authority on that shape. The service that links and
 * the listing that reports missing roles both build definitions through
 * its constructor, so a role that is accepted is a role the service can act on.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Relation
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
 * @spec openspec/changes/linked-case-with-a-role/specs/linked-case-with-a-role/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Relation;

use InvalidArgumentException;
use OCA\Dossiq\Service\CaseRelationService;

/**
 * A validated relation role of a case type.
 *
 * @spec openspec/changes/linked-case-with-a-role/specs/linked-case-with-a-role/spec.md#requirement-a-case-type-declares-the-roles-a-case-can-be-linked-with-req-lcr-1
 */
final class RoleLinkDefinition {

	/**
	 * The role points at another case.
	 *
	 * @var string
	 */
	public const TARGET_CASE = 'case';

	/**
	 * The role points at an object that belongs to a case.
	 *
	 * @var string
	 */
	public const TARGET_OBJECT = 'object';

	/**
	 * The role key.
	 *
	 * @var string
	 */
	public readonly string $role;

	/**
	 * What the role is called on screen.
	 *
	 * @var string
	 */
	public readonly string $label;

	/**
	 * TARGET_CASE or TARGET_OBJECT.
	 *
	 * @var string
	 */
	public readonly string $target;

	/**
	 * Config key of the target schema ('' for a case).
	 *
	 * @var string
	 */
	public readonly string $targetSchema;

	/**
	 * Field on the target that holds its case.
	 *
	 * @var string
	 */
	public readonly string $targetCaseField;

	/**
	 * Relation type written between the cases.
	 *
	 * @var string
	 */
	public readonly string $natureRelationship;

	/**
	 * Whether intake needs this role.
	 *
	 * @var bool
	 */
	public readonly bool $requiredAtIntake;

	/**
	 * Config key of the record schema, '' for none.
	 *
	 * @var string
	 */
	public readonly string $recordSchema;

	/**
	 * Record field that holds the linked case.
	 *
	 * @var string
	 */
	public readonly string $recordCaseField;

	/**
	 * Record field that holds the target.
	 *
	 * @var string
	 */
	public readonly string $recordTargetField;

	/**
	 * Record field for the acting user, or null.
	 *
	 * @var string|null
	 */
	public readonly ?string $recordActorField;

	/**
	 * Build and validate a role from its stored form.
	 *
	 * @param mixed $config One `relations[]` entry.
	 *
	 * @throws InvalidArgumentException When the entry is not a well-formed role.
	 *
	 * @spec openspec/changes/linked-case-with-a-role/specs/linked-case-with-a-role/spec.md#requirement-a-case-type-declares-the-roles-a-case-can-be-linked-with-req-lcr-1
	 */
	public function __construct(mixed $config) {
		if (is_array($config) === false) {
			throw new InvalidArgumentException('A relation role must be an object.');
		}

		$role = self::text(config: $config, key: 'role');
		if (preg_match('/^[a-z][a-zA-Z0-9-]*$/', $role) !== 1) {
			throw new InvalidArgumentException('A relation role needs a `role` key of letters, digits and dashes.');
		}

		$target = self::textOr(config: $config, key: 'target', default: self::TARGET_CASE);
		if (in_array($target, [self::TARGET_CASE, self::TARGET_OBJECT], true) === false) {
			throw new InvalidArgumentException("Relation role '$role' points at '$target'; use case or object.");
		}

		$targetSchema = self::text(config: $config, key: 'targetSchema');
		if ($target === self::TARGET_OBJECT && $targetSchema === '') {
			throw new InvalidArgumentException("Relation role '$role' points at an object but names no `targetSchema`.");
		}

		$nature = self::textOr(config: $config, key: 'natureRelationship', default: 'vervolg');
		if (in_array($nature, CaseRelationService::RELATION_TYPES, true) === false) {
			throw new InvalidArgumentException("Relation role '$role' writes `natureRelationship` '$nature', which is not a relation type.");
		}

		$record = ($config['record'] ?? []);
		if (is_array($record) === false) {
			throw new InvalidArgumentException("Relation role '$role' has a `record` that is not an object.");
		}

		$this->role               = $role;
		$this->label              = self::textOr(config: $config, key: 'label', default: $role);
		$this->target             = $target;
		$this->targetSchema       = $targetSchema;
		$this->targetCaseField    = self::textOr(config: $config, key: 'targetCaseField', default: 'case');
		$this->natureRelationship = $nature;
		$this->requiredAtIntake   = (($config['requiredAtIntake'] ?? false) === true);
		$this->recordSchema       = self::text(config: $record, key: 'schema');
		$this->recordCaseField    = self::textOr(config: $record, key: 'caseField', default: 'case');
		$this->recordTargetField  = self::textOr(config: $record, key: 'targetField', default: 'target');
		$this->recordActorField   = self::textOrNull(config: $record, key: 'actorField');
	}//end __construct()

	/**
	 * Whether the role opens a record when a case is linked.
	 *
	 * @return bool True when a record schema is declared.
	 *
	 * @spec openspec/changes/linked-case-with-a-role/specs/linked-case-with-a-role/spec.md#requirement-linking-a-case-with-a-role-writes-the-relation-and-the-record-req-lcr-2
	 */
	public function opensRecord(): bool {
		return $this->recordSchema !== '';
	}//end opensRecord()

	/**
	 * The role as the listing answers it.
	 *
	 * @return array<string, mixed> The public shape.
	 *
	 * @spec openspec/changes/linked-case-with-a-role/specs/linked-case-with-a-role/spec.md#requirement-the-case-says-which-required-roles-are-missing-req-lcr-3
	 */
	public function toArray(): array {
		return [
			'role'               => $this->role,
			'label'              => $this->label,
			'target'             => $this->target,
			'natureRelationship' => $this->natureRelationship,
			'requiredAtIntake'   => $this->requiredAtIntake,
		];
	}//end toArray()

	/**
	 * A trimmed string value, or null when it is absent or empty.
	 *
	 * @param array<array-key, mixed> $config The entry.
	 * @param string                  $key    The key.
	 *
	 * @return string|null The value.
	 */
	private static function textOrNull(array $config, string $key): ?string {
		$value = self::text(config: $config, key: $key);
		if ($value === '') {
			return null;
		}

		return $value;
	}//end textOrNull()

	/**
	 * A trimmed string value, or a default when it is absent or empty.
	 *
	 * @param array<array-key, mixed> $config  The entry.
	 * @param string                  $key     The key.
	 * @param string                  $default The value when none is given.
	 *
	 * @return string The value.
	 */
	private static function textOr(array $config, string $key, string $default): string {
		$value = self::text(config: $config, key: $key);
		if ($value === '') {
			return $default;
		}

		return $value;
	}//end textOr()

	/**
	 * A trimmed string value, or '' when absent or not a string.
	 *
	 * @param array<array-key, mixed> $config The entry.
	 * @param string                  $key    The key.
	 *
	 * @return string The value.
	 */
	private static function text(array $config, string $key): string {
		$value = ($config[$key] ?? '');
		if (is_string($value) === false) {
			return '';
		}

		return trim($value);
	}//end text()
}//end class
