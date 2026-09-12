<?php

/**
 * Satellite Orphan Scanner
 *
 * Reports satellite rows whose `tenantRef` resolves to no Organisation.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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
 * @spec openspec/changes/tenancy-onto-openregister-organisation/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Finds satellite rows pointing at a tenant that does not exist.
 *
 * Lives beside `TenantMigrationService` rather than inside it. Scanning is a
 * read-only audit of the five satellites and migrating is a write against the
 * tenant store; folding the two together put that class over phpmd's
 * complexity ceiling, which was a fair description of the problem rather than
 * only a number.
 *
 * @spec openspec/changes/tenancy-onto-openregister-organisation/tasks.md
 */
class SatelliteOrphanScanner {

	use SearchesObjects;

	/**
	 * Register slug holding this app's schemas.
	 */
	private const REGISTER_SLUG = 'dossiq';

	/**
	 * The five satellites that reference a tenant and stay in dossiq.
	 *
	 * `tenantOnboardingTask` is not here on purpose: it is re-filed onto the
	 * engine `Task` as follow-up 7.1 of remove-casetask, and it still points at
	 * the `tenant` schema.
	 *
	 * @var array<int, string>
	 */
	private const SATELLITE_SCHEMAS = [
		'tenantConfiguration',
		'tenantQuota',
		'tenantUser',
		'tenantMandate',
		'tenantBillingEvent',
	];

	/**
	 * The `tenantRef` values that are shipped seed data, not a broken reference.
	 *
	 * These are the three tier quota templates in `lib/Settings/dossiq_register.json`,
	 * one sentinel per tier, four `tenantQuota` rows each. They belong to no
	 * tenant by design and they are on EVERY install, so an orphan report that
	 * did not know them would open with twelve false alarms and teach an
	 * operator to skim the list. tasks.md 2b read the same twelve rows off the
	 * dev instance and called them test fixtures written on 2026-08-30; they
	 * are not, they are the register seed.
	 *
	 * @var array<int, string>
	 */
	private const TIER_TEMPLATE_REFS = [
		'00000000-0000-0000-0000-000000000000',
		'00000000-0000-0000-0000-000000000001',
		'00000000-0000-0000-0000-000000000002',
	];

	/**
	 * How many satellite rows to read per schema when scanning for orphans.
	 */
	private const ORPHAN_SCAN_LIMIT = 5000;

	/**
	 * Constructor.
	 *
	 * @param SettingsService    $settingsService Dossiq settings bridge, provides the ObjectService.
	 * @param ContainerInterface $container       DI container, resolves OpenRegister's OrganisationMapper.
	 * @param IAppManager        $appManager      Detects whether OpenRegister is installed.
	 * @param LoggerInterface    $logger          PSR-3 logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly ContainerInterface $container,
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Report every satellite row whose `tenantRef` resolves to nothing.
	 *
	 * 🔴 REPORTED, NEVER MAPPED. An orphan is a row pointing at a tenant that
	 * does not exist, and there is no safe guess about which organisation it
	 * meant. Attaching it to the nearest candidate would give one tenant
	 * another tenant's mandates or quotas, and every scoping filter downstream
	 * would then agree, because the row really would say so. So this method
	 * reads and counts. It writes nothing, and it has no repair mode.
	 *
	 * Safe to run before the migration, after it, or instead of it.
	 *
	 * @return array{scanned:int, orphans:int, templates:int,
	 *               bySchema:array<string, array{scanned:int, orphans:int, templates:int}>,
	 *               rows:array<int, array{schema:string, row:string, tenantRef:string}>}
	 *               What was scanned and what does not resolve.
	 *
	 * @spec openspec/changes/tenancy-onto-openregister-organisation/tasks.md
	 */
	public function reportOrphans(): array {
		$report = [
			'scanned' => 0,
			'orphans' => 0,
			'templates' => 0,
			'bySchema' => [],
			'rows' => [],
		];

		$objectService = $this->settingsService->getObjectService();
		$mapper = $this->getOrganisationMapper();
		if ($objectService === null || $mapper === null) {
			$this->logger->warning('Dossiq: orphan scan skipped, OpenRegister organisation services unavailable');
			return $report;
		}

		// Resolution is cached per tenantRef: a register with thousands of
		// satellite rows holds only a handful of distinct tenants, and the
		// uncached form is one mapper read per row.
		$resolves = [];

		foreach (self::SATELLITE_SCHEMAS as $schema) {
			$perSchema = ['scanned' => 0, 'orphans' => 0, 'templates' => 0];

			foreach ($this->readSatelliteRows(objectService: $objectService, schema: $schema) as $row) {
				$perSchema['scanned']++;
				$tenantRef = trim((string)($row['tenantRef'] ?? ''));

				if (in_array($tenantRef, self::TIER_TEMPLATE_REFS, true) === true) {
					$perSchema['templates']++;
					continue;
				}

				if (array_key_exists($tenantRef, $resolves) === false) {
					$resolves[$tenantRef] = ($tenantRef !== ''
						&& $this->findOrganisationByUuid(mapper: $mapper, uuid: $tenantRef) !== null);
				}

				if ($resolves[$tenantRef] === true) {
					continue;
				}

				$perSchema['orphans']++;
				$report['rows'][] = [
					'schema' => $schema,
					'row' => (string)($row['id'] ?? ($row['uuid'] ?? '')),
					'tenantRef' => $tenantRef,
				];
			}

			$report['bySchema'][$schema] = $perSchema;
			$report['scanned'] += $perSchema['scanned'];
			$report['orphans'] += $perSchema['orphans'];
			$report['templates'] += $perSchema['templates'];
		}

		$this->logger->info(
			'Dossiq: satellite orphan scan complete',
			[
				'scanned' => $report['scanned'],
				'orphans' => $report['orphans'],
				'templates' => $report['templates'],
			],
		);

		return $report;
	}//end reportOrphans()

	/**
	 * Read one satellite schema's rows, or none when the schema is absent.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param string $schema        The satellite schema slug.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function readSatelliteRows(object $objectService, string $schema): array {
		try {
			return $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: self::REGISTER_SLUG,
				schema: $schema,
				filters: ['_limit' => self::ORPHAN_SCAN_LIMIT],
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: orphan scan could not read a satellite schema',
				['schema' => $schema, 'exception' => $e->getMessage()],
			);
			return [];
		}
	}//end readSatelliteRows()

	/**
	 * Find an Organisation by uuid, returning null when absent.
	 *
	 * @param object $mapper OR OrganisationMapper.
	 * @param string $uuid   Uuid to look up.
	 *
	 * @return object|null The Organisation, or null when none matches.
	 */
	private function findOrganisationByUuid(object $mapper, string $uuid): ?object {
		try {
			return $mapper->findByUuid($uuid);
		} catch (Throwable $e) {
			// DoesNotExistException, and any other lookup failure, reads as absent.
			return null;
		}
	}//end findOrganisationByUuid()

	/**
	 * Resolve OpenRegister's OrganisationMapper from the DI container.
	 *
	 * @return object|null The mapper, or null when OpenRegister is unavailable.
	 */
	private function getOrganisationMapper(): ?object {
		if (in_array('openregister', (array)$this->appManager->getInstalledApps(), true) === false) {
			return null;
		}

		try {
			return $this->container->get('OCA\\OpenRegister\\Db\\OrganisationMapper');
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: could not resolve OrganisationMapper for the satellite orphan scan',
				['exception' => $e->getMessage()],
			);
			return null;
		}
	}//end getOrganisationMapper()
}//end class
