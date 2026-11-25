<?php

/**
 * Dossiq legacy onboarding step rows.
 *
 * Before remove-casetask 7.1 an onboarding step was a `tenantOnboardingTask`
 * object. This opens an engine step for each known step such a row holds.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Onboarding
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
 * @spec openspec/specs/tenant-onboarding/spec.md#requirement-onboarding-checklist-and-progress-dashboard-req-003-a-req-003-d
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Onboarding;

use OCA\Dossiq\Command\Backfill\OpenRegisterRowNormaliser;
use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Service\Task\OnboardingSteps;
use OCA\Dossiq\Service\TenantOnboardingService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Carries a tenant's legacy onboarding rows onto the task engine.
 *
 * The rows stay as they were written; the status and the row's uuid,
 * completedBy, completedAt and blockedReason go along in the step.
 *
 * @spec openspec/specs/tenant-onboarding/spec.md#requirement-onboarding-checklist-and-progress-dashboard-req-003-a-req-003-d
 */
final class LegacyStepRows {

	/**
	 * Constructor.
	 *
	 * @param OnboardingSteps           $steps         The onboarding steps, as engine tasks.
	 * @param LoggerInterface           $logger        Logger.
	 * @param OpenRegisterRowNormaliser $rowNormaliser Reads a findAll() row, entity or array, as an array.
	 */
	public function __construct(
		private readonly OnboardingSteps $steps,
		private readonly LoggerInterface $logger,
		private readonly OpenRegisterRowNormaliser $rowNormaliser,
	) {
	}//end __construct()

	/**
	 * Open an engine step for every known step a legacy onboarding row holds.
	 *
	 * @param object|null $objectService OpenRegister's object service, or null without OpenRegister.
	 * @param string      $tenantId      The tenant (Organisation) uuid.
	 * @param string      $actor         Who carries the rows over.
	 *
	 * @return int How many steps the engine accepted.
	 *
	 * @spec openspec/specs/tenant-onboarding/spec.md#requirement-onboarding-checklist-and-progress-dashboard-req-003-a-req-003-d
	 */
	public function carry(?object $objectService, string $tenantId, string $actor): int {
		$carried = 0;
		foreach ($this->read(objectService: $objectService, tenantId: $tenantId) as $row) {
			$step = (string) ($row['data']['step'] ?? '');
			$status = (string) ($row['data']['status'] ?? 'pending');
			if (in_array($step, TenantOnboardingService::STEPS, true) === false) {
				continue;
			}

			$metadata = $this->metadata(row: $row);
			if ($this->steps->open(tenantId: $tenantId, step: $step, title: $step, status: $status, actor: $actor, metadata: $metadata) !== '') {
				$carried++;
			}
		}

		return $carried;
	}//end carry()

	/**
	 * The metadata a carried step keeps from its legacy row.
	 *
	 * @param array $row The legacy onboarding row.
	 *
	 * @return array<string, string> The row's uuid and its non-empty completion and block fields.
	 */
	private function metadata(array $row): array {
		$metadata = ['onboardingRow' => $row['uuid']];
		foreach (['completedBy', 'completedAt', 'blockedReason'] as $field) {
			if ((string) ($row['data'][$field] ?? '') !== '') {
				$metadata[$field] = (string) $row['data'][$field];
			}
		}

		return $metadata;
	}//end metadata()

	/**
	 * The tenant's legacy `tenantOnboardingTask` rows, each as uuid and data.
	 *
	 * @param object|null $objectService OpenRegister's object service, or null without OpenRegister.
	 * @param string      $tenantId      The Organisation's uuid.
	 *
	 * @return array<int, array{uuid: string, data: array<string, mixed>}> The rows.
	 */
	private function read(?object $objectService, string $tenantId): array {
		if ($objectService === null) {
			return [];
		}

		try {
			$rows = $objectService->findAll(
				[
					'filters' => [
						'register' => AppIdentity::REGISTER_SLUG,
						'schema' => 'tenantOnboardingTask',
						'tenantRef' => $tenantId,
					],
					'limit' => 100,
					'offset' => 0,
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning('Dossiq: could not read the legacy onboarding rows', ['tenantId' => $tenantId, 'exception' => $e->getMessage()]);
			return [];
		}

		return $this->rowsOfTenant(rows: $rows, tenantId: $tenantId);
	}//end read()

	/**
	 * The normalised rows that carry data and belong to the tenant.
	 *
	 * @param mixed  $rows     The rows as read.
	 * @param string $tenantId The Organisation's uuid.
	 *
	 * @return array<int, array{uuid: string, data: array<string, mixed>}> The rows.
	 */
	private function rowsOfTenant(mixed $rows, string $tenantId): array {
		$out = [];
		foreach ((array) $rows as $row) {
			$normalised = $this->rowNormaliser->normalise(row: $row);
			if ($normalised['data'] !== [] && (string) ($normalised['data']['tenantRef'] ?? '') === $tenantId) {
				$out[] = $normalised;
			}
		}

		return $out;
	}//end rowsOfTenant()
}//end class
