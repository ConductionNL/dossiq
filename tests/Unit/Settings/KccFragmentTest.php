<?php

/**
 * KCC Register Fragment Unit Tests
 *
 * Verifies that the register.d/30-kcc.json fragment unions its schemas,
 * register membership and seed objects onto the procest monolith via the
 * ADR-037 deep-merge loader, and that the contactMoment (customerContact)
 * schema is extended rather than duplicated.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Settings;

use OCA\Procest\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Integration-style unit tests for the KCC register fragment.
 *
 * @covers \OCA\Procest\Service\SettingsService
 */
class KccFragmentTest extends TestCase
{

    /**
     * @var array<string, mixed>
     */
    private array $merged;

    /**
     * Load the monolith and merge the real register.d fragments.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $base = json_decode(
            (string) file_get_contents(__DIR__.'/../../../lib/Settings/procest_register.json'),
            true
        );

        $reflection = new ReflectionMethod(SettingsService::class, 'mergeRegisterFragments');
        $reflection->setAccessible(true);

        [$merged] = $reflection->invokeArgs(
            null,
            [$base, __DIR__.'/../../../lib/Settings/register.d']
        );

        $this->merged = $merged;
    }//end setUp()

    /**
     * The KCC operational schemas are present after the merge.
     *
     * @return void
     */
    public function testKccSchemasPresent(): void
    {
        $schemas = $this->merged['components']['schemas'];
        $this->assertArrayHasKey('routingRule', $schemas);
        $this->assertArrayHasKey('kccAgent', $schemas);
        $this->assertArrayHasKey('callbackRequest', $schemas);
    }//end testKccSchemasPresent()

    /**
     * The contactMoment entity reuses the existing customerContact schema,
     * extended with KCC fields rather than introducing a new schema.
     *
     * @return void
     */
    public function testContactMomentReusesCustomerContact(): void
    {
        $schemas = $this->merged['components']['schemas'];
        $this->assertArrayHasKey('customerContact', $schemas);
        $this->assertArrayNotHasKey('contactMoment', $schemas, 'A new contactMoment schema must NOT be invented');

        $props = $schemas['customerContact']['properties'];
        // Original property survives the union.
        $this->assertArrayHasKey('case', $props);
        // KCC extensions are merged in.
        $this->assertArrayHasKey('direction', $props);
        $this->assertArrayHasKey('outcome', $props);
        $this->assertArrayHasKey('kccAgentRef', $props);
    }//end testContactMomentReusesCustomerContact()

    /**
     * The procest register lists the new KCC schemas (list concatenation).
     *
     * @return void
     */
    public function testRegisterMembershipUnioned(): void
    {
        $schemas = $this->merged['components']['registers']['procest']['schemas'];
        $this->assertContains('routingRule', $schemas);
        $this->assertContains('kccAgent', $schemas);
        $this->assertContains('callbackRequest', $schemas);
        // Existing membership preserved.
        $this->assertContains('caseType', $schemas);
    }//end testRegisterMembershipUnioned()

    /**
     * KCC seed objects are appended to components.objects.
     *
     * @return void
     */
    public function testSeedObjectsAppended(): void
    {
        $slugs = array_map(
            static function (array $object): string {
                return (string) ($object['@self']['slug'] ?? '');
            },
            $this->merged['components']['objects']
        );

        $this->assertContains('kcc-rule-paspoort-burgerzaken', $slugs);
        $this->assertContains('kcc-agent-maria-santos', $slugs);
        $this->assertContains('kcc-callback-demo-scheduled', $slugs);
    }//end testSeedObjectsAppended()
}//end class
