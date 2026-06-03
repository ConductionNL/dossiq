<?php

/**
 * KCC-werkplek Register Fragment Unit Tests (ADR-037)
 *
 * Validates that the KCC-werkplek bridge ships its schemas and seed objects as
 * a modular register.d fragment (never editing the monolith) and that the
 * fragment merges cleanly onto the base register, unioning the register's schema
 * membership list and seed object list.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T01
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Settings;

use OCA\Procest\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Unit tests for the 40-kcc-werkplek.json register fragment.
 *
 * @covers \OCA\Procest\Service\SettingsService
 */
class KccWerkplekFragmentTest extends TestCase
{
    /**
     * The decoded KCC fragment.
     *
     * @var array<string, mixed>
     */
    private array $fragment;

    /**
     * The decoded base register.
     *
     * @var array<string, mixed>
     */
    private array $base;

    /**
     * Schema slugs the KCC bridge adds.
     *
     * @var array<int, string>
     */
    private array $kccSchemas = [
        'contactmoment',
        'kccQuickAction',
        'belplan',
        'specialistBeschikbaarheid',
        'doorverbinding',
        'klantSentiment',
    ];

    /**
     * Set up fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $fragmentPath = __DIR__.'/../../../lib/Settings/register.d/40-kcc-werkplek.json';
        $basePath     = __DIR__.'/../../../lib/Settings/procest_register.json';

        $this->fragment = json_decode((string) file_get_contents($fragmentPath), true);
        $this->base     = json_decode((string) file_get_contents($basePath), true);
    }//end setUp()

    /**
     * The fragment is valid JSON declaring every KCC schema.
     *
     * @return void
     */
    public function testFragmentDeclaresAllSchemas(): void
    {
        $this->assertIsArray($this->fragment);
        $schemas = $this->fragment['components']['schemas'];

        foreach ($this->kccSchemas as $slug) {
            $this->assertArrayHasKey($slug, $schemas, "Fragment must declare schema '{$slug}'");
            $this->assertSame($slug, $schemas[$slug]['slug']);
            $this->assertArrayHasKey('properties', $schemas[$slug]);
        }
    }//end testFragmentDeclaresAllSchemas()

    /**
     * The monolith must NOT carry the KCC schemas (ADR-037: fragment only).
     *
     * @return void
     */
    public function testMonolithDoesNotCarryKccSchemas(): void
    {
        $baseSchemas = $this->base['components']['schemas'];

        foreach ($this->kccSchemas as $slug) {
            $this->assertArrayNotHasKey($slug, $baseSchemas, "ADR-037: '{$slug}' must live only in the fragment");
        }
    }//end testMonolithDoesNotCarryKccSchemas()

    /**
     * No burger/person schema is introduced (NC contact entity is reused).
     *
     * @return void
     */
    public function testNoBurgerPersonSchemaIntroduced(): void
    {
        $schemas = $this->fragment['components']['schemas'];

        $this->assertArrayNotHasKey('burger', $schemas);
        $this->assertArrayNotHasKey('person', $schemas);
        $this->assertArrayNotHasKey('customer', $schemas);
    }//end testNoBurgerPersonSchemaIntroduced()

    /**
     * Merging the fragment unions the register schema membership and seed list.
     *
     * @return void
     */
    public function testMergeUnionsRegisterMembershipAndObjects(): void
    {
        $reflection = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
        $reflection->setAccessible(true);

        $merged = $reflection->invokeArgs(null, [$this->base, $this->fragment]);

        $registerSchemas = $merged['components']['registers']['procest']['schemas'];
        foreach ($this->kccSchemas as $slug) {
            $this->assertContains($slug, $registerSchemas, "Merged register must include '{$slug}'");
        }

        // Base case/role types must survive the merge.
        $this->assertContains('case', $registerSchemas);

        // Seed objects from base + fragment are concatenated.
        $this->assertGreaterThan(
            count($this->base['components']['objects']),
            count($merged['components']['objects']),
        );
    }//end testMergeUnionsRegisterMembershipAndObjects()

    /**
     * The fragment seeds the five default quick-actions and two belplannen.
     *
     * @return void
     */
    public function testFragmentSeedsDefaults(): void
    {
        $objects = $this->fragment['components']['objects'];

        $quickActions = array_filter(
            $objects,
            static function (array $o): bool {
                return (($o['@self']['schema'] ?? '') === 'kccQuickAction');
            }
        );
        $belplannen = array_filter(
            $objects,
            static function (array $o): bool {
                return (($o['@self']['schema'] ?? '') === 'belplan');
            }
        );

        $this->assertCount(5, $quickActions);
        $this->assertCount(2, $belplannen);
    }//end testFragmentSeedsDefaults()
}//end class
