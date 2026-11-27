<?php

/**
 * Dossiq case role source.
 *
 * Reads the stored role records of one case from the configured register
 * and role schema, refusing when the register cannot be read. Split out of
 * {@see \OCA\Dossiq\Service\RoleResolverService}, which keeps the
 * strategies, the area, the fallback, the delegation and the cache.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Routing
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
 * @spec openspec/specs/role-based-step-routing/spec.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Routing;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\SettingsService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The stored role records of a case.
 *
 * @spec openspec/specs/role-based-step-routing/spec.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class CaseRoleSource {

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The settings and OpenRegister access.
	 * @param LoggerInterface $logger          Logs a read that failed before it is refused.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The stored role records of one case; null when there is nowhere to read them.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return mixed What the register answered, or null without an object service, register or role schema.
	 *
	 * @throws RefusedException When the role register could not be read at all.
	 *
	 * @spec openspec/specs/role-based-step-routing/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function recordsOf(string $caseId): mixed {
		$source = $this->roleSource();
		if ($source === null) {
			return null;
		}

		return $this->readRoles(source: $source, caseId: $caseId);
	}//end recordsOf()

	/**
	 * Where the case roles are read: the object service, register and role schema; null when any is missing.
	 *
	 * @return array{0: object, 1: string, 2: string}|null The source.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function roleSource(): ?array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return null;
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('role_schema');
		if ($register === '' || $schema === '') {
			return null;
		}

		return [$objectService, $register, $schema];
	}//end roleSource()

	/**
	 * The stored role records of one case; refused when the register cannot be read.
	 *
	 * @param array{0: object, 1: string, 2: string} $source The object service, register and role schema.
	 * @param string                                 $caseId The case UUID.
	 *
	 * @return mixed What the register answered.
	 *
	 * @throws RefusedException When the role register could not be read at all.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function readRoles(array $source, string $caseId): mixed {
		[$objectService, $register, $schema] = $source;

		// OpenRegister's ObjectService::findAll() takes ONE config array. This
		// call used to pass ($register, $schema, $filters) positionally, which
		// is a TypeError against `array $config` — and the catch that stood
		// here turned that TypeError into an empty role list, so stored case
		// roles were never loaded and rule resolution silently fell through to
		// its other sources. An empty role list is also how a routing rule
		// resolves to nobody, which is why the read now refuses instead.
		try {
			return $objectService->findAll(['filters' => ['register' => $register, 'schema' => $schema, 'case' => $caseId]]);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: the roles on case ' . $caseId . ' could not be read: ' . $e->getMessage(),
			);

			throw new RefusedException(
				rule: 'case-roles-unreadable',
				sentence: 'The roles on this case could not be read, so the routing cannot be worked out right now.',
				status: RefusedException::STATUS_INDETERMINATE,
				previous: $e,
			);
		}
	}//end readRoles()
}//end class
