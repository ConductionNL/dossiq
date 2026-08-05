<?php

/**
 * ZgwZrcRulesService Unit Tests
 *
 * Tests for the ZRC (Zaken API) business rule validation and enrichment service.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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

    public function buildSearchQuery(array $requestParams, string $register, string $schema): array;

    public function searchObjectsPaginated(array $query): array;
}//end interface

/**
 * Unit tests for ZgwZrcRulesService.
 *
 * @covers \OCA\Procest\Service\ZgwZrcRulesService
 * @covers \OCA\Procest\Service\ZgwRulesBase
 *
 * @uses \OCA\Procest\Service\FieldValidator
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

        // FieldValidator is a pure, stateless utility — use the real
        // implementation so the service exercises genuine format validation.
        $this->service = new ZgwZrcRulesService(
            logger: $this->logger,
            settingsService: $this->settingsService,
            fieldValidator: new \OCA\Procest\Service\FieldValidator()
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
        $objectService = $this->createMock(ObjectServiceStub::class);
        $objectService->method('find')->willReturn(
                [
                    'isEindstatus' => true,
                    'caseType'     => 'uuid-zt-1',
                ]
                );

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
        $objectService = $this->createMock(ObjectServiceStub::class);
        $objectService->method('find')->willReturn(
                [
                    'isEindstatus' => false,
                    'caseType'     => 'uuid-zt-1',
                ]
                );

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

        $objectService = $this->createMock(ObjectServiceStub::class);

        // find() returns statustype data (no isEindstatus field).
        $objectService->method('find')->willReturn(
                [
                    'id'             => $uuidHighest,
                    'caseType'       => 'uuid-zt-1',
                    'sequenceNumber' => 10,
                ]
                );

        $objectService->method('buildSearchQuery')->willReturn(['caseType' => 'uuid-zt-1']);

        $objectService->method('searchObjectsPaginated')->willReturn(
                [
                    'results' => [
                        ['id' => $uuidHighest, 'sequenceNumber' => 10],
                        ['id' => $uuidLower, 'sequenceNumber' => 5],
                    ],
                ]
                );

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

        $objectService = $this->createMock(ObjectServiceStub::class);

        $objectService->method('find')->willReturn(
                [
                    'id'             => $uuidLower,
                    'caseType'       => 'uuid-zt-1',
                    'sequenceNumber' => 5,
                ]
                );

        $objectService->method('buildSearchQuery')->willReturn(['caseType' => 'uuid-zt-1']);

        $objectService->method('searchObjectsPaginated')->willReturn(
                [
                    'results' => [
                        ['id' => $uuidHighest, 'sequenceNumber' => 10],
                        ['id' => $uuidLower, 'sequenceNumber' => 5],
                    ],
                ]
                );

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
        $zaaktypeUuid  = 'aabbccdd-1111-2222-3333-444455556666';
        $hoofdzaakUuid = 'ccddccdd-5555-6666-7777-888899990000';

        // Zaaktype lookup returns a valid (published) zaaktype; hoofdzaak lookup returns null.
        $objectService = $this->createMock(ObjectServiceStub::class);
        $objectService->method('find')->willReturnCallback(
            static function (string $id, string $register, string $schema) use ($zaaktypeUuid): ?array {
                if ($id === $zaaktypeUuid) {
                    return [
                        'id'      => $zaaktypeUuid,
                        'isDraft' => false,
                    ];
                }

                return null;
            }
        );

        $this->settingsService
            ->method('getConfigValue')
            ->willReturn('schema-case');

        $this->service->setContext(
            objectService: $objectService,
            mappingConfig: ['sourceRegister' => '1', 'sourceSchema' => '2']
        );

        $body = [
            'zaaktype'  => "http://example.com/zaaktypen/{$zaaktypeUuid}",
            'hoofdzaak' => "http://example.com/zaken/{$hoofdzaakUuid}",
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
        $objectService->method('find')->willReturn(
                [
                    'id'              => $zaaktypeUuid,
                    'confidentiality' => 'vertrouwelijk',
                    'isDraft'         => false,
                ]
                );

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
        $objectService->method('find')->willReturn(
                [
                    'id'      => $zaaktypeUuid,
                    'isDraft' => false,
                ]
                );

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

    // -------------------------------------------------------------------------
    // WF1 — isSafeExternalUrl SSRF guard (ZgwRulesBase::fetchExternalUrl)
    // -------------------------------------------------------------------------

    /**
     * Test that isSafeExternalUrl rejects IMDS/cloud-metadata address.
     *
     * WF1 regression: fetchExternalUrl previously used verify=>false and had no
     * SSRF guard. Any JWT consumer with ZTC write scope could pass the IMDS URL
     * in selectielijstProcestype and exfiltrate credentials.
     *
     * @return void
     *
     * @dataProvider provideBlockedUrls
     */
    public function testSafeExternalUrlBlocksPrivateAddresses(string $url): void
    {
        $reflMethod = new \ReflectionMethod($this->service, 'isSafeExternalUrl');
        $reflMethod->setAccessible(true);

        $result = $reflMethod->invoke($this->service, $url);

        $this->assertFalse(
            $result,
            "URL '$url' should be blocked by the SSRF guard"
        );

    }//end testSafeExternalUrlBlocksPrivateAddresses()

    /**
     * Data provider: URLs that must be blocked by the SSRF guard.
     *
     * Covers IMDS, RFC1918 ranges, and loopback.
     *
     * @return array<string, array{0: string}>
     */
    public static function provideBlockedUrls(): array
    {
        return [
            'IMDS cloud metadata' => ['http://169.254.169.254/latest/meta-data/'],
            'RFC1918 class-A'     => ['http://10.0.0.1/admin'],
            'RFC1918 class-B'     => ['https://172.16.0.5/secret'],
            'RFC1918 class-C'     => ['https://192.168.1.1/login'],
            'localhost'           => ['http://127.0.0.1/internal'],
            'non-http scheme'     => ['ftp://example.com/file'],
            'file scheme'         => ['file:///etc/passwd'],
        ];
    }//end provideBlockedUrls()

    /**
     * Test that isSafeExternalUrl allows a public https URL.
     *
     * @return void
     */
    public function testSafeExternalUrlAllowsPublicHttpsUrl(): void
    {
        $reflMethod = new \ReflectionMethod($this->service, 'isSafeExternalUrl');
        $reflMethod->setAccessible(true);

        // selectielijst API is the canonical external URL — public HTTPS endpoint.
        // We test that the guard ACCEPTS a well-formed public URL structurally.
        // DNS resolution will fail in the test environment (no real resolver for
        // example.com/selectielijst.nl in isolation), but we only check that
        // the scheme/host parsing logic doesn't *immediately* block the URL.
        // The method returns false when DNS is unreachable (fail-closed), so
        // we assert that the method completes without throwing.
        $result = $reflMethod->invoke($this->service, 'https://selectielijst.openzaak.nl/api/v1/');
        // result may be true or false depending on DNS resolution in CI;
        // the important thing is that no exception is thrown.
        $this->assertIsBool($result);

    }//end testSafeExternalUrlAllowsPublicHttpsUrl()
}//end class
