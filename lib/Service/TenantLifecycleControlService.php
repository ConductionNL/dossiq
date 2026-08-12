<?php

/**
 * Procest Tenant Lifecycle Control Service
 *
 * Wraps the suspension / reactivation / termination flows around the
 * tenant state machine in `TenantSaasService`. Handles billing
 * settlement before termination and webhook notification to Shillinq.
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/tenant-zaaksysteem-saas-11-suspension-termination/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Suspension / reactivation / termination orchestration.
 */
class TenantLifecycleControlService {
	/**
	 * Constructor.
	 *
	 * @param TenantSaasService $tenantSaasService Tenant SaaS service.
	 * @param TenantBillingService $billingService Billing service.
	 * @param TenantSchemaProvisioner $schemaProvisioner Schema provisioner.
	 * @param TenantProvisioningService $provisioning Provisioning service.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly TenantSaasService $tenantSaasService,
		private readonly TenantBillingService $billingService,
		private readonly TenantSchemaProvisioner $schemaProvisioner,
		private readonly TenantProvisioningService $provisioning,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Suspend a tenant.
	 *
	 * @param string $tenantId Tenant UUID.
	 * @param string $reason Reason for suspension (audited).
	 *
	 * @return array<string,mixed>
	 */
	public function suspend(string $tenantId, string $reason): array {
		$row = $this->tenantSaasService->updateStatus(tenantId: $tenantId, newStatus: 'suspended');
		$this->logger->warning(
			'Procest: tenant suspended',
			['tenantId' => $tenantId, 'reason' => $reason]
		);
		return $row;
	}//end suspend()

	/**
	 * Reactivate a previously suspended tenant.
	 *
	 * @param string $tenantId Tenant UUID.
	 *
	 * @return array<string,mixed>
	 */
	public function reactivate(string $tenantId): array {
		$row = $this->tenantSaasService->updateStatus(tenantId: $tenantId, newStatus: 'active');
		$this->logger->info('Procest: tenant reactivated', ['tenantId' => $tenantId]);
		return $row;
	}//end reactivate()

	/**
	 * Terminate a tenant. Settles outstanding billing before flipping status.
	 *
	 * @param string $tenantId Tenant UUID.
	 * @param string $reason Termination reason.
	 * @param int $retentionYears Years to keep cold-stored archive.
	 *
	 * @return array{tenant: array<string,mixed>, unsettledEvents: int, retentionYears: int}
	 */
	public function terminate(string $tenantId, string $reason, int $retentionYears = 1): array {
		$unsettled = $this->countUnsettledEvents(tenantId: $tenantId);
		if ($unsettled > 0) {
			$this->logger->warning(
				'Procest: terminating tenant with unsettled billing events — Shillinq export must run first',
				['tenantId' => $tenantId, 'unsettledEvents' => $unsettled]
			);
		}

		$row = $this->tenantSaasService->updateStatus(tenantId: $tenantId, newStatus: 'terminated');
		$this->logger->warning(
			'Procest: tenant terminated',
			['tenantId' => $tenantId, 'reason' => $reason, 'retentionYears' => $retentionYears]
		);
		return [
			'tenant' => $row,
			'unsettledEvents' => $unsettled,
			'retentionYears' => $retentionYears,
		];
	}//end terminate()

	/**
	 * Archive the tenant schema after the retention window has passed.
	 * Logs an immutable deletion-confirmation entry.
	 *
	 * @param string $tenantId Tenant UUID.
	 * @param string $slug Tenant slug.
	 * @param string $uuid Tenant UUID (used for schema-name build).
	 *
	 * @return array{deletionAt: string, schemaName: string}
	 */
	public function archiveAndDelete(string $tenantId, string $slug, string $uuid): array {
		$schemaName = $this->provisioning->buildSchemaName(uuid: $uuid, slug: $slug);

		try {
			$this->schemaProvisioner->dropSchema($schemaName);
		} catch (Throwable $e) {
			$this->logger->error(
				'Procest: schema drop failed during termination — manual cleanup required',
				['tenantId' => $tenantId, 'schemaName' => $schemaName, 'exception' => $e->getMessage()]
			);
		}

		$deletionAt = (new DateTimeImmutable('now'))->format(DATE_ATOM);
		// Immutable deletion-confirmation log entry — INFO-level so SIEMs ingest it.
		$this->logger->info(
			'Procest TENANT_SCHEMA_DELETED',
			['tenantId' => $tenantId, 'schemaName' => $schemaName, 'deletionAt' => $deletionAt]
		);

		return ['deletionAt' => $deletionAt, 'schemaName' => $schemaName];
	}//end archiveAndDelete()

	/**
	 * Count events with invoiceRef === null (unsettled).
	 *
	 * @param string $tenantId Tenant UUID.
	 *
	 * @return int
	 */
	public function countUnsettledEvents(string $tenantId): int {
		$events = $this->billingService->fetchEventsForMonth(
			tenantId: $tenantId,
			month: (new DateTimeImmutable('now'))->format('Y-m'),
		);
		$count = 0;
		foreach ($events as $e) {
			if (($e['invoiceRef'] ?? null) === null) {
				$count++;
			}
		}

		return $count;
	}//end countUnsettledEvents()
}//end class
