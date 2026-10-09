<?php

/**
 * An in-memory register that answers a save the way OpenRegister does for callers that read `getUuid()`.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Support
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Support;

/**
 * Delegates to InMemoryRegister; a save on the named schemas answers an entity with a uuid.
 */
class UuidAnsweringRegister {

	/**
	 * Constructor.
	 *
	 * @param InMemoryRegister   $store   The rows.
	 * @param array<int, string> $schemas The schemas whose saves answer an entity.
	 */
	public function __construct(
		public readonly InMemoryRegister $store,
		private readonly array $schemas = ['decision'],
	) {
	}//end __construct()

	/**
	 * Find, as the store does.
	 *
	 * @param int|string $id       The uuid.
	 * @param mixed      $_extend  Ignored.
	 * @param bool       $files    Ignored.
	 * @param int|string $register Ignored.
	 * @param int|string $schema   The schema.
	 * @param bool       $_rbac          Ignored: the store is not scoped.
	 * @param bool       $_multitenancy  Ignored: the store is not scoped.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find(
		int|string $id,
		mixed $_extend = null,
		bool $files = false,
		int|string $register = '',
		int|string $schema = '',
		bool $_rbac = true,
		bool $_multitenancy = true,
	): ?array {
		return $this->store->find(id: $id, register: $register, schema: $schema);
	}//end find()

	/**
	 * Search, as the store does.
	 *
	 * @param string               $register Ignored.
	 * @param string               $schema   The schema.
	 * @param array<string, mixed> $filters  Filters.
	 * @param bool                 $_rbac          Ignored: the store is not scoped.
	 * @param bool                 $_multitenancy  Ignored: the store is not scoped.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function searchObjectsBySlug(
		string $register,
		string $schema,
		array $filters = [],
		bool $_rbac = true,
		bool $_multitenancy = true,
	): array {
		return $this->store->searchObjectsBySlug($register, $schema, $filters);
	}//end searchObjectsBySlug()

	/**
	 * Save, answering an entity for the named schemas.
	 *
	 * @param array<string, mixed> $object   The row.
	 * @param int|string           $register Ignored.
	 * @param int|string           $schema   The schema.
	 * @param string|null          $uuid     The uuid, or null to create.
	 * @param bool                 $_rbac          Ignored: the store is not scoped.
	 * @param bool                 $_multitenancy  Ignored: the store is not scoped.
	 *
	 * @return mixed
	 */
	public function saveObject(
		array $object,
		int|string $register = '',
		int|string $schema = '',
		?string $uuid = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
	): mixed {
		$row = $this->store->saveObject(object: $object, register: $register, schema: $schema, uuid: $uuid);
		if (in_array((string)$schema, $this->schemas, true) === false) {
			return $row;
		}

		return new class((string)$row['id']) {
			/**
			 * @param string $id The uuid.
			 */
			public function __construct(private readonly string $id) {
			}

			/**
			 * @return string
			 */
			public function getUuid(): string {
				return $this->id;
			}
		};
	}//end saveObject()
}//end class
