<?php

/**
 * The dossier register and its configured schemas, as the case record stores read them.
 *
 * One reading of OpenRegister's object service, the configured register and a
 * configured schema, each refused with the same sentence wherever a store
 * asks, so the custody chain and the incident log cannot drift apart on it.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Custody
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
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Custody;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use RuntimeException;
use Throwable;

/**
 * The object service, the register and a configured schema, or the exception naming what is missing.
 *
 * @spec openspec/specs/case-management/spec.md
 */
final class DossierRegister {
	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Bridge to OpenRegister and the configured schemas.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
	) {
	}//end __construct()

	/**
	 * The object service and the register, or an exception.
	 *
	 * @return array{0: object, 1: string} The service and the register.
	 *
	 * @throws RuntimeException When OpenRegister is absent or unconfigured.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function context(): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$register = $this->settingsService->getConfigValue('register');
		if ($register === '') {
			throw new RuntimeException('Dossier register not configured');
		}

		return [$objectService, $register];
	}//end context()

	/**
	 * A configured schema, or an exception naming the key.
	 *
	 * @param string $key The configuration key.
	 *
	 * @return string The schema id or slug.
	 *
	 * @throws RuntimeException When the key is unset.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function schema(string $key): string {
		$schema = $this->settingsService->getConfigValue($key);
		if ($schema === '') {
			throw new RuntimeException('Dossiq schema ' . $key . ' not configured');
		}

		return $schema;
	}//end schema()

	/**
	 * The stored case, or a refusal saying what was not done.
	 *
	 * A read that fails is indeterminate; a case that is not there is unprocessable.
	 *
	 * @param string $caseId   The case uuid.
	 * @param string $rule     The rule both refusals carry.
	 * @param string $sentence The sentence both refusals carry.
	 *
	 * @return array<string, mixed> The stored case.
	 *
	 * @throws RefusedException When it cannot be read.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function requireCase(string $caseId, string $rule, string $sentence): array {
		try {
			$case = $this->readCase(caseId: $caseId);
		} catch (Throwable $e) {
			throw new RefusedException(
				rule: $rule,
				sentence: $sentence,
				status: RefusedException::STATUS_INDETERMINATE,
				previous: $e,
			);
		}

		if ($case === null) {
			throw new RefusedException(
				rule: $rule,
				sentence: $sentence,
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		return $case;
	}//end requireCase()

	/**
	 * The stored case, or null when it is not there.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return array<string, mixed>|null The stored case.
	 *
	 * @throws Throwable When the store cannot be reached or the read fails.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	private function readCase(string $caseId): ?array {
		[$objectService, $register] = $this->context();

		return $this->findObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $this->schema(key: 'case_schema'),
			id: $caseId,
		);
	}//end readCase()

	/**
	 * Save a case's payload over the stored case.
	 *
	 * @param string               $caseId  The case uuid.
	 * @param array<string, mixed> $payload The case without its identity fields.
	 *
	 * @return void
	 *
	 * @throws Throwable When the register is not configured or the save fails.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function saveCase(string $caseId, array $payload): void {
		[$objectService, $register] = $this->context();
		$objectService->saveObject(
			object: $payload,
			options: new ObjectSaveOptions(
				target: new ObjectFindTarget(register: $register, schema: $this->schema(key: 'case_schema')),
				uuid: $caseId
			)
		);
	}//end saveCase()
}//end class
