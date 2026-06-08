<?php

/**
 * LegesCaseCalculationService Unit Tests
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/leges-heffingen/specs.md#req-leges-002
 * @spec openspec/changes/leges-heffingen/specs.md#req-leges-003
 * @spec openspec/changes/leges-heffingen/specs.md#req-leges-004
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\LegesCaseCalculationService;
use OCA\Procest\Service\LegesConditionEvaluator;
use OCA\Procest\Service\LegesContextResolver;
use OCA\Procest\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Object service stub matching the named-arg API used by the calculation service.
 */
interface LegesObjectServiceStub
{
    /**
     * Save an object (OpenRegister object-first signature).
     *
     * @param array       $object   Object data.
     * @param array       $extend   Extend parameters.
     * @param string|null $register Register id.
     * @param string|null $schema   Schema id.
     * @param string|null $uuid     Optional object uuid.
     *
     * @return array
     */
    public function saveObject(array $object, array $extend=[], ?string $register=null, ?string $schema=null, ?string $uuid=null);

    /**
     * Find a single object.
     *
     * @param string $id       Object id.
     * @param string $register Register id.
     * @param string $schema   Schema id.
     *
     * @return array
     */
    public function find(string $id, string $register='', string $schema='');

    /**
     * Find all objects.
     *
     * @param string $register Register id.
     * @param string $schema   Schema id.
     * @param array  $query    Query.
     *
     * @return array
     */
    public function findAll(string $register='', string $schema='', array $query=[]);
}//end interface

/**
 * Unit tests for LegesCaseCalculationService.
 *
 * @covers \OCA\Procest\Service\LegesCaseCalculationService
 */
class LegesCaseCalculationServiceTest extends TestCase
{

    /**
     * The mocked settings service.
     *
     * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
     */
    private SettingsService $settingsService;

    /**
     * The service under test.
     *
     * @var LegesCaseCalculationService
     */
    private LegesCaseCalculationService $service;

    /**
     * Set up fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->settingsService = $this->createMock(SettingsService::class);

        $logger        = $this->createMock(LoggerInterface::class);
        $this->service = new LegesCaseCalculationService(
            settingsService: $this->settingsService,
            evaluator: new LegesConditionEvaluator(),
            contextResolver: new LegesContextResolver(logger: $logger),
            logger: $logger
        );

        $this->settingsService->method('getConfigValue')->willReturnCallback(
            static function (string $key): string {
                $map = [
                    'register'                  => '1',
                    'case_schema'               => 'case',
                    'leges_tarief_tabel_schema' => 'legesTariefTabel',
                    'leges_tarief_schema'       => 'legesTarief',
                    'leges_variant_schema'      => 'legesVariant',
                    'leges_korting_schema'      => 'legesKorting',
                    'leges_berekening_schema'   => 'legesBerekening',
                ];
                return ($map[$key] ?? '');
            }
        );
    }//end setUp()

    /**
     * Build an ObjectService stub with the given fixtures.
     *
     * @param array $case      The case object.
     * @param array $perSchema Rows keyed by schema slug.
     *
     * @return LegesObjectServiceStub|\PHPUnit\Framework\MockObject\MockObject
     */
    private function objectServiceWith(array $case, array $perSchema)
    {
        $stub = $this->createMock(LegesObjectServiceStub::class);
        $stub->method('find')->willReturn($case);
        $stub->method('findAll')->willReturnCallback(
            static function (string $register, string $schema, array $query) use ($perSchema): array {
                $rows    = ($perSchema[$schema] ?? []);
                $filters = ($query['filters'] ?? []);
                if ($filters === []) {
                    return $rows;
                }

                return array_values(
                    array_filter(
                        $rows,
                        static function (array $row) use ($filters): bool {
                            foreach ($filters as $k => $v) {
                                if ((string) ($row[$k] ?? '') !== (string) $v) {
                                    return false;
                                }
                            }

                            return true;
                        }
                    )
                );
            }
        );
        $stub->method('saveObject')->willReturnCallback(
            static fn (array $object, array $extend=[], ?string $register=null, ?string $schema=null, ?string $uuid=null): array => array_merge(['id' => 'berekening-1'], $object)
        );

        return $stub;
    }//end objectServiceWith()

    /**
     * A vast tariff produces the fixed amount with the right VAT split.
     *
     * @return void
     */
    public function testVastTarief(): void
    {
        $case      = ['id' => 'case-1', 'caseType' => 'ct-paspoort', 'startDate' => '2026-03-01'];
        $perSchema = [
            'legesTariefTabel' => [
                ['id' => 'tab-1', 'naam' => 'Verordening 2026', 'status' => 'vastgesteld', 'geldigVanaf' => '2026-01-01', 'geldigTotEnMet' => ''],
            ],
            'legesTarief'      => [
                ['id' => 't-1', 'tariefTabelId' => 'tab-1', 'zaaktype' => 'ct-paspoort', 'tariefNummer' => '1.1.1', 'omschrijving' => 'Paspoort', 'bedrag' => 10000, 'grondslag' => 'vast', 'btwTarief' => 0],
            ],
            'legesVariant'     => [],
            'legesKorting'     => [],
        ];

        $this->settingsService->method('getObjectService')->willReturn($this->objectServiceWith($case, $perSchema));

        $result = $this->service->calculateForCase(caseId: 'case-1', calculatedBy: 'system');

        $this->assertSame(10000, $result['bedragExclBtw']);
        $this->assertSame(0, $result['btwBedrag']);
        $this->assertSame(10000, $result['bedragInclBtw']);
        $this->assertSame('berekend', $result['status']);
        $this->assertSame('2026-03-01', $result['berekendeOp']);
        $this->assertStringContainsString('Paspoort', $result['berekeningsToelichting']);
    }//end testVastTarief()

    /**
     * A bouwsom percentage tariff is computed from the case attribute.
     *
     * @return void
     */
    public function testBouwsomPercentageTarief(): void
    {
        $case      = ['id' => 'case-2', 'caseType' => 'ct-bouw', 'startDate' => '2026-06-01', 'bouwsom' => 250000];
        $perSchema = [
            'legesTariefTabel' => [
                ['id' => 'tab-1', 'naam' => 'Verordening 2026', 'status' => 'vastgesteld', 'geldigVanaf' => '2026-01-01', 'geldigTotEnMet' => ''],
            ],
            'legesTarief'      => [
                ['id' => 't-2', 'tariefTabelId' => 'tab-1', 'zaaktype' => 'ct-bouw', 'tariefNummer' => '2.3.1.1', 'omschrijving' => 'Bouw', 'bedrag' => 35000, 'grondslag' => 'bouwsom', 'grondslagVeld' => 'bouwsom', 'percentage' => 3.0, 'btwTarief' => 0],
            ],
            'legesVariant'     => [],
            'legesKorting'     => [],
        ];

        $this->settingsService->method('getObjectService')->willReturn($this->objectServiceWith($case, $perSchema));

        $result = $this->service->calculateForCase(caseId: 'case-2', calculatedBy: 'system');

        // 3% of 250000 = 7500 euro = 750000 cents (above the 35000 minimum).
        $this->assertSame(750000, $result['bedragInclBtw']);
    }//end testBouwsomPercentageTarief()

    /**
     * A spoed variant overrides the base amount.
     *
     * @return void
     */
    public function testVariantOverride(): void
    {
        $case      = ['id' => 'case-3', 'caseType' => 'ct-rijbewijs', 'startDate' => '2026-02-01', 'spoedAanvraag' => true];
        $perSchema = [
            'legesTariefTabel' => [
                ['id' => 'tab-1', 'naam' => 'Verordening 2026', 'status' => 'vastgesteld', 'geldigVanaf' => '2026-01-01', 'geldigTotEnMet' => ''],
            ],
            'legesTarief'      => [
                ['id' => 't-3', 'tariefTabelId' => 'tab-1', 'zaaktype' => 'ct-rijbewijs', 'tariefNummer' => '1.2.1', 'omschrijving' => 'Rijbewijs', 'bedrag' => 4875, 'grondslag' => 'vast', 'btwTarief' => 0],
            ],
            'legesVariant'     => [
                ['id' => 'v-1', 'tariefId' => 't-3', 'variantNaam' => 'Spoed', 'condities' => ['spoedAanvraag' => true], 'bedragOverride' => 6750],
            ],
            'legesKorting'     => [],
        ];

        $this->settingsService->method('getObjectService')->willReturn($this->objectServiceWith($case, $perSchema));

        $result = $this->service->calculateForCase(caseId: 'case-3', calculatedBy: 'system');

        $this->assertSame(6750, $result['bedragInclBtw']);
        $this->assertSame('v-1', $result['variantId']);
    }//end testVariantOverride()

    /**
     * A full age exemption reduces the amount to zero and records the discount.
     *
     * @return void
     */
    public function testLeeftijdVrijstelling(): void
    {
        $birth     = (new \DateTimeImmutable())->modify('-67 years')->format('Y-m-d');
        $case      = ['id' => 'case-4', 'caseType' => 'ct-rijbewijs', 'startDate' => '2026-02-01', 'geboortedatum' => $birth];
        $perSchema = [
            'legesTariefTabel' => [
                ['id' => 'tab-1', 'naam' => 'Verordening 2026', 'status' => 'vastgesteld', 'geldigVanaf' => '2026-01-01', 'geldigTotEnMet' => ''],
            ],
            'legesTarief'      => [
                ['id' => 't-3', 'tariefTabelId' => 'tab-1', 'zaaktype' => 'ct-rijbewijs', 'tariefNummer' => '1.2.1', 'omschrijving' => 'Rijbewijs', 'bedrag' => 4875, 'grondslag' => 'vast', 'btwTarief' => 0],
            ],
            'legesVariant'     => [],
            'legesKorting'     => [
                ['id' => 'k-1', 'naam' => '65-plus', 'tariefIds' => ['t-3'], 'kortingsType' => 'volledige_vrijstelling', 'kortingsWaarde' => 100, 'condities' => ['leeftijd' => ['min' => 65]], 'geldigVanaf' => '2026-01-01'],
            ],
        ];

        $this->settingsService->method('getObjectService')->willReturn($this->objectServiceWith($case, $perSchema));

        $result = $this->service->calculateForCase(caseId: 'case-4', calculatedBy: 'system');

        $this->assertSame(0, $result['bedragInclBtw']);
        $this->assertCount(1, $result['appliedKortingen']);
        $this->assertSame(-4875, $result['appliedKortingen'][0]['bedrag']);
    }//end testLeeftijdVrijstelling()

    /**
     * A minima discount requiring verification flags the calculation as pending.
     *
     * @return void
     */
    public function testMinimaPendingFlag(): void
    {
        $case      = ['id' => 'case-5', 'caseType' => 'ct-uittreksel', 'startDate' => '2026-02-01', 'huishoudinkomen' => 1500000];
        $perSchema = [
            'legesTariefTabel' => [
                ['id' => 'tab-1', 'naam' => 'Verordening 2026', 'status' => 'vastgesteld', 'geldigVanaf' => '2026-01-01', 'geldigTotEnMet' => ''],
            ],
            'legesTarief'      => [
                ['id' => 't-5', 'tariefTabelId' => 'tab-1', 'zaaktype' => 'ct-uittreksel', 'tariefNummer' => '1.5.1', 'omschrijving' => 'Uittreksel', 'bedrag' => 1500, 'grondslag' => 'vast', 'btwTarief' => 0],
            ],
            'legesVariant'     => [],
            'legesKorting'     => [
                ['id' => 'k-2', 'naam' => 'Minima', 'tariefIds' => ['t-5'], 'kortingsType' => 'volledige_vrijstelling', 'kortingsWaarde' => 100, 'condities' => ['huishoudinkomen' => ['max' => 2000000]], 'vereistMinimaCheck' => true, 'geldigVanaf' => '2026-01-01'],
            ],
        ];

        $this->settingsService->method('getObjectService')->willReturn($this->objectServiceWith($case, $perSchema));

        $result = $this->service->calculateForCase(caseId: 'case-5', calculatedBy: 'system');

        // Discount is held pending verification, so the amount is NOT reduced.
        $this->assertSame('pending_minima_check', $result['status']);
        $this->assertSame(1500, $result['bedragInclBtw']);
        $this->assertCount(0, $result['appliedKortingen']);
    }//end testMinimaPendingFlag()
}//end class
