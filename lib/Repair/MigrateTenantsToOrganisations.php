<?php

/**
 * Run the tenant migration on every upgrade, for one release, and report what is left.
 *
 * Decision Q1 (Ruben, 2026-10-08): the migration that `occ dossiq:migrate-tenants`
 * runs also runs as a repair step for one release, and reports `unmigrated`,
 * the number of stored tenants with no Organisation of the same uuid. A cloud
 * session cannot see other installs, so that count is what tells a person the
 * migration class may be deleted in the release after (task 6.4). A refused
 * collision stays refused and is counted, never mapped.
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
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/tenancy-onto-openregister-organisation/specs/tenant-organisation-boundary/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Repair\Support\RunsUnderSystemIdentity;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\TenantMigrationService;
use OCA\Dossiq\Service\TenantService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Migrates the legacy tenants onto Organisations and reports the unmigrated count.
 *
 * @spec openspec/changes/tenancy-onto-openregister-organisation/specs/tenant-organisation-boundary/spec.md
 */
class MigrateTenantsToOrganisations implements IRepairStep {
	use RunsUnderSystemIdentity;

	/**
	 * How many tenantUser rows are read per page when collecting the member organisations.
	 */
	private const PAGE = 500;

	/**
	 * Constructor.
	 *
	 * @param TenantMigrationService $migration       The migration, shared with the occ command.
	 * @param SettingsService        $settingsService Provides the ObjectService to run as system.
	 * @param LoggerInterface        $logger          Logger.
	 * @param TenantService          $tenants         Creates the audit anchor of a member organisation that has none.
	 */
	public function __construct(
		private readonly TenantMigrationService $migration,
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
		private readonly TenantService $tenants,
	) {
	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/tenancy-onto-openregister-organisation/specs/tenant-organisation-boundary/spec.md
	 */
	public function getName(): string {
		return 'Move the legacy dossiq tenants onto OpenRegister Organisations and report what is left';
	}//end getName()

	/**
	 * Migrate, then report the unmigrated count.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tenancy-onto-openregister-organisation/specs/tenant-organisation-boundary/spec.md
	 */
	public function run(IOutput $output): void {
		$summary = null;
		$anchors = ['members' => 0, 'anchored' => 0];
		try {
			$this->withSystemIdentity(
				objectService: $this->settingsService->getObjectService(),
				work: function () use (&$summary, &$anchors): void {
					$summary = $this->migration->migrate();
					$anchors = $this->anchorMemberOrganisations();
				}
			);
		} catch (Throwable $e) {
			$this->logger->error('Dossiq: tenant migration repair step failed', ['exception' => $e->getMessage()]);
			$output->warning('Dossiq: the tenant migration could not run: '.$e->getMessage());
			return;
		}

		$unmigrated = (int)($summary['unmigrated'] ?? 0);
		$output->info(
			sprintf(
				'Dossiq: tenants migrated %d, already present %d, repaired %d, refused %d, failed %d, of %d. unmigrated = %d.',
				(int)($summary['migrated'] ?? 0),
				(int)($summary['skipped'] ?? 0),
				(int)($summary['repaired'] ?? 0),
				(int)($summary['refused'] ?? 0),
				(int)($summary['failed'] ?? 0),
				(int)($summary['total'] ?? 0),
				$unmigrated
			)
		);

		$output->info(
			sprintf(
				'Dossiq: %d of %d organisations with members have their tenant audit anchor.',
				$anchors['anchored'],
				$anchors['members']
			)
		);

		if ($unmigrated > 0) {
			$this->logger->warning(
				'Dossiq: stored tenants are left with no Organisation of the same uuid',
				['unmigrated' => $unmigrated, 'refused' => (int)($summary['refused'] ?? 0), 'failed' => (int)($summary['failed'] ?? 0)]
			);
			$output->warning('Dossiq: '.$unmigrated.' tenants are not migrated yet; run occ dossiq:migrate-tenants --dry-run to see which.');
		}
	}//end run()

	/**
	 * Give every Organisation a tenantUser row points at its audit anchor (decision Q6).
	 *
	 * An anchor that exists is left alone; one that is missing is created by
	 * `TenantService::ensureAuditAnchor()`. A reference with no Organisation
	 * behind it gets none, and is not counted as anchored.
	 *
	 * @return array{members: int, anchored: int} The member organisations, and how many have an anchor now.
	 *
	 * @spec openspec/changes/tenancy-onto-openregister-organisation/specs/tenant-organisation-boundary/spec.md
	 */
	private function anchorMemberOrganisations(): array {
		$members = $this->memberOrganisations();
		$anchored = 0;
		foreach ($members as $uuid) {
			if ($this->tenants->ensureAuditAnchor(organisationUuid: $uuid) === true) {
				$anchored++;
			}
		}

		return ['members' => count($members), 'anchored' => $anchored];
	}//end anchorMemberOrganisations()

	/**
	 * The distinct organisations the tenantUser rows point at.
	 *
	 * @return array<int, string> The organisation uuids.
	 */
	private function memberOrganisations(): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return [];
		}

		$refs = [];
		$offset = 0;
		do {
			$rows = (array) $objectService->findAll(
				[
					'filters' => ['register' => Application::REGISTER_SLUG, 'schema' => 'tenantUser'],
					'limit' => self::PAGE,
					'offset' => $offset,
				]
			);
			foreach ($rows as $row) {
				$data = $row;
				if (is_object($row) === true && method_exists($row, 'getObject') === true) {
					$data = (array) $row->getObject();
				}

				$ref = trim((string) (((array) $data)['tenantRef'] ?? ''));
				if ($ref !== '') {
					$refs[$ref] = true;
				}
			}

			$offset += self::PAGE;
		} while (count($rows) === self::PAGE);

		return array_keys($refs);
	}//end memberOrganisations()
}//end class
