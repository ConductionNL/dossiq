<?php

/**
 * Dossiq repair step: bring stored tenant quota rows in line with decision 272.
 *
 * Two kinds of stored row change meaning. A `storage_gb` row becomes a
 * `storage_mb` row, its usage multiplied by 1024, because storage is now
 * counted in megabytes. An `active_users` row that still carries its own
 * `limit` hands that limit to the Organisation's `userQuota` when the
 * Organisation has none yet, because the user limit now lives there only and
 * a row's own copy is no longer read. A row whose Organisation cannot be
 * written keeps its limit and is counted as failed, so the next upgrade tries
 * again. The step records a complete run and then never runs again.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
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
 * @spec openspec/changes/tenant-quotas-enforced-and-billing-shown/specs/tenant-quotas/spec.md#requirement-the-quota-card-and-the-refusal-read-the-same-limits-req-tq-enf-02
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\OrganisationQuotaLimits;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\TenantQuotaService;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Rename storage rows to megabytes and move user limits onto the Organisation.
 *
 * @spec openspec/changes/tenant-quotas-enforced-and-billing-shown/specs/tenant-quotas/spec.md#requirement-the-quota-card-and-the-refusal-read-the-same-limits-req-tq-enf-02
 */
class MoveTenantQuotaLimitsOntoOrganisation implements IRepairStep {

	use SearchesObjects;

	/**
	 * The app config key that records a complete run.
	 *
	 * @var string
	 */
	public const CONFIG_KEY = 'tenant_quota_decision_272';

	/**
	 * The value of the key once the step has run completely.
	 *
	 * @var string
	 */
	public const DONE = '1';

	/**
	 * Megabytes in a gigabyte.
	 *
	 * @var int
	 */
	private const MB_PER_GB = 1024;

	/**
	 * Constructor.
	 *
	 * @param SettingsService         $settings           Hands out the object service.
	 * @param IAppConfig              $appConfig          Holds the completion key.
	 * @param OrganisationQuotaLimits $organisationLimits Reads and writes the Organisation's user limit.
	 * @param TenantQuotaService      $quotas             Reads a stored row as an array.
	 * @param LoggerInterface         $logger             Records a row that refused.
	 */
	public function __construct(
		private readonly SettingsService $settings,
		private readonly IAppConfig $appConfig,
		private readonly OrganisationQuotaLimits $organisationLimits,
		private readonly TenantQuotaService $quotas,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The step's name in the upgrade output.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/tenant-quotas-enforced-and-billing-shown/specs/tenant-quotas/spec.md#requirement-the-quota-card-and-the-refusal-read-the-same-limits-req-tq-enf-02
	 */
	public function getName(): string {
		return 'Count tenant storage in megabytes and move user limits onto the organisation';
	}//end getName()

	/**
	 * Run the step once and say what it did.
	 *
	 * @param IOutput $output The upgrade output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tenant-quotas-enforced-and-billing-shown/specs/tenant-quotas/spec.md#requirement-the-quota-card-and-the-refusal-read-the-same-limits-req-tq-enf-02
	 */
	public function run(IOutput $output): void {
		if ($this->appConfig->getValueString(Application::APP_ID, self::CONFIG_KEY, '') === self::DONE) {
			return;
		}

		$objectService = $this->settings->getObjectService();
		if ($objectService === null) {
			$output->info('Tenant quotas: OpenRegister is not available, skipped.');
			return;
		}

		try {
			$tally = (array)$this->runAsSystemIfAvailable(
				objectService: $objectService,
				operation: fn (): array => $this->carry(objectService: $objectService)
			);
		} catch (Throwable $e) {
			$this->logger->warning('Dossiq: tenant quota rows could not be read', ['error' => $e->getMessage()]);
			$output->info('Tenant quotas: the rows could not be read, skipped.');
			return;
		}

		$output->info(
			'Tenant quotas: ' . (int)($tally['storage'] ?? 0) . ' storage row(s) now in MB, '
			. (int)($tally['users'] ?? 0) . ' user limit(s) moved onto the organisation, '
			. (int)($tally['failed'] ?? 0) . ' failed.'
		);

		if ((int)($tally['failed'] ?? 0) === 0) {
			$this->appConfig->setValueString(Application::APP_ID, self::CONFIG_KEY, self::DONE);
		}
	}//end run()

	/**
	 * The row as it should be stored now, or null when it needs no change.
	 *
	 * @param array<string, mixed> $row A stored tenantQuota row.
	 *
	 * @return array<string, mixed>|null The rewritten row.
	 *
	 * @spec openspec/changes/tenant-quotas-enforced-and-billing-shown/specs/tenant-quotas/spec.md#requirement-the-quota-card-and-the-refusal-read-the-same-limits-req-tq-enf-02
	 */
	public function rewritten(array $row): ?array {
		$type = (string)($row['quotaType'] ?? '');
		if ($type === 'storage_gb') {
			$row['quotaType'] = 'storage_mb';
			$row['currentUsage'] = ((int)($row['currentUsage'] ?? 0) * self::MB_PER_GB);
			unset($row['limit']);
			return $row;
		}

		if ($type === 'active_users' && isset($row['limit']) === true) {
			unset($row['limit']);
			return $row;
		}

		return null;
	}//end rewritten()

	/**
	 * Rewrite every row that needs it.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 *
	 * @return array{storage:int, users:int, failed:int} What was done.
	 */
	private function carry(object $objectService): array {
		$tally = ['storage' => 0, 'users' => 0, 'failed' => 0];
		foreach (['storage_gb', 'active_users'] as $type) {
			$rows = $objectService->findAll(
				[
					'filters' => [
						'register' => Application::REGISTER_SLUG,
						'schema' => 'tenantQuota',
						'quotaType' => $type,
					],
					'limit' => 1000,
					'offset' => 0,
				]
			);
			foreach ((array)$rows as $entity) {
				$row = $this->quotas->quotaRowAsArray(row: $entity);
				$new = null;
				if ($row !== null) {
					$new = $this->rewritten(row: $row);
				}

				if ($row === null || $new === null) {
					continue;
				}

				$key = 'storage';
				if ($type === 'active_users') {
					$key = 'users';
				}

				if ($this->save(objectService: $objectService, old: $row, new: $new) === false) {
					$key = 'failed';
				}

				$tally[$key]++;
			}//end foreach
		}//end foreach

		return $tally;
	}//end carry()

	/**
	 * Hand a user limit to the Organisation when it has none, then store the row.
	 *
	 * @param object               $objectService OpenRegister's ObjectService.
	 * @param array<string, mixed> $old           The row as stored.
	 * @param array<string, mixed> $new           The row as it should be stored.
	 *
	 * @return bool Whether the row was stored.
	 */
	private function save(object $objectService, array $old, array $new): bool {
		$tenantId = (string)($old['tenantRef'] ?? '');
		if (($old['quotaType'] ?? '') === 'active_users'
			&& $this->organisationLimits->read(tenantId: $tenantId, quotaType: 'active_users') === null
			&& $this->organisationLimits->write(tenantId: $tenantId, quotaType: 'active_users', limit: (int)$old['limit']) === false
		) {
			return false;
		}

		$uuid = (string)($old['uuid'] ?? $old['id'] ?? '');
		if ($uuid === '') {
			$uuid = null;
		}

		try {
			$objectService->saveObject(
				object: $new,
				register: Application::REGISTER_SLUG,
				schema: 'tenantQuota',
				uuid: $uuid,
			);
			return true;
		} catch (Throwable $e) {
			$this->logger->warning('Dossiq: a tenant quota row was not rewritten', ['tenantId' => $tenantId, 'error' => $e->getMessage()]);
			return false;
		}
	}//end save()
}//end class
