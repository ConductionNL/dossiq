<?php

/**
 * The organisation roles (`organisatieRol`), read in one place.
 *
 * Read as the caller for what people see, and without RBAC for what
 * OpenRegister asks while it decides an access question (a read that asked the
 * permission handler there would re-enter the decision it is part of).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Access
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/case-type-rights-enforced/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Access;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;

/**
 * Reads the organisation roles.
 *
 * @spec openspec/changes/case-type-rights-enforced/specs/case-types/spec.md
 */
class OrganisationRoles {
	use SearchesObjects;

	/**
	 * At most this many roles are read.
	 */
	private const LIMIT = 500;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Knows the register and the role schema.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
	) {
	}//end __construct()

	/**
	 * Every role, read as the caller; empty when the store or its settings are absent.
	 *
	 * @return array<int, array<string, mixed>> The roles.
	 *
	 * @spec openspec/changes/case-type-rights-enforced/specs/case-types/spec.md
	 */
	public function all(): array {
		return $this->read(scope: []);
	}//end all()

	/**
	 * Every role, read without RBAC and tenancy, for use inside an access decision.
	 *
	 * @return array<int, array<string, mixed>> The roles.
	 *
	 * @spec openspec/changes/case-type-rights-enforced/specs/case-types/spec.md
	 */
	public function allUnscoped(): array {
		return $this->read(scope: ['_rbac' => false, '_multitenancy' => false]);
	}//end allUnscoped()

	/**
	 * Read the roles within a scope.
	 *
	 * @param array<string, bool> $scope The search scope.
	 *
	 * @return array<int, array<string, mixed>> The roles.
	 */
	private function read(array $scope): array {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue(key: 'register');
		$schema = $this->settingsService->getConfigValue(key: 'organisatie_rol_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return [];
		}

		if ($scope !== []) {
			return $this->searchObjectsAsArraysUnscoped(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: ['_limit' => self::LIMIT]
			);
		}

		return $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			filters: ['_limit' => self::LIMIT]
		);
	}//end read()

	/**
	 * The ids a role named by id or role name is known by: its id and its name.
	 *
	 * @param string                           $role  The role as a rights row names it.
	 * @param array<int, array<string, mixed>> $roles The roles.
	 *
	 * @return array<int, string> The keys; just the given name when no role matches.
	 *
	 * @spec openspec/changes/case-type-rights-enforced/specs/case-types/spec.md
	 */
	public function keysOf(string $role, array $roles): array {
		foreach ($roles as $candidate) {
			$keys = [(string)($candidate['id'] ?? ''), (string)($candidate['roleName'] ?? '')];
			if (in_array($role, $keys, true) === true) {
				return array_values(array_unique(array_filter($keys, static fn (string $key): bool => $key !== '')));
			}
		}

		return [$role];
	}//end keysOf()
}//end class
