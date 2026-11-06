<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Permit;

use OCA\Dossiq\Service\Support\SearchesObjects;

/**
 * The dossiq register as a plate change reads and writes it: every call runs as the system.
 *
 * @spec openspec/specs/portal-contribution/spec.md
 */
final class PermitRegister {
	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param object $objectService OpenRegister's object service.
	 * @param string $register      The register.
	 */
	public function __construct(
		private readonly object $objectService,
		private readonly string $register,
	) {
	}//end __construct()

	/**
	 * Read one object, or null.
	 *
	 * @param string $schema The schema slug.
	 * @param string $id     The object id.
	 *
	 * @return array<string, mixed>|null The object.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public function read(string $schema, string $id): ?array {
		if (trim($id) === '') {
			return null;
		}

		// A missing object answers null (findObjectAsArray); any other failure
		// reaches the caller.
		$found = $this->runAsSystemIfAvailable(
			objectService: $this->objectService,
			operation: fn (): ?array => $this->findObjectAsArray(objectService: $this->objectService, register: $this->register, schema: $schema, id: $id)
		);

		if (is_array($found) === false) {
			return null;
		}

		return $found;
	}//end read()

	/**
	 * The objects of a schema that match the filters, without RBAC or tenancy.
	 *
	 * @param string               $schema  The schema slug.
	 * @param array<string, mixed> $filters The filters.
	 *
	 * @return array<int, array<string, mixed>> The matching objects.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public function search(string $schema, array $filters): array {
		return (array)$this->runAsSystemIfAvailable(
			objectService: $this->objectService,
			operation: fn (): array => $this->searchObjectsAsArraysUnscoped(
				objectService: $this->objectService,
				register: $this->register,
				schema: $schema,
				filters: $filters,
			)
		);
	}//end search()

	/**
	 * Write a new object.
	 *
	 * @param string               $schema The schema slug.
	 * @param array<string, mixed> $object The object.
	 *
	 * @return array<string, mixed>|null The stored object.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public function save(string $schema, array $object): ?array {
		return $this->runAsSystemIfAvailable(
			objectService: $this->objectService,
			operation: fn (): ?array => $this->saveObjectAsArray(
				objectService: $this->objectService,
				register: $this->register,
				schema: $schema,
				object: $object
			)
		);
	}//end save()
}//end class
