<?php

/**
 * Portal Contribution Provider Test
 *
 * Verifies procest's three-audience Portaliq contribution (ADR-046,
 * procest#162): the advertised audiences, per-audience collections/actions and
 * the fail-closed null for an unserved audience — plus a register-drift pin that
 * asserts every declared scopeField and every field-projection entry exists as a
 * property on its schema in procest_register.json (so a schema rename can never
 * silently break a portal scope key or leak a dropped column).
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/move-portals-to-portaliq/tasks.md#T6
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Portal;

use OCA\Procest\Portal\PortalContributionProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Procest\Portal\PortalContributionProvider
 */
class PortalContributionProviderTest extends TestCase
{
    /**
     * The provider under test.
     *
     * @var PortalContributionProvider
     */
    private PortalContributionProvider $provider;

    /**
     * Schema definitions from procest_register.json, keyed by slug.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $schemas;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new PortalContributionProvider();

        // Load the base register + every register.d fragment exactly as the
        // runtime does (SettingsService merges lib/Settings/register.d/*.json
        // on top of procest_register.json), so a scope key that lives in a
        // fragment schema (e.g. portaalBericht in 50-zaakportaal.json) is
        // resolvable by the drift pin.
        $settingsDir = __DIR__.'/../../../lib/Settings';
        $basePath    = $settingsDir.'/procest_register.json';
        $this->assertFileExists($basePath);

        $files = array_merge([$basePath], glob($settingsDir.'/register.d/*.json'));

        // Union every schema's properties across the base + all fragments — a
        // fragment that re-declares a schema (e.g. `case`) contributes ADDED
        // properties rather than replacing the whole definition, mirroring the
        // runtime union merge.
        $this->schemas = [];
        foreach ($files as $file) {
            foreach ($this->schemasFrom($file) as $slug => $definition) {
                $props = (is_array(($definition['properties'] ?? null)) === true ? $definition['properties'] : []);
                $this->schemas[$slug]['properties'] = array_merge(($this->schemas[$slug]['properties'] ?? []), $props);
            }
        }

        $this->assertNotEmpty($this->schemas, 'the register must declare schemas');
    }

    public function testAdvertisesThreeAudiences(): void
    {
        $this->assertSame(['supplier', 'citizen', 'inspector'], $this->provider->getAudiences());
    }

    public function testPrimaryAudienceFallbackIsSupplier(): void
    {
        $this->assertSame('supplier', $this->provider->getAudience());
    }

    public function testUnservedAudienceContributesNull(): void
    {
        $this->assertNull($this->provider->getContribution(['audience' => 'employee']));
        $this->assertNull($this->provider->getContribution(['audience' => '']));
        $this->assertNull($this->provider->getContribution([]));
    }

    public function testSupplierContributionShape(): void
    {
        $contribution = $this->provider->getContribution(['audience' => 'supplier']);
        $this->assertIsArray($contribution);
        $ids = array_column($contribution['collections'], 'id');
        $this->assertSame(['tenders', 'contracts', 'invoices', 'messages'], $ids);
        foreach ($contribution['collections'] as $collection) {
            $this->assertSame('supplierRef', $collection['scopeField']);
        }
    }

    public function testCitizenContributionShape(): void
    {
        $contribution = $this->provider->getContribution(['audience' => 'citizen']);
        $this->assertIsArray($contribution);
        $ids = array_column($contribution['collections'], 'id');
        $this->assertSame(['mijnZaken', 'berichten', 'verzoeken'], $ids);

        // Exactly one safe create — the standalone complaint.
        $actionIds = array_column($contribution['actions'], 'id');
        $this->assertSame(['createKlacht'], $actionIds);
        // The deferred bezwaar / message-reply creates must NOT be declared.
        $this->assertNotContains('createBezwaar', $actionIds);
        $this->assertNotContains('sendMessage', $actionIds);
    }

    public function testInspectorContributionShape(): void
    {
        $contribution = $this->provider->getContribution(['audience' => 'inspector']);
        $this->assertIsArray($contribution);
        $ids = array_column($contribution['collections'], 'id');
        $this->assertSame(['inspectieRapporten', 'checklistRuns'], $ids);
        foreach ($contribution['collections'] as $collection) {
            $this->assertSame('assignedInspectorRef', $collection['scopeField']);
        }
        // Run-submit create is deferred (write-IDOR).
        $this->assertSame([], $contribution['actions']);
    }

    /**
     * Register-drift pin: every scopeField + projected field declared by every
     * collection AND action, across all three audiences, must exist as a
     * property on its schema.
     *
     * @dataProvider audienceProvider
     */
    public function testEveryDeclaredFieldExistsOnItsSchema(string $audience): void
    {
        $contribution = $this->provider->getContribution(['audience' => $audience]);
        $this->assertIsArray($contribution);

        $entries = array_merge(($contribution['collections'] ?? []), ($contribution['actions'] ?? []));
        $this->assertNotEmpty($entries);

        foreach ($entries as $entry) {
            $schemaSlug = $entry['schema'];
            $props      = $this->propertiesFor($schemaSlug);

            $this->assertArrayHasKey(
                $entry['scopeField'],
                $props,
                "scopeField '{$entry['scopeField']}' must exist on schema '{$schemaSlug}' ({$audience}/{$entry['id']})"
            );

            foreach (($entry['fields'] ?? []) as $field) {
                $this->assertArrayHasKey(
                    $field,
                    $props,
                    "projected field '{$field}' must exist on schema '{$schemaSlug}' ({$audience}/{$entry['id']})"
                );
            }
        }
    }

    /**
     * @return array<int, array<int, string>>
     */
    public static function audienceProvider(): array
    {
        return [['supplier'], ['citizen'], ['inspector']];
    }

    /**
     * Read the schema definitions from one register JSON file, keyed by slug.
     *
     * @param string $path The register/fragment JSON path.
     *
     * @return array<string, array<string, mixed>>
     */
    private function schemasFrom(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true);
        if (is_array($decoded) === false) {
            return [];
        }

        $schemas = ($decoded['components']['schemas'] ?? []);
        return (is_array($schemas) === true ? $schemas : []);
    }

    /**
     * Resolve a schema's declared properties by slug.
     *
     * @param string $slug The schema slug.
     *
     * @return array<string, mixed>
     */
    private function propertiesFor(string $slug): array
    {
        $this->assertArrayHasKey($slug, $this->schemas, "schema '{$slug}' must exist in procest_register.json");
        $props = ($this->schemas[$slug]['properties'] ?? []);
        $this->assertNotEmpty($props, "schema '{$slug}' must declare properties");
        return $props;
    }
}
