<?php

/**
 * ZgwZrcRulesService Unit Tests
 *
 * Tests for the ZRC (Zaken API) business rule validation and enrichment service.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\ZgwZrcRulesService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Minimal ObjectService shape used by ZgwZrcRulesService.
 *
 * Defined here so the unit tests can `createMock(ObjectServiceStub::class)`
 * and get a mock whose method signatures match the named-arg calls in
 * ZgwRulesBase. Using `getMockBuilder(\stdClass::class)->addMethods([...])`
 * generates parameter-less stubs, which throws
 * "Error: Unknown named parameter" when production code passes named
 * arguments. This interface declares the real parameter names so named-arg
 * calls resolve correctly.
 */
interface ObjectServiceStub
{
    public function find(string $id, string $register, string $schema): ?array;
    public function buildSearchQuery(array $criteria): array;
    public function searchObjectsPaginated(array $query): array;

}//end interface

/**
 * Unit tests for ZgwZrcRulesService.
 *
 * @covers \OCA\Procest\Service\ZgwZrcRulesService
 */
class ZgwZrcRulesServiceTest extends TestCase
{

    /**
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface $logger;

    /**
     * @var SettingsService&MockObject
     */
    private SettingsService $settingsService;

    /**
     * The service under test.
     *
     * @var ZgwZrcRulesService
     */
    private ZgwZrcRulesService $service;


    /**
     * Set up the test environment.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->logger          = $this->createMock(LoggerInterface::class);
        $this->settingsService = $this->createMock(SettingsService::class);

        $this->service = new ZgwZrcRulesService(
            logger: $this->logger,
            settingsService: $this->settingsService
        );

    }//end setUp()


    // -------------------------------------------------------------------------
    // Task 6.1 — detectEindstatus() tests
    // -------------------------------------------------------------------------


    /**
     * Test detectEindstatus returns false when objectService is null.
     *
     * @return void
     */
    public function testDetectEindstatusReturnsFalseWithoutObjectService(): void
    {
        // No objectService set — context not initialised.
        $result = $this->service->detectEindstatus(
            statustypeUuid: 'uuid-st-1',
            zaaktypeUuid: 'uuid-zt-1'
        );

        $this->assertFalse($result);

    }//end testDetectEindstatusReturnsFalseWithoutObjectService()


    /**
     * Test detectEindstatus returns true when isEindstatus is explicitly true.
     *
     * @return void
     */
    public function testDetectEindstatusExplicitTrue(): void
    {
        $objectService = $this->createMock(\stdClass::class);
        $objectService->method('find')->willReturn([
            'isEindstatus' => true,
            'caseType'     => 'uuid-zt-1',
        ]);

        $this->settingsService
            ->method('getConfigValue')
            ->willReturnCallback(
                static function (string $key): string {
                    return match ($key) {
                        'status_type_schema' => 'schema-st',
                        default              => '',
                    };
                }
            );

        $this->service->setContext(
            objectService: $objectService,
            mappingConfig: ['sourceRegister' => '1']
        );

        $result = $this->service->detectEindstatus(
            statustypeUuid: 'uuid-st-eindstatus',
            zaaktypeUuid: 'uuid-zt-1'
        );

        $this->assertTrue($result);

    }//end testDetectEindstatusExplicitTrue()


    /**
     * Test detectEindstatus returns false when isEindstatus is explicitly false.
     *
     * @return void
     */
    public function testDetectEindstatusExplicitFalse(): void
    {
        $objectService = $this->createMock(\stdClass::class);
        $objectService->method('find')->willReturn([
            'isEindstatus' => false,
            'caseType'     => 'uuid-zt-1',
        ]);

        $this->service->setContext(
            objectService: $objectService,
            mappingConfig: ['sourceRegister' => '1']
        );

        $result = $this->service->detectEindstatus(
            statustypeUuid: 'uuid-st-1',
            zaaktypeUuid: 'uuid-zt-1'
        );

        $this->assertFalse($result);

    }//end testDetectEindstatusExplicitFalse()


    /**
     * Test detectEindstatus uses volgnummer fallback when isEindstatus is absent.
     *
     * @return void
     */
    public function testDetectEindstatusVolgnummerFallbackHighestIsEindstatus(): void
    {
        $uuidHighest = 'uuid-st-highest';
        $uuidLower   = 'uuid-st-lower';

        $objectService = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['find', 'buildSearchQuery', 'searchObjectsPaginated'])
            ->getMock();

        // find() returns statustype data (no isEindstatus field).
        $objectService->method('find')->willReturn([
            'id'         => $uuidHighest,
            'caseType'   => 'uuid-zt-1',
            'order'      => 10,
        ]);

        $objectService->method('buildSearchQuery')->willReturn(['caseType' => 'uuid-zt-1']);

        $objectService->method('searchObjectsPaginated')->willReturn([
            'results' => [
                ['id' => $uuidHighest, 'order' => 10],
                ['id' => $uuidLower, 'order' => 5],
            ],
        ]);

        $this->settingsService
            ->method('getConfigValue')
            ->willReturnCallback(
                static function (string $key): string {
                    return match ($key) {
                        'status_type_schema' => 'schema-st',
                        default              => '',
                    };
                }
            );

        $this->service->setContext(
            objectService: $objectService,
            mappingConfig: ['sourceRegister' => '1']
        );

        // Highest volgnummer → is eindstatus.
        $result = $this->service->detectEindstatus(
            statustypeUuid: $uuidHighest,
            zaaktypeUuid: 'uuid-zt-1'
        );
        $this->assertTrue($result);

    }//end testDetectEindstatusVolgnummerFallbackHighestIsEindstatus()


    /**
     * Test detectEindstatus returns false for lower volgnummer when isEindstatus absent.
     *
     * @return void
     */
    public function testDetectEindstatusVolgnummerFallbackLowerIsNotEindstatus(): void
    {
        $uuidHighest = 'uuid-st-highest';
        $uuidLower   = 'uuid-st-lower';

        $objectService = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['find', 'buildSearchQuery', 'searchObjectsPaginated'])
            ->getMock();

        $objectService->method('find')->willReturn([
            'id'       => $uuidLower,
            'caseType' => 'uuid-zt-1',
            'order'    => 5,
        ]);

        $objectService->method('buildSearchQuery')->willReturn(['caseType' => 'uuid-zt-1']);

        $objectService->method('searchObjectsPaginated')->willReturn([
            'results' => [
                ['id' => $uuidHighest, 'order' => 10],
                ['id' => $uuidLower, 'order' => 5],
            ],
        ]);

        $this->settingsService
            ->method('getConfigValue')
            ->willReturnCallback(
                static function (string $key): string {
                    return match ($key) {
                        'status_type_schema' => 'schema-st',
                        default              => '',
                    };
                }
            );

        $this->service->setContext(
            objectService: $objectService,
            mappingConfig: ['sourceRegister' => '1']
        );

        $result = $this->service->detectEindstatus(
            statustypeUuid: $uuidLower,
            zaaktypeUuid: 'uuid-zt-1'
        );
        $this->assertFalse($result);

    }//end testDetectEindstatusVolgnummerFallbackLowerIsNotEindstatus()


    // -------------------------------------------------------------------------
    // Task 6.2 — filterZakenForConsumer() tests
    // -------------------------------------------------------------------------


    /**
     * Test filterZakenForConsumer returns all zaken when no authorizations provided.
     *
     * @return void
     */
    public function testFilterZakenForConsumerUnfilteredWithoutAuthorizations(): void
    {
        $zaken = [
            ['zaaktype' => 'http://example.com/zaaktypen/uuid-zt-1', 'vertrouwelijkheidaanduiding' => 'openbaar'],
            ['zaaktype' => 'http://example.com/zaaktypen/uuid-zt-2', 'vertrouwelijkheidaanduiding' => 'geheim'],
        ];

        $result = $this->service->filterZakenForConsumer(
            zaken: $zaken,
            authorizations: []
        );

        $this->assertCount(2, $result);

    }//end testFilterZakenForConsumerUnfilteredWithoutAuthorizations()


    /**
     * Test filterZakenForConsumer excludes zaken from unauthorized zaaktypen.
     *
     * @return void
     */
    public function testFilterZakenForConsumerExcludesUnauthorizedZaaktype(): void
    {
        $zaaktypeUuid1 = 'aabbccdd-1111-2222-3333-444455556666';
        $zaaktypeUuid2 = 'bbccddee-2222-3333-4444-555566667777';

        $zaken = [
            ['zaaktype' => "http://example.com/zaaktypen/{$zaaktypeUuid1}", 'vertrouwelijkheidaanduiding' => 'openbaar'],
            ['zaaktype' => "http://example.com/zaaktypen/{$zaaktypeUuid2}", 'vertrouwelijkheidaanduiding' => 'openbaar'],
        ];

        $authorizations = [
            ['zaaktype' => "http://example.com/zaaktypen/{$zaaktypeUuid1}", 'maxVertrouwelijkheidaanduiding' => 'openbaar'],
        ];

        $result = $this->service->filterZakenForConsumer(
            zaken: $zaken,
            authorizations: $authorizations
        );

        $this->assertCount(1, $result);
        $this->assertStringContainsString($zaaktypeUuid1, $result[0]['zaaktype']);

    }//end testFilterZakenForConsumerExcludesUnauthorizedZaaktype()


    /**
     * Test filterZakenForConsumer excludes zaken exceeding maxVertrouwelijkheidaanduiding.
     *
     * @return void
     */
    public function testFilterZakenForConsumerExcludesExceedingVertrouwelijkheid(): void
    {
        $zaaktypeUuid = 'aabbccdd-1111-2222-3333-444455556666';

        $zaken = [
            ['zaaktype' => "http://example.com/zaaktypen/{$zaaktypeUuid}", 'vertrouwelijkheidaanduiding' => 'openbaar'],
            ['zaaktype' => "http://example.com/zaaktypen/{$zaaktypeUuid}", 'vertrouwelijkheidaanduiding' => 'zeer_geheim'],
        ];

        $authorizations = [
            ['zaaktype' => "http://example.com/zaaktypen/{$zaaktypeUuid}", 'maxVertrouwelijkheidaanduiding' => 'intern'],
        ];

        $result = $this->service->filterZakenForConsumer(
            zaken: $zaken,
            authorizations: $authorizations
        );

        $this->assertCount(1, $result);
        $this->assertSame('openbaar', $result[0]['vertrouwelijkheidaanduiding']);

    }//end testFilterZakenForConsumerExcludesExceedingVertrouwelijkheid()


    // -------------------------------------------------------------------------
    // Task 6.3 — Error code fix tests (zrc-010, zrc-013a, zrc-002, zrc-015)
    // -------------------------------------------------------------------------


    /**
     * Test zrc-010: communicatiekanaal valid URL without UUID → invalid-resource.
     *
     * @return void
     */
    public function testCommunicatiekanaalCollectionUrlReturnsInvalidResource(): void
    {
        $body   = ['communicatiekanaal' => 'https://example.com/kanalen'];
        $result = $this->service->rulesZakenCreate($body);

        $this->assertFalse($result['valid']);
        $invalidParams = $result['invalidParams'];
        $this->assertNotEmpty($invalidParams);
        $code = $invalidParams[0]['code'];
        $this->assertSame('invalid-resource', $code);

    }//end testCommunicatiekanaalCollectionUrlReturnsInvalidResource()


    /**
     * Test zrc-010: completely invalid URL → bad-url.
     *
     * @return void
     */
    public function testCommunicatiekanaalInvalidUrlReturnsBadUrl(): void
    {
        $body   = ['communicatiekanaal' => 'not-a-url'];
        $result = $this->service->rulesZakenCreate($body);

        $this->assertFalse($result['valid']);
        $code = $result['invalidParams'][0]['code'];
        $this->assertSame('bad-url', $code);

    }//end testCommunicatiekanaalInvalidUrlReturnsBadUrl()


    /**
     * Test zrc-013a: hoofdzaak not found returns does-not-exist (not no_match).
     *
     * @return void
     */
    public function testHoofdzaakNotFoundReturnsDoesNotExist(): void
    {
        $zaaktypeUuid   = 'aabbccdd-1111-2222-3333-444455556666';
        $hoofdzaakUuid  = 'ccddccdd-5555-6666-7777-888899990000';

        // objectService: find() for hoofdzaak returns null (not found).
        $objectService = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['find', 'buildSearchQuery', 'searchObjectsPaginated'])
            ->getMock();
        $objectService->method('find')->willReturn(null);

        $this->settingsService
            ->method('getConfigValue')
            ->willReturn('schema-case');

        $this->service->setContext(
            objectService: $objectService,
            mappingConfig: ['sourceRegister' => '1', 'sourceSchema' => '2']
        );

        $body = [
            'zaaktype'   => "http://example.com/zaaktypen/{$zaaktypeUuid}",
            'hoofdzaak'  => "http://example.com/zaken/{$hoofdzaakUuid}",
        ];

        $result = $this->service->rulesZakenCreate($body);

        $this->assertFalse($result['valid']);
        $code = $result['invalidParams'][0]['code'] ?? '';
        $this->assertSame('does-not-exist', $code);

    }//end testHoofdzaakNotFoundReturnsDoesNotExist()


    // -------------------------------------------------------------------------
    // Task 6.4 — Side effect tests (vertrouwelijkheidaanduiding override)
    // -------------------------------------------------------------------------


    /**
     * Test zrc-009: zaaktype vertrouwelijkheidaanduiding overrides incoming value.
     *
     * @return void
     */
    public function testVertrouwelijkheidaanduidingAlwaysOverridesFromZaaktype(): void
    {
        $zaaktypeUuid = 'aabbccdd-1111-2222-3333-444455556666';

        $objectService = $this->createMock(ObjectServiceStub::class);

        // find() returns zaaktype with confidentiality = 'vertrouwelijk'.
        $objectService->method('find')->willReturn([
            'id'            => $zaaktypeUuid,
            'confidentiality' => 'vertrouwelijk',
            'isDraft'       => false,
        ]);

        $objectService->method('buildSearchQuery')->willReturn([]);
        $objectService->method('searchObjectsPaginated')->willReturn(['results' => [], 'total' => 0]);

        $this->settingsService
            ->method('getConfigValue')
            ->willReturnCallback(
                static function (string $key): string {
                    return match ($key) {
                        'case_type_schema' => 'schema-ct',
                        default            => 'schema-x',
                    };
                }
            );

        $this->service->setContext(
            objectService: $objectService,
            mappingConfig: ['sourceRegister' => '1', 'sourceSchema' => '2']
        );

        $body = [
            'zaaktype'                    => "http://example.com/zaaktypen/{$zaaktypeUuid}",
            'bronorganisatie'             => '000000000',
            'vertrouwelijkheidaanduiding' => 'openbaar',
            // Incoming says openbaar, zaaktype says vertrouwelijk.
        ];

        $result = $this->service->rulesZakenCreate($body);

        $enrichedBody = $result['enrichedBody'];
        $this->assertSame('vertrouwelijk', $enrichedBody['vertrouwelijkheidaanduiding']);

    }//end testVertrouwelijkheidaanduidingAlwaysOverridesFromZaaktype()


    /**
     * Test zrc-009: incoming value preserved when zaaktype has no confidentiality.
     *
     * @return void
     */
    public function testVertrouwelijkheidaanduidingFallsBackToIncomingWhenZaaktypeHasNone(): void
    {
        $zaaktypeUuid = 'aabbccdd-1111-2222-3333-444455556666';

        $objectService = $this->createMock(ObjectServiceStub::class);

        // Zaaktype has no confidentiality field.
        $objectService->method('find')->willReturn([
            'id'       => $zaaktypeUuid,
            'isDraft'  => false,
        ]);

        $objectService->method('buildSearchQuery')->willReturn([]);
        $objectService->method('searchObjectsPaginated')->willReturn(['results' => [], 'total' => 0]);

        $this->settingsService
            ->method('getConfigValue')
            ->willReturnCallback(
                static function (string $key): string {
                    return match ($key) {
                        'case_type_schema' => 'schema-ct',
                        default            => 'schema-x',
                    };
                }
            );

        $this->service->setContext(
            objectService: $objectService,
            mappingConfig: ['sourceRegister' => '1', 'sourceSchema' => '2']
        );

        $body = [
            'zaaktype'                    => "http://example.com/zaaktypen/{$zaaktypeUuid}",
            'bronorganisatie'             => '000000000',
            'vertrouwelijkheidaanduiding' => 'intern',
        ];

        $result = $this->service->rulesZakenCreate($body);

        $enrichedBody = $result['enrichedBody'];
        $this->assertSame('intern', $enrichedBody['vertrouwelijkheidaanduiding']);

    }//end testVertrouwelijkheidaanduidingFallsBackToIncomingWhenZaaktypeHasNone()


}//end class
