<?php

/**
 * Dossiq unscoped object reader.
 *
 * Reads one object by id as the system, without RBAC or multitenancy, on a
 * register and schema the caller pins. A request that carries no Nextcloud
 * user (every portal forward) finds nothing through a scoped read, so the
 * caller reads unscoped and decides itself who may see the row.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Support
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
 * @spec openspec/specs/woo-request-intake/spec.md#requirement-only-the-owners-dossier-starts-a-request-req-wri-003
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Support;

use OCP\AppFramework\Db\DoesNotExistException;

/**
 * One object by id, read as the system and unscoped.
 *
 * @spec openspec/specs/woo-request-intake/spec.md#requirement-only-the-owners-dossier-starts-a-request-req-wri-003
 */
final class UnscopedObjectReader {

	use SearchesObjects;

	/**
	 * One object by id on a pinned register and schema, as the system and unscoped.
	 *
	 * @param object     $objectService The OpenRegister ObjectService.
	 * @param int|string $register      The register.
	 * @param int|string $schema        The schema.
	 * @param string     $id            The uuid.
	 *
	 * @return array<string, mixed>|null The object, or null when it does not exist.
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-only-the-owners-dossier-starts-a-request-req-wri-003
	 */
	public function find(object $objectService, int|string $register, int|string $schema, string $id): ?array {
		return $this->runAsSystemIfAvailable(
			objectService: $objectService,
			operation: fn (): ?array => $this->findUnscoped(objectService: $objectService, register: $register, schema: $schema, id: $id)
		);
	}//end find()

	/**
	 * One object by id, without `_rbac` and `_multitenancy`.
	 *
	 * @param object     $objectService The OpenRegister ObjectService.
	 * @param int|string $register      The register.
	 * @param int|string $schema        The schema.
	 * @param string     $id            The uuid.
	 *
	 * @return array<string, mixed>|null The object, or null when it does not exist.
	 */
	private function findUnscoped(object $objectService, int|string $register, int|string $schema, string $id): ?array {
		try {
			$object = $objectService->find(
				id: $id,
				register: $register,
				schema: $schema,
				_rbac: false,
				_multitenancy: false,
			);
		} catch (DoesNotExistException $e) {
			return null;
		}

		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$serialized = $object->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}
		}

		return null;
	}//end findUnscoped()
}//end class
