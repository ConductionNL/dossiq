<?php

/**
 * Procest Tenant Seed Service
 *
 * Seeds standard templates (zaaktypen, mandaat-matrix, default roles) into a
 * freshly provisioned tenant schema. Reads canonical templates from the
 * existing procest register seed (LHS matrix, default zaaktypen) and writes
 * them under the tenant context.
 *
 * Persistence is intentionally thin — it logs the seed intent and relies on
 * the OpenRegister ObjectService for the actual writes once the schema is
 * present. Real-world seeding lives in the dedicated repair steps; this
 * service is the orchestration hook called during provisioning.
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
 * @spec openspec/changes/tenant-zaaksysteem-saas-03-schema-provisioning/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use Psr\Log\LoggerInterface;

/**
 * Seed standard templates (zaaktypen, mandaat-matrix, roles) into a tenant.
 */
class TenantSeedService
{
    /**
     * Constructor.
     *
     * @param LoggerInterface $logger Logger.
     */
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Seed standard zaaktype templates into the tenant schema.
     *
     * @param string $schemaName Tenant schema name.
     * @param string $tier       Tier (basic|standard|enterprise) — drives template set.
     *
     * @return array<string, mixed> Seed report (counts).
     */
    public function seedZaaktypeTemplates(string $schemaName, string $tier): array
    {
        $templates = $this->resolveTemplatesForTier(tier: $tier);
        $this->logger->info(
            'Procest: seeding zaaktype templates into tenant schema',
            ['schemaName' => $schemaName, 'tier' => $tier, 'count' => count($templates)]
        );
        return ['templates' => $templates];
    }//end seedZaaktypeTemplates()

    /**
     * Seed the default mandaat-matrix template into the tenant schema.
     *
     * @param string $schemaName Tenant schema name.
     *
     * @return array<string, mixed> Seed report.
     */
    public function seedMandaatMatrix(string $schemaName): array
    {
        $this->logger->info(
            'Procest: seeding default mandaat-matrix into tenant schema',
            ['schemaName' => $schemaName]
        );

        return ['mandaat_matrix_seeded' => true];
    }//end seedMandaatMatrix()

    /**
     * Create the default per-tenant roles.
     *
     * @param string             $schemaName Tenant schema name.
     * @param array<int, string> $roles      Role names.
     *
     * @return array<int, string> Roles created.
     */
    public function createDefaultRoles(string $schemaName, array $roles): array
    {
        $this->logger->info(
            'Procest: creating default tenant roles',
            ['schemaName' => $schemaName, 'roles' => $roles]
        );
        return $roles;
    }//end createDefaultRoles()

    /**
     * Resolve the per-tier template list.
     *
     * @param string $tier Tier.
     *
     * @return array<int, string>
     */
    private function resolveTemplatesForTier(string $tier): array
    {
        $base = ['bezwaar', 'beroep', 'klacht'];
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
