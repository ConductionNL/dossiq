<?php

/**
 * Tenant SaaS Register Schemas Integration Test
 *
 * Verifies that the seven SaaS tenant schemas declared in
 * `lib/Settings/procest_register.json` materialise with the documented
 * required properties, that the tier quota templates and the default-tenant
 * onboarding template are present as seed data, and that the register
 * lists every tenant schema so OpenRegister exposes them through REST.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/tenant-zaaksysteem-saas-01-schemas-and-seed/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Asserts the seven tenant SaaS schemas materialise as documented and that
 * tier-template + default-tenant onboarding seed rows are present.
 *
 * @covers \OCA\Procest\Repair\InitializeSettings
 */
class TenantSaasRegisterSchemasTest extends TestCase
{
    /**
     * Decoded register template payload.
     *
     * @var array<string,mixed>
     */
    private array $register;

    /**
     * Load the register template once per test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $path = __DIR__.'/../../../lib/Settings/procest_register.json';
        $this->assertFileExists($path);

        $payload = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($payload);
        $this->register = $payload;
    }//end setUp()

    /**
     * The seven SaaS tenant schemas exist with the documented required
     * properties.
     *
     * @return void
     */
    public function testSevenTenantSchemasDeclared(): void
    {
        $schemas = $this->register['components']['schemas'] ?? [];

        $expected = [
            'tenant'                 => ['slug', 'displayName', 'status', 'tier'],
            'tenantConfiguration'    => ['tenantRef'],
            'tenantQuota'            => ['tenantRef', 'quotaType'],
            'tenantUser'             => ['tenantRef', 'userRef'],
            'tenantMandate'          => ['tenantRef'],
            'tenantBillingEvent'     => ['tenantRef', 'eventType', 'occurredAt'],
            'tenantOnboardingTask'   => ['tenantRef', 'step', 'status'],
        ];

        foreach ($expected as $slug => $required) {
            $this->assertArrayHasKey(
                $slug,
                $schemas,
                "Schema {$slug} must be declared in the register template"
            );
            $this->assertSame(
                $required,
                $schemas[$slug]['required'] ?? [],
                "Schema {$slug} required-properties mismatch"
            );
            // Documented properties present.
            foreach ($required as $prop) {
                $this->assertArrayHasKey(
                    $prop,
                    $schemas[$slug]['properties'] ?? [],
                    "Schema {$slug} must declare property {$prop}"
                );
            }
        }
    }//end testSevenTenantSchemasDeclared()

    /**
     * TenantBillingEvent is marked insert-only (billing immutability).
     *
     * @return void
     */
    public function testTenantBillingEventIsInsertOnly(): void
    {
        $schema = $this->register['components']['schemas']['tenantBillingEvent'] ?? [];
        $this->assertTrue(
            ($schema['x-insert-only'] ?? false),
            'tenantBillingEvent must be insert-only for billing immutability'
        );
    }//end testTenantBillingEventIsInsertOnly()

    /**
     * The register lists every tenant schema so OR exposes them via REST.
     *
     * @return void
     */
    public function testRegisterIncludesAllTenantSchemas(): void
    {
        $listed = $this->register['components']['registers']['procest']['schemas'] ?? [];
        foreach (['tenant', 'tenantConfiguration', 'tenantQuota', 'tenantUser', 'tenantMandate', 'tenantBillingEvent', 'tenantOnboardingTask'] as $slug) {
            $this->assertContains(
                $slug,
                $listed,
                "Register must list schema {$slug}"
            );
        }
    }//end testRegisterIncludesAllTenantSchemas()

    /**
     * Each tier (basic, standard, enterprise) seeds four quota templates.
     *
     * @return void
     */
    public function testTierQuotaTemplatesSeeded(): void
    {
        $objects = $this->register['components']['objects'] ?? [];
        $byTier  = ['basic' => 0, 'standard' => 0, 'enterprise' => 0];
        foreach ($objects as $obj) {
            $slug = $obj['@self']['slug'] ?? '';
            if (str_starts_with($slug, 'tier-template-basic-') === true) {
                $byTier['basic']++;
            } elseif (str_starts_with($slug, 'tier-template-standard-') === true) {
                $byTier['standard']++;
            } elseif (str_starts_with($slug, 'tier-template-enterprise-') === true) {
                $byTier['enterprise']++;
            }
        }

        $this->assertSame(4, $byTier['basic'], 'basic tier needs 4 quota templates');
        $this->assertSame(4, $byTier['standard'], 'standard tier needs 4 quota templates');
        $this->assertSame(4, $byTier['enterprise'], 'enterprise tier needs 4 quota templates');
    }//end testTierQuotaTemplatesSeeded()

    /**
     * The seven default onboarding-task steps are seeded in `pending`.
     *
     * @return void
     */
    public function testDefaultOnboardingTemplateSeeded(): void
    {
        $objects = $this->register['components']['objects'] ?? [];
        $steps   = [];
        foreach ($objects as $obj) {
            if (($obj['@self']['schema'] ?? '') !== 'tenantOnboardingTask') {
                continue;
            }

            if (str_starts_with($obj['@self']['slug'] ?? '', 'default-onboarding-') === false) {
                continue;
            }

            $this->assertSame('pending', $obj['status'] ?? '');
            $steps[] = $obj['step'] ?? '';
        }

        $expectedSteps = [
            'contract',
            'mandate_import',
            'sso_setup',
            'branding',
            'zaaktype_selection',
            'first_user',
            'go_live',
        ];

        sort($steps);
        sort($expectedSteps);
        $this->assertSame($expectedSteps, $steps);
    }//end testDefaultOnboardingTemplateSeeded()
}//end class
