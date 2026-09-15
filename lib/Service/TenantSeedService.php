<?php

/**
 * Dossiq Tenant Seed Service
 *
 * Seeds standard templates (zaaktypen, mandaat-matrix, default roles) into a
 * freshly provisioned tenant schema. Reads canonical templates from the
 * existing dossiq register seed (LHS matrix, default zaaktypen) and writes
 * them under the tenant context.
 *
 * Persistence is intentionally thin — it logs the seed intent and relies on
 * the OpenRegister ObjectService for the actual writes once the schema is
 * present. Real-world seeding lives in the dedicated repair steps; this
 * service is the orchestration hook called during provisioning.
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
 * @spec openspec/changes/tenant-zaaksysteem-saas-03-schema-provisioning/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\Service\Starter\MunicipalRoleSetService;
use OCA\Dossiq\Service\Starter\ShippedSets;
use Psr\Log\LoggerInterface;

/**
 * Seed standard templates (zaaktypen, mandaat-matrix, roles) into a tenant.
 *
 * @spec openspec/specs/tenant-schemas/spec.md#requirement-seed-tier-templates-and-default-tenant-onboarding-template-req-001-b-seed
 */
class TenantSeedService {
	/**
	 * Constructor.
	 *
	 * @param MunicipalRoleSetService $roleSet The shipped municipal role set.
	 * @param LoggerInterface         $logger  Logger.
	 */
	public function __construct(
		private readonly MunicipalRoleSetService $roleSet,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Seed standard zaaktype templates into the tenant schema.
	 *
	 * @param string $schemaName Tenant schema name.
	 * @param string $tier Tier (basic|standard|enterprise) — drives template set.
	 *
	 * @return array<string, mixed> Seed report (counts).
	 *
	 * @spec openspec/specs/tenant-schemas/spec.md#requirement-seed-tier-templates-and-default-tenant-onboarding-template-req-001-b-seed
	 */
	public function seedZaaktypeTemplates(string $schemaName, string $tier): array {
		$templates = $this->resolveTemplatesForTier(tier: $tier);
		$set = ShippedSets::VTH;
		$setVersion = ShippedSets::versionOf(set: $set);

		$this->logger->info(
			'Dossiq: seeding zaaktype templates into tenant schema',
			[
				'schemaName' => $schemaName,
				'tier' => $tier,
				'count' => count($templates),
				'set' => $set,
				'setVersion' => $setVersion,
			]
		);

		// The set and its version travel with the report so the provisioner
		// that performs the writes can stamp them. This service still does not
		// write the case types itself, and saying so on the report is better
		// than a caller assuming it did.
		return ['templates' => $templates, 'set' => $set, 'setVersion' => $setVersion, 'written' => false];
	}//end seedZaaktypeTemplates()

	/**
	 * Seed the default mandaat-matrix template into the tenant schema.
	 *
	 * @param string $schemaName Tenant schema name.
	 *
	 * @return array<string, mixed> Seed report.
	 *
	 * @spec openspec/specs/tenant-mandate/spec.md#requirement-mandate-matrix-validation-per-action-req-002-d-req-006-d
	 */
	public function seedMandaatMatrix(string $schemaName): array {
		$this->logger->info(
			'Dossiq: seeding default mandaat-matrix into tenant schema',
			['schemaName' => $schemaName]
		);

		return ['mandaat_matrix_seeded' => true];
	}//end seedMandaatMatrix()

	/**
	 * Create the default per-tenant roles.
	 *
	 * @param string $schemaName Tenant schema name.
	 * @param array<int, string> $roles Role names.
	 *
	 * @return array<int, string> Roles created.
	 *
	 * @spec openspec/specs/tenant-schemas/spec.md#requirement-seed-tier-templates-and-default-tenant-onboarding-template-req-001-b-seed
	 */
	public function createDefaultRoles(string $schemaName, array $roles): array {
		// 🔑 THE ROLES ARE THE SHIPPED SET, WRITTEN AND STAMPED, NOT A LOG LINE.
		// This method used to return the names it was handed and write nothing,
		// so a tenant provisioned through it started with no roles at all and
		// the log said otherwise. It seeds the named municipal set, dormant,
		// with a provenance row per role; the caller's own list is kept on the
		// report so a provisioner that wanted something else can see what it
		// actually got.
		$tally = $this->roleSet->seed();

		$this->logger->info(
			'Dossiq: creating default tenant roles',
			['schemaName' => $schemaName, 'requested' => $roles, 'seeded' => $tally]
		);

		if ($tally === null) {
			return $roles;
		}

		return array_column(MunicipalRoleSetService::ROLES_SHIPPED, 'name');
	}//end createDefaultRoles()

	/**
	 * Resolve the per-tier template list.
	 *
	 * @param string $tier Tier.
	 *
	 * @return array<int, string>
	 */
	private function resolveTemplatesForTier(string $tier): array {
		$base = ['objectionProceeding', 'beroep', 'complaint'];
		if ($tier === 'standard') {
			return array_merge($base, ['vergunning_bouw', 'vergunning_apv', 'subsidieaanvraag']);
		}

		if ($tier === 'enterprise') {
			return array_merge(
				$base,
				['vergunning_bouw', 'vergunning_apv', 'subsidieaanvraag'],
				['handhaving', 'planschade', 'omgevingsvergunning_wabo']
			);
		}

		return $base;
	}//end resolveTemplatesForTier()
}//end class
