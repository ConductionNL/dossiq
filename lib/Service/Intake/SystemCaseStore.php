<?php

/**
 * Dossiq system case store.
 *
 * Reads a case type and a case by id, and writes a case, as the system and
 * without RBAC or multitenancy, on the schemas dossiq configures. An intake
 * that arrives without a Nextcloud user (a portal forward, a form another app
 * took in) has nobody to act as, so it writes as the system; the intake itself
 * decides beforehand whether the request may become a case.
 *
 * Every failure is logged and answered as an absent value, so the caller turns
 * it into its own refusal: no case type, no read-back, no written case.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Intake
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
 * @spec openspec/specs/woo-request-intake/spec.md#requirement-one-service-creates-every-woo-request-req-wri-002
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Intake;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\Support\UnscopedObjectReader;
use OCA\Dossiq\Woo\WooWrittenCase;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads and writes cases as the system, on dossiq's configured case schemas.
 *
 * @spec openspec/specs/woo-request-intake/spec.md#requirement-one-service-creates-every-woo-request-req-wri-002
 */
class SystemCaseStore {

	use SearchesObjects;

	/**
	 * Reads one object by id as the system, unscoped.
	 *
	 * @var UnscopedObjectReader
	 */
	private readonly UnscopedObjectReader $reader;

	/**
	 * Constructor.
	 *
	 * @param SettingsService           $settingsService The configured case and case type schemas.
	 * @param LoggerInterface           $logger          Logger.
	 * @param UnscopedObjectReader|null $reader          Reads unscoped; a fresh one when null.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
		?UnscopedObjectReader $reader = null,
	) {
		$this->reader = ($reader ?? new UnscopedObjectReader());
	}//end __construct()

	/**
	 * A case type by id, or null when it is absent or cannot be read.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $register      The dossiq register.
	 * @param string $caseTypeId    The case type uuid.
	 *
	 * @return array<string, mixed>|null The case type.
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-one-service-creates-every-woo-request-req-wri-002
	 */
	public function caseType(object $objectService, string $register, string $caseTypeId): ?array {
		$caseType = null;
		try {
			$caseType = $this->reader->find(
				objectService: $objectService,
				register: $register,
				schema: $this->settingsService->getConfigValue('case_type_schema'),
				id: $caseTypeId,
			);
		} catch (Throwable $e) {
			$this->logger->error('SystemCaseStore: the case type could not be read', ['caseType' => $caseTypeId, 'error' => $e->getMessage()]);
		}

		return $caseType;
	}//end caseType()

	/**
	 * A case by id, or an empty array when it is absent or cannot be read.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $register      The dossiq register.
	 * @param string $caseId        The case uuid.
	 *
	 * @return array<string, mixed> The case.
	 *
	 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/woo-request-intake/spec.md#requirement-armed-means-a-term-runs-counted-from-when-the-requester-sent-it-req-wto-002
	 */
	public function readCase(object $objectService, string $register, string $caseId): array {
		$case = [];
		try {
			$case = (array)$this->reader->find(
				objectService: $objectService,
				register: $register,
				schema: $this->settingsService->getConfigValue('case_schema'),
				id: $caseId,
			);
		} catch (Throwable $e) {
			$this->logger->warning('SystemCaseStore: the case could not be read back', ['case' => $caseId, 'error' => $e->getMessage()]);
		}

		return $case;
	}//end readCase()

	/**
	 * Write a case, and read its uuid, number and deadline from what was written.
	 *
	 * @param object               $objectService The OpenRegister ObjectService.
	 * @param string               $register      The dossiq register.
	 * @param array<string, mixed> $case          The case to write.
	 *
	 * @return array{id: string, answer: array<string, string>}|null The written case, or null when the write failed.
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-one-service-creates-every-woo-request-req-wri-002
	 */
	public function write(object $objectService, string $register, array $case): ?array {
		$saved  = null;
		$failed = false;
		try {
			$saved = $this->runAsSystemIfAvailable(
				objectService: $objectService,
				operation: fn (): mixed => $objectService->saveObject(
					object: $case,
					register: $register,
					schema: $this->settingsService->getConfigValue('case_schema'),
					_rbac: false,
					_multitenancy: false,
				)
			);
		} catch (Throwable $e) {
			$this->logger->error('SystemCaseStore: the case could not be written', ['error' => $e->getMessage()]);
			$failed = true;
		}

		if ($failed === true) {
			return null;
		}

		return (new WooWrittenCase())->read(saved: $saved);
	}//end write()
}//end class
