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

use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Kernel\Contracts\TenantMigrationServiceInterface;
use OCA\Dossiq\Kernel\Contracts\TenantServiceInterface;
use OCA\Dossiq\Repair\Support\RunsUnderSystemIdentity;
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
	 * @param TenantMigrationServiceInterface $migration       The migration, shared with the occ command.
	 * @param SettingsServiceInterface        $settingsService Provides the ObjectService to run as system.
	 * @param LoggerInterface        $logger          Logger.
	 * @param TenantServiceInterface          $tenants         Creates the audit anchor of a member organisation that has none.
	 */
	public function __construct(
		private readonly TenantMigrationServiceInterface $migration,
		private readonly SettingsServiceInterface $settingsService,
		private readonly LoggerInterface $logger,
		private readonly TenantServiceInterface $tenants,
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
			$this->withSystemIdentity(objectService: $this->settingsService->getObjectService(), work: function () use (&$summary, &$anchors): void {
				$summary = $this->migration->migrate();
				$anchors = $this->anchorMemberOrganisations();
			});
		} catch (Throwable $e) {
			$this->logger->error('Dossiq: tenant migration repair step failed', ['exception' => $e->getMessage()]);
			$output->warning('Dossiq: the tenant migration could not run: '.$e->getMessage());
			return;
		}

		$this->report(output: $output, summary: $summary, anchors: $anchors);
	}//end run()

	/**
	 * Report what the migration did, and warn when tenants are left unmigrated.
	 *
	 * @param IOutput                          $output  Repair output.
	 * @param array<string, mixed>|null        $summary The migration's summary.
	 * @param array{members: int, anchored: int} $anchors The member organisations, and how many have an anchor.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tenancy-onto-openregister-organisation/specs/tenant-organisation-boundary/spec.md
	 */
	private function report(IOutput $output, ?array $summary, array $anchors): void {
		$unmigrated = (int)($summary['unmigrated'] ?? 0);
		$this->reportMigrated(output: $output, summary: $summary, unmigrated: $unmigrated);
		$this->reportAnchors(output: $output, anchors: $anchors);
		$this->warnUnmigrated(output: $output, summary: $summary, unmigrated: $unmigrated);
	}//end report()

	/**
	 * Report how many tenants the migration moved, skipped, repaired, refused and failed.
	 *
	 * @param IOutput                   $output     Repair output.
	 * @param array<string, mixed>|null $summary    The migration's summary.
	 * @param int                       $unmigrated The tenants left unmigrated.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tenancy-onto-openregister-organisation/specs/tenant-organisation-boundary/spec.md
	 */
	private function reportMigrated(IOutput $output, ?array $summary, int $unmigrated): void {
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
	}//end reportMigrated()

	/**
	 * Report how many member organisations have their tenant audit anchor.
	 *
	 * @param IOutput                            $output  Repair output.
	 * @param array{members: int, anchored: int} $anchors The member organisations, and how many have an anchor.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tenancy-onto-openregister-organisation/specs/tenant-organisation-boundary/spec.md
	 */
	private function reportAnchors(IOutput $output, array $anchors): void {
		$output->info(
			sprintf(
				'Dossiq: %d of %d organisations with members have their tenant audit anchor.',
				$anchors['anchored'],
				$anchors['members']
			)
		);
	}//end reportAnchors()

	/**
	 * Log and warn when stored tenants are left with no Organisation of the same uuid.
	 *
	 * @param IOutput                   $output     Repair output.
	 * @param array<string, mixed>|null $summary    The migration's summary.
	 * @param int                       $unmigrated The tenants left unmigrated.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tenancy-onto-openregister-organisation/specs/tenant-organisation-boundary/spec.md
	 */
	private function warnUnmigrated(IOutput $output, ?array $summary, int $unmigrated): void {
		if ($unmigrated > 0) {
			$this->logger->warning(
				'Dossiq: stored tenants are left with no Organisation of the same uuid',
				['unmigrated' => $unmigrated, 'refused' => (int)($summary['refused'] ?? 0), 'failed' => (int)($summary['failed'] ?? 0)]
			);
			$output->warning('Dossiq: '.$unmigrated.' tenants are not migrated yet; run occ dossiq:migrate-tenants --dry-run to see which.');
		}
	}//end warnUnmigrated()

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
			$rows = $this->tenantUserPage(objectService: $objectService, offset: $offset);
			$refs = self::withTenantRefs(refs: $refs, rows: $rows);

			$offset += self::PAGE;
			$full = (count($rows) === self::PAGE);
		} while ($full === true);

		return array_keys($refs);
	}//end memberOrganisations()

	/**
	 * One page of tenantUser rows.
	 *
	 * @param object $objectService OpenRegister's object service.
	 * @param int    $offset        The page's offset.
	 *
	 * @return array<int, mixed> The rows.
	 */
	private function tenantUserPage(object $objectService, int $offset): array {
		return (array) $objectService->findAll(
			[
				'filters' => ['register' => AppIdentity::REGISTER_SLUG, 'schema' => 'tenantUser'],
				'limit' => self::PAGE,
				'offset' => $offset,
			]
		);
	}//end tenantUserPage()

	/**
	 * The organisation references seen so far, with those of a page of rows added.
	 *
	 * @param array<string, bool> $refs The references seen so far.
	 * @param array<int, mixed>   $rows The tenantUser rows.
	 *
	 * @return array<string, bool> The references.
	 */
	private static function withTenantRefs(array $refs, array $rows): array {
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

		return $refs;
	}//end withTenantRefs()
}//end class
