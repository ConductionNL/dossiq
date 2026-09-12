<?php

/**
 * Test double: an object service whose save replaces, the way OpenRegister's does.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/document-zaakdossier/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Fixtures;

use OCP\AppFramework\Db\DoesNotExistException;
use RuntimeException;

/**
 * An object service that replaces on save, the way OpenRegister does.
 *
 * Deliberately has no `patchObject()`: this is the older OpenRegister shape,
 * where a partial write can only be done by reading and saving the whole.
 */
class ReplacingObjectService {

	/**
	 * Stored objects by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $stored = [];

	/**
	 * Every payload saveObject() was handed, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $saves = [];

	/**
	 * Constructor.
	 *
	 * @param string[] $required   The schema's required properties.
	 * @param string[] $properties The schema's declared properties.
	 */
	public function __construct(
		private readonly array $required,
		private readonly array $properties,
	) {
	}

	/**
	 * A double that enforces what the shipped register declares for one schema.
	 *
	 * @param string $slug The schema slug in the shipped register.
	 *
	 * @return static
	 */
	public static function forShippedSchema(string $slug): static {
		$schema = ShippedRegisterSchema::schema($slug);

		return new static(($schema['required'] ?? []), array_keys(($schema['properties'] ?? [])));
	}

	/**
	 * Read one object back, shaped like ObjectEntity::jsonSerialize().
	 *
	 * @param string          $id       The uuid.
	 * @param string|int|null $register Unused register scope.
	 * @param string|int|null $schema   Unused schema scope.
	 *
	 * @return object The stored object with its id and an `@self` block.
	 *
	 * @throws DoesNotExistException When nothing is stored under the id.
	 */
	public function find(string $id, string|int|null $register = null, string|int|null $schema = null): object {
		if (isset($this->stored[$id]) === false) {
			throw new DoesNotExistException('not found: ' . $id);
		}

		return $this->entity(uuid: $id);
	}

	/**
	 * Save with PUT semantics: the payload replaces the stored object.
	 *
	 * @param array<string, mixed> $object   The payload.
	 * @param string|int|null      $register Unused register scope.
	 * @param string|int|null      $schema   Unused schema scope.
	 * @param string|null          $uuid     The uuid to update, or null to take it from the payload.
	 *
	 * @return object The stored object.
	 *
	 * @throws RuntimeException When a required property is missing, as OpenRegister's validation does.
	 */
	public function saveObject(
		array $object,
		string|int|null $register = null,
		string|int|null $schema = null,
		?string $uuid = null,
	): object {
		$this->saves[] = $object;

		$self = (array)($object['@self'] ?? []);
		$uuid = ($uuid ?? (string)($object['id'] ?? ($self['id'] ?? '')));
		unset($object['@self'], $object['id']);

		$missing = [];
		foreach ($this->required as $property) {
			if (isset($object[$property]) === false) {
				$missing[] = $property;
			}
		}

		if ($missing !== []) {
			throw new RuntimeException('The required properties (' . implode(', ', $missing) . ') are missing.');
		}

		foreach ($this->properties as $property) {
			if (array_key_exists($property, $object) === false) {
				$object[$property] = null;
			}
		}

		$this->stored[$uuid] = $object;

		return $this->entity(uuid: $uuid);
	}

	/**
	 * Wrap a stored object the way OpenRegister's ObjectEntity serialises.
	 *
	 * @param string $uuid The uuid.
	 *
	 * @return object An object exposing jsonSerialize().
	 */
	protected function entity(string $uuid): object {
		$data = array_merge(
			$this->stored[$uuid],
			['id' => $uuid, '@self' => ['id' => $uuid, 'register' => 'dossiq', 'schema' => 'informatieobject']]
		);

		return new class($data) {
			/**
			 * Constructor.
			 *
			 * @param array<string, mixed> $data The serialised object.
			 */
			public function __construct(
				private readonly array $data,
			) {
			}

			/**
			 * The serialised object.
			 *
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return $this->data;
			}
		};
	}
}//end class
