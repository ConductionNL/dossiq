<?php

/**
 * An in-memory register that judges lifecycle fields as OpenRegister does.
 *
 * It reads the `x-openregister-lifecycle` block each schema declares in
 * lib/Settings/dossiq_register.json. A create is refused when it names a
 * state other than the declared initial one or one of its `createStates`
 * (openregister#5400), and an empty lifecycle field is filled with the
 * initial state, resolved through `x-openregister-references` when it is
 * declared as `{from, field}`. An update that changes the field is refused
 * unless a declared transition allows the move. A schema without transitions
 * leaves its moves to the app, as OpenRegister does.
 *
 * A create whose payload carries `@self.id` or `id` writes that object, as
 * OpenRegister's saveObject() does, so a forged id shows up as an update.
 * `patchObject()` reads, merges and saves as OpenRegister's does, and a schema
 * listed in `callerMayOnlyCreate` refuses the caller every update and delete
 * that is not run with `_rbac: false`.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/bezwaar-hearing/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Support;

use OCA\OpenRegister\Service\Object\ObjectFindTarget;
use OCA\OpenRegister\Service\Object\ObjectSaveOptions;
use RuntimeException;

/**
 * A register that refuses creates outside the initial state and undeclared moves.
 */
class LifecycleRegister extends RefusableRegister {

	/**
	 * The schema configuration blocks of the register file, by slug.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private static ?array $configurations = null;

	/**
	 * Every write that changed a lifecycle field, as `schema: from -> to`.
	 *
	 * @var array<int, string>
	 */
	public array $moves = [];

	/**
	 * Whether an update must follow a declared transition; creates are always judged.
	 *
	 * @var bool
	 */
	public bool $judgesMoves = true;

	/**
	 * Schemas whose objects the caller may create but not update or delete.
	 *
	 * @var array<int, string>
	 */
	public array $callerMayOnlyCreate = [];

	/**
	 * How many writes came through patchObject().
	 *
	 * @var int
	 */
	public int $patches = 0;

	/**
	 * Every delete, as `schema/uuid`.
	 *
	 * @var array<int, string>
	 */
	public array $deletes = [];

	/**
	 * Save one row, judged against the schema's declared lifecycle.
	 *
	 * @param array<string, mixed> $object        The row.
	 * @param int|string           $register      The register.
	 * @param int|string           $schema        The schema slug.
	 * @param string|null          $uuid          The uuid, or null for a new row.
	 * @param bool                 $_rbac         Passed on.
	 * @param bool                 $_multitenancy Passed on.
	 *
	 * @return array<string, mixed> The saved row.
	 *
	 * @throws RuntimeException When the lifecycle refuses the write.
	 */
	public function saveObject(
		array $object,
		ObjectSaveOptions $options = new ObjectSaveOptions(),
		bool $_rbac = true,
		bool $_multitenancy = true,
	): array {
		$register = ($options->target->register ?? '');
		$schema = ($options->target->schema ?? '');
		$uuid = $options->uuid;
		$uuid ??= $this->payloadId(object: $object);
		$stored  = [];
		if ($uuid !== null) {
			$stored = $this->row(schema: (string)$schema, uuid: $uuid);
		}

		if ($stored !== [] && $_rbac === true && in_array((string)$schema, $this->callerMayOnlyCreate, true) === true) {
			throw new RuntimeException("The caller may not update objects in schema '" . (string)$schema . "'.");
		}

		$configuration = (self::configurations()[(string)$schema] ?? []);
		$lifecycle     = ($configuration['x-openregister-lifecycle'] ?? null);
		if (is_array($lifecycle) === true) {

			if ($stored === []) {
				$object = $this->judgeCreate(schema: (string)$schema, object: $object);
			} else {
				$this->judgeMove(schema: (string)$schema, rows: ['old' => $stored, 'new' => $object]);
			}
		}

		$options = new ObjectSaveOptions(
			target: $options->target,
			uuid: $uuid,
			extend: $options->extend,
			uploadedFiles: $options->uploadedFiles,
			policy: $options->policy,
			actor: $options->actor
		);

		return parent::saveObject(object: $object, options: $options, _rbac: $_rbac, _multitenancy: $_multitenancy);
	}//end saveObject()

	/**
	 * Merge fields onto a stored object and save it, as ObjectService::patchObject() does.
	 *
	 * @param string               $objectId      The uuid.
	 * @param array<string, mixed> $data          The fields to change.
	 * @param int|string|null      $register      The register.
	 * @param int|string|null      $schema        The schema slug.
	 * @param bool                 $_rbac         Whether the caller's rights apply.
	 * @param bool                 $_multitenancy Passed on.
	 *
	 * @return array<string, mixed> The saved row.
	 *
	 * @throws RuntimeException When there is no such object, or the save is refused.
	 */
	public function patchObject(
		string $objectId,
		array $data,
		int|string|null $register = null,
		int|string|null $schema = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
	): array {
		$this->patches += 1;
		$stored = $this->row(schema: (string)$schema, uuid: $objectId);
		if ($stored === []) {
			throw new RuntimeException('No object ' . $objectId . ' to patch.');
		}

		return $this->saveObject(object: array_merge($stored, $data), options: new ObjectSaveOptions(target: new ObjectFindTarget(register: (string)$register, schema: (string)$schema), uuid: $objectId), _rbac: $_rbac, _multitenancy: $_multitenancy);
	}//end patchObject()

	/**
	 * Remove one object, refused to the caller on a schema it may only create in.
	 *
	 * @param int|string $register      The register.
	 * @param int|string $schema        The schema slug.
	 * @param string     $uuid          The uuid.
	 * @param bool       $_rbac         Whether the caller's rights apply.
	 * @param bool       $_multitenancy Passed on.
	 *
	 * @return bool True when a row was removed.
	 *
	 * @throws RuntimeException When the caller may not delete in the schema.
	 */
	public function deleteObject(int|string $register = '', int|string $schema = '', string $uuid = '', bool $_rbac = true, bool $_multitenancy = true): bool {
		if ($_rbac === true && in_array((string)$schema, $this->callerMayOnlyCreate, true) === true) {
			throw new RuntimeException("The caller may not delete objects in schema '" . (string)$schema . "'.");
		}

		$this->deletes[] = (string)$schema . '/' . $uuid;

		return parent::deleteObject(register: $register, schema: $schema, uuid: $uuid);
	}//end deleteObject()

	/**
	 * The object a payload names, as OpenRegister reads it: `@self.id`, else `id`.
	 *
	 * @param array<string, mixed> $object The payload.
	 *
	 * @return string|null The uuid, or null when it names none.
	 */
	private function payloadId(array $object): ?string {
		$named = trim((string)($object['@self']['id'] ?? ($object['id'] ?? '')));
		if ($named === '') {
			return null;
		}

		return $named;
	}//end payloadId()

	/**
	 * The new row with its initial state filled in, or a refusal.
	 *
	 * @param string               $schema The schema slug.
	 * @param array<string, mixed> $object The new row.
	 *
	 * @return array<string, mixed> The row to store.
	 *
	 * @throws RuntimeException When the row names a state it may not be created in.
	 */
	private function judgeCreate(string $schema, array $object): array {
		$configuration = self::configurations()[$schema];
		$lifecycle     = $configuration['x-openregister-lifecycle'];
		$field         = (string)$lifecycle['field'];
		$initial       = $this->initialOf(configuration: $configuration, object: $object);
		$chosen        = ($object[$field] ?? null);
		if ($chosen === null || $chosen === '') {
			if ($initial !== null) {
				$object[$field] = $initial;
			}

			return $object;
		}

		if ($chosen !== $initial && in_array($chosen, (array)($lifecycle['createStates'] ?? []), true) === false) {
			throw new RuntimeException(sprintf('A new object starts in "%s"; "%s" cannot be set when it is created.', (string)$initial, (string)$chosen));
		}

		return $object;
	}//end judgeCreate()

	/**
	 * Refuse a change of the lifecycle field that no declared transition allows.
	 *
	 * @param string                                                      $schema The schema slug.
	 * @param array{old: array<string, mixed>, new: array<string, mixed>} $rows   The stored row and the new one.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When no transition allows the move.
	 */
	private function judgeMove(string $schema, array $rows): void {
		$lifecycle = self::configurations()[$schema]['x-openregister-lifecycle'];
		$field     = (string)$lifecycle['field'];
		$from      = ($rows['old'][$field] ?? null);
		$to        = ($rows['new'][$field] ?? null);
		if ($from === $to) {
			return;
		}

		$this->moves[] = $schema . ': ' . (string)$from . ' -> ' . (string)$to;
		$transitions   = (array)($lifecycle['transitions'] ?? []);
		if ($transitions === [] || $this->judgesMoves === false) {
			return;
		}

		foreach ($transitions as $transition) {
			if (($transition['to'] ?? null) === $to && in_array($from, (array)($transition['from'] ?? []), true) === true) {
				return;
			}
		}

		throw new RuntimeException(sprintf('No transition allows moving "%s" from "%s" to "%s".', $field, (string)$from, (string)$to));
	}//end judgeMove()

	/**
	 * The declared initial state, resolved through a reference when it names one.
	 *
	 * @param array<string, mixed> $configuration The schema's configuration block.
	 * @param array<string, mixed> $object        The new row.
	 *
	 * @return string|null The initial state, or null when it does not resolve.
	 */
	private function initialOf(array $configuration, array $object): ?string {
		$initial = $configuration['x-openregister-lifecycle']['initial'];
		if (is_string($initial) === true) {
			return $initial;
		}

		$reference = ($configuration['x-openregister-references'][(string)$initial['from']] ?? []);
		$related   = $this->row(schema: (string)($reference['schema'] ?? ''), uuid: (string)($object[(string)($reference['field'] ?? '')] ?? ''));
		$value     = ($related[(string)$initial['field']] ?? null);
		if ($value === null || $value === '') {
			return null;
		}

		return (string)$value;
	}//end initialOf()

	/**
	 * The configuration block of every schema in the register file, read once.
	 *
	 * @return array<string, array<string, mixed>> By slug.
	 */
	private static function configurations(): array {
		if (self::$configurations === null) {
			$register = json_decode((string)file_get_contents(__DIR__ . '/../../lib/Settings/dossiq_register.json'), true);
			self::$configurations = array_map(
				static fn (array $schema): array => (array)($schema['configuration'] ?? []),
				(array)($register['components']['schemas'] ?? [])
			);
		}

		return self::$configurations;
	}//end configurations()
}//end class
