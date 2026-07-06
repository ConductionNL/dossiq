<?php

/**
 * SeedVerwerkingsactiviteiten Repair Step Unit Tests
 *
 * Tests the catalogue seed: upsert-by-code as draft, FG-owned status
 * preservation on refresh, and graceful skip when OpenRegister (or its
 * verwerkingsregister) is unavailable.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/specs/avg-verwerkingenlogging/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Repair;

use OCA\OpenRegister\Db\Verwerkingsactiviteit;
use OCA\OpenRegister\Db\VerwerkingsactiviteitMapper;
use OCA\Procest\Repair\SeedVerwerkingsactiviteiten;
use OCA\Procest\Service\SettingsService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for the SeedVerwerkingsactiviteiten repair step.
 *
 * @covers \OCA\Procest\Repair\SeedVerwerkingsactiviteiten
 */
class SeedVerwerkingsactiviteitenTest extends TestCase
{
    /**
     * The mocked settings service.
     *
     * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
     */
    private SettingsService $settingsService;

    /**
     * The mocked DI container.
     *
     * @var ContainerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private ContainerInterface $container;

    /**
     * The in-memory OR mapper stub.
     *
     * @var VerwerkingsactiviteitMapper
     */
    private VerwerkingsactiviteitMapper $mapper;

    /**
     * The repair step under test.
     *
     * @var SeedVerwerkingsactiviteiten
     */
    private SeedVerwerkingsactiviteiten $step;

    /**
     * Set up mocks.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->container       = $this->createMock(ContainerInterface::class);
        $this->mapper          = new VerwerkingsactiviteitMapper();

        $this->step = new SeedVerwerkingsactiviteiten(
            settingsService: $this->settingsService,
            container: $this->container,
            logger: $this->createMock(LoggerInterface::class),
        );

    }//end setUp()

    /**
     * Seeding is skipped entirely when OpenRegister is unavailable.
     *
     * @return void
     */
    public function testSkipsWhenOpenRegisterUnavailable(): void
    {
        $this->settingsService->method('isOpenRegisterAvailable')->willReturn(false);
        $this->container->expects($this->never())->method('get');

        $output = $this->createMock(IOutput::class);
        $output->expects($this->once())->method('warning');

        $this->step->run($output);

    }//end testSkipsWhenOpenRegisterUnavailable()

    /**
     * Seeding skips gracefully when the deployed OR predates the
     * verwerkingsregister (mapper class unresolvable).
     *
     * @return void
     */
    public function testSkipsWhenVerwerkingsregisterUnavailable(): void
    {
        $this->settingsService->method('isOpenRegisterAvailable')->willReturn(true);
        $this->container->method('get')->willThrowException(new \RuntimeException('class not found'));

        $output = $this->createMock(IOutput::class);
        $output->expects($this->once())->method('warning');
        $output->expects($this->never())->method('info');

        $this->step->run($output);

    }//end testSkipsWhenVerwerkingsregisterUnavailable()

    /**
     * A fresh run inserts every catalogue activity as a draft (concept).
     *
     * @return void
     */
    public function testFreshRunInsertsCatalogueAsDrafts(): void
    {
        $this->settingsService->method('isOpenRegisterAvailable')->willReturn(true);
        $this->container->method('get')->willReturn($this->mapper);

        $this->step->run($this->createMock(IOutput::class));

        $this->assertGreaterThanOrEqual(5, $this->mapper->inserts, 'catalogue must seed the case-handling activities');
        $this->assertSame(0, $this->mapper->updates);

        $default = $this->mapper->findByCode(code: 'zaakafhandeling');
        $this->assertNotNull($default, 'the default attribution activity must be seeded');
        $this->assertSame('concept', $default->getStatus(), 'seeded activities are drafts for FG review');
        $this->assertNotEmpty($default->getNaam());
        $this->assertNotEmpty($default->getDoelbinding());
        $this->assertContains($default->getRechtsgrond(), Verwerkingsactiviteit::RECHTSGROND_VOCABULARY);

    }//end testFreshRunInsertsCatalogueAsDrafts()

    /**
     * A re-run refreshes descriptive fields but never touches the
     * FG-owned lifecycle status (published stays published).
     *
     * @return void
     */
    public function testRerunPreservesFgActivatedStatus(): void
    {
        $this->settingsService->method('isOpenRegisterAvailable')->willReturn(true);
        $this->container->method('get')->willReturn($this->mapper);

        // First run seeds drafts; FG then publishes one in OpenRegister.
        $this->step->run($this->createMock(IOutput::class));
        $published = $this->mapper->findByCode(code: 'zaakafhandeling');
        $published->setStatus('published');
        $published->setNaam('FG-renamed');

        // Second run (app upgrade) refreshes fields, preserves status.
        $this->step->run($this->createMock(IOutput::class));

        $after = $this->mapper->findByCode(code: 'zaakafhandeling');
        $this->assertSame('published', $after->getStatus(), 'FG activation must survive procest upgrades');
        $this->assertNotSame('FG-renamed', $after->getNaam(), 'descriptive fields refresh from the catalogue');
        $this->assertGreaterThan(0, $this->mapper->updates);

    }//end testRerunPreservesFgActivatedStatus()

    /**
     * The shipped catalogue file is valid: unique codes, required AVG
     * art. 30 fields, and rechtsgronden within OR's art. 6 vocabulary
     * (so OR-side validation can never reject the seed).
     *
     * @return void
     */
    public function testShippedCatalogueSatisfiesOrValidation(): void
    {
        $path = __DIR__.'/../../../lib/Settings/verwerkingsactiviteiten.json';
        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($decoded);
        $this->assertIsArray($decoded['activities']);
        $this->assertNotEmpty($decoded['activities']);

        $codes = [];
        foreach ($decoded['activities'] as $activity) {
            $this->assertNotEmpty($activity['code']);
            $this->assertNotEmpty($activity['naam'], "{$activity['code']}: naam required (Art 30 §1(a))");
            $this->assertNotEmpty($activity['doelbinding'], "{$activity['code']}: doelbinding required (Art 30 §1(b))");
            $this->assertContains(
                $activity['rechtsgrond'],
                Verwerkingsactiviteit::RECHTSGROND_VOCABULARY,
                "{$activity['code']}: rechtsgrond must be an AVG art. 6 ground"
            );
            $this->assertNotEmpty($activity['bewaartermijn'], "{$activity['code']}: bewaartermijn required");
            $this->assertNotEmpty($activity['categorieenBetrokkenen'], "{$activity['code']}: betrokkene categories required");
            $codes[] = $activity['code'];
        }

        $this->assertSame($codes, array_unique($codes), 'catalogue codes must be unique (upsert key)');

    }//end testShippedCatalogueSatisfiesOrValidation()

    /**
     * Every attribution reference declared in the register annotations
     * resolves to a catalogue code — unmapped references would silently
     * land every read in OR's flagged fallback.
     *
     * @return void
     */
    public function testRegisterAttributionReferencesResolveToCatalogue(): void
    {
        $catalogue = json_decode(
            (string) file_get_contents(__DIR__.'/../../../lib/Settings/verwerkingsactiviteiten.json'),
            true
        );
        $codes = array_column($catalogue['activities'], 'code');

        $references = [];
        $files      = array_merge(
            [__DIR__.'/../../../lib/Settings/procest_register.json'],
            glob(__DIR__.'/../../../lib/Settings/register.d/*.json')
        );
        foreach ($files as $file) {
            $config = json_decode((string) file_get_contents($file), true);
            foreach (($config['components']['schemas'] ?? []) as $slug => $schema) {
                $processing = ($schema['configuration']['x-openregister-processing'] ?? null);
                if (is_array($processing) === false) {
                    continue;
                }

                foreach (($processing['attribution'] ?? []) as $reference) {
                    $references[$slug][] = $reference;
                    $this->assertContains(
                        $reference,
                        $codes,
                        "schema {$slug}: attribution reference '{$reference}' must exist in the catalogue"
                    );
                }
            }
        }

        $this->assertNotEmpty($references, 'at least one schema must declare processing attribution');
        $this->assertArrayHasKey('case', $references, 'the case schema must carry read-logging attribution');

    }//end testRegisterAttributionReferencesResolveToCatalogue()

    /**
     * The person-bearing schemas opt into read logging (logReads: true).
     *
     * @return void
     */
    public function testPersonBearingSchemasOptIntoReadLogging(): void
    {
        $expectations = [
            'lib/Settings/procest_register.json'         => ['case', 'role', 'customerContact'],
            'lib/Settings/register.d/40-kcc-werkplek.json' => ['contactmoment'],
        ];

        foreach ($expectations as $file => $slugs) {
            $config = json_decode((string) file_get_contents(__DIR__.'/../../../'.$file), true);
            foreach ($slugs as $slug) {
                $processing = ($config['components']['schemas'][$slug]['configuration']['x-openregister-processing'] ?? null);
                $this->assertIsArray($processing, "{$slug} must declare x-openregister-processing");
                $this->assertTrue($processing['logReads'], "{$slug} must enable logReads");
            }
        }

    }//end testPersonBearingSchemasOptIntoReadLogging()
}//end class
