<?php

/**
 * One case read and one case patched, for a service that names itself in its warnings
 *
 * No OpenRegister, no register or no case schema is `case_not_found`; a
 * lookup that throws is `case_not_found` too, and a case that is not there is
 * `case_not_found` without a warning. A patch that throws is
 * `<act>_write_failed`. Every warning names the service it is written for.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/case-claim-action/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Support;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\SettingsService;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Reads and patches a case in the configured case schema.
 *
 * @spec openspec/changes/case-claim-action/specs/case-management/spec.md
 */
final class CaseReadAndPatch {
	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService OpenRegister and the configured register and case schema.
	 * @param LoggerInterface $logger          Where the warnings go.
	 * @param string          $owner           The service the warnings name.
	 * @param string          $act             What a patch writes, naming its failure code.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
		private readonly string $owner,
		private readonly string $act,
	) {
	}//end __construct()

	/**
	 * The case, or a refusal.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<string, mixed> The case.
	 *
	 * @throws RuntimeException With code `case_not_found`.
	 *
	 * @spec openspec/changes/case-claim-action/specs/case-management/spec.md
	 */
	public function read(string $caseId): array {
		$target = $this->target();
		try {
			$case = $this->findObjectAsArray(
				objectService: $target['objectService'],
				register: $target['register'],
				schema: $target['schema'],
				id: $caseId
			);
		} catch (Throwable $e) {
			$this->warn(warning: 'case lookup failed: ' . $e->getMessage(), context: ['caseId' => $caseId]);
			throw new RuntimeException('case_not_found');
		}

		return ($case ?? throw new RuntimeException('case_not_found'));
	}//end read()

	/**
	 * Write some fields of the case, or a refusal.
	 *
	 * @param string               $caseId  The case UUID.
	 * @param array<string, mixed> $changes The fields to write.
	 *
	 * @return void
	 *
	 * @throws RuntimeException With code `case_not_found` or `<act>_write_failed`.
	 *
	 * @spec openspec/changes/case-claim-action/specs/case-management/spec.md
	 */
	public function patch(string $caseId, array $changes): void {
		$target = $this->target();
		try {
			$this->patchObjectAsArray(
				objectService: $target['objectService'],
				register: $target['register'],
				schema: $target['schema'],
				id: $caseId,
				changes: $changes
			);
		} catch (Throwable $e) {
			$this->warn(warning: $this->act . ' write failed: ' . $e->getMessage(), context: ['caseId' => $caseId]);
			throw new RuntimeException($this->act . '_write_failed');
		}
	}//end patch()

	/**
	 * The OpenRegister seam and the case schema, or a refusal.
	 *
	 * @return array{objectService: object, register: string, schema: string} The three.
	 *
	 * @throws RuntimeException With code `case_not_found` when OpenRegister or the schema is absent.
	 */
	private function target(): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			$this->warn(warning: 'OpenRegister unavailable', context: []);
			throw new RuntimeException('case_not_found');
		}

		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('case_schema');
		if ($register === '' || $schema === '') {
			$this->warn(warning: 'case schema not configured', context: []);
			throw new RuntimeException('case_not_found');
		}

		return ['objectService' => $objectService, 'register' => $register, 'schema' => $schema];
	}//end target()

	/**
	 * Write a warning that names the service.
	 *
	 * @param string               $warning What went wrong.
	 * @param array<string, mixed> $context What it went wrong for.
	 *
	 * @return void
	 */
	private function warn(string $warning, array $context): void {
		$this->logger->warning(
			'Dossiq ' . $this->owner . ': ' . $warning,
			['app' => Application::APP_ID] + $context
		);
	}//end warn()
}//end class
