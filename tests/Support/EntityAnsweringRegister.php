<?php

/**
 * OpenRegister's ObjectService as BezwaarAuditTrail sees it.
 *
 * `ObjectService::find()` answers an ObjectEntity, never an array, and the
 * audit mapper takes that entity. This adapter answers one for every row the
 * shared InMemoryRegister holds, so the bezwaar services and the audit writer
 * read the same store, and throws for a row that is not there, as the real
 * service does.
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
 * @spec openspec/specs/bezwaar-awb-audit-trail/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Support;

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Db\DoesNotExistException;

class EntityAnsweringRegister {
	/**
	 * Constructor.
	 *
	 * @param InMemoryRegister $register The shared store.
	 */
	public function __construct(
		private readonly InMemoryRegister $register,
	) {
	}//end __construct()

	/**
	 * Find one object as an entity.
	 *
	 * @param int|string $id       The uuid.
	 * @param mixed      $_extend  Unused.
	 * @param bool       $files    Unused.
	 * @param int|string $register The register.
	 * @param int|string $schema   The schema.
	 * @param bool       $_rbac         Ignored: this store is not scoped.
	 * @param bool       $_multitenancy Ignored: this store is not scoped.
	 *
	 * @return ObjectEntity The entity.
	 *
	 * @throws DoesNotExistException When there is no such row.
	 */
	public function find(
		int|string $id,
		mixed $_extend = null,
		bool $files = false,
		int|string $register = '',
		int|string $schema = '',
		bool $_rbac = true,
		bool $_multitenancy = true,
	): ObjectEntity {
		$row = $this->register->find(id: $id, register: $register, schema: $schema);
		if ($row === null) {
			throw new DoesNotExistException('no such object');
		}

		$entity = new ObjectEntity();
		$entity->setUuid((string) $id);
		$entity->setSchema((string) $schema);
		$entity->setObject($row);

		return $entity;
	}//end find()

	/**
	 * Save one object into the shared store, as ObjectService::saveObject() does.
	 *
	 * @param array<string, mixed> $object        The object.
	 * @param int|string           $register      The register.
	 * @param int|string           $schema        The schema.
	 * @param string|null          $uuid          The uuid, or null for a new one.
	 * @param bool                 $_rbac         Ignored: this store is not scoped.
	 * @param bool                 $_multitenancy Ignored: this store is not scoped.
	 *
	 * @return array<string, mixed> The stored row.
	 */
	public function saveObject(
		array $object,
		int|string $register = '',
		int|string $schema = '',
		?string $uuid = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
	): array {
		return $this->register->saveObject(object: $object, register: $register, schema: $schema, uuid: $uuid);
	}//end saveObject()

	/**
	 * One page of rows, as ObjectService::findAll() answers, when the store pages.
	 *
	 * @param array<string, mixed> $config        The query.
	 * @param bool                 $_rbac         Ignored: this store is not scoped.
	 * @param bool                 $_multitenancy Ignored: this store is not scoped.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
		if ($this->register instanceof RefusableRegister) {
			return $this->register->findAll(config: $config);
		}

		return [];
	}//end findAll()
}//end class
