<?php

/**
 * PublicationService Unit Tests
 *
 * Tests the DROP/LVBB dispatcher's branches that do not require live HTTP:
 * publicationRequired=false skip, missing decision, and unconfigured endpoint.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\PublicationService;
use OCA\Procest\Service\SettingsService;
use OCP\Http\Client\IClientService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * ObjectService stub for PublicationService.
 */
interface PublicationObjectServiceStub
{
    /**
     * Find a single object.
     *
     * @param string $id       The object id.
     * @param string $register The register slug.
     * @param string $schema   The schema id.
     *
     * @return array
     */
    public function find(string $id, string $register, string $schema): array;

    /**
     * Save or update an object.
     *
     * @param string $register The register slug.
     * @param string $schema   The schema id.
     * @param array  $object   The object payload.
     * @param string $id       Optional id for update.
     *
     * @return array
     */
    public function saveObject(string $register, string $schema, array $object, string $id=''): array;

    /**
     * Find objects.
     *
     * @param array $params The query params.
     *
     * @return array
     */
    public function findAll(array $params=[]): array;
}//end interface

/**
 * Unit tests for PublicationService.
 *
 * @covers \OCA\Procest\Service\PublicationService
 */
class PublicationServiceTest extends TestCase
{
    /**
     * The mocked settings service.
     *
     * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
     */
    private SettingsService $settingsService;

    /**
     * The mocked HTTP client service.
     *
     * @var IClientService|\PHPUnit\Framework\MockObject\MockObject
     */
    private IClientService $clientService;

    /**
     * The service under test.
     *
     * @var PublicationService
     */
    private PublicationService $service;

    /**
     * Set up fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->clientService   = $this->createMock(IClientService::class);
        $logger                = $this->createMock(LoggerInterface::class);

        $this->service = new PublicationService($this->settingsService, $this->clientService, $logger);
    }//end setUp()

    /**
     * Common schema-config callback.
     *
     * @return void
     */
    private function configureSchemas(): void
    {
        $this->settingsService->method('getConfigValue')->willReturnCallback(
            static function (string $key, string $default=''): string {
                if ($key === 'register') {
                    return 'reg';
                }

                if (in_array($key, ['drop_lvbb_endpoint', 'drop_lvbb_token', 'mandaatregister_endpoint'], true) === true) {
                    return '';
                }

                return 'schema-'.$key;
            },
        );
    }//end configureSchemas()

    /**
     * A case whose caseType has publicationRequired=false is skipped, no dispatch.
     *
     * @return void
     */
    public function testSkipsWhenPublicationNotRequired(): void
    {
        $this->configureSchemas();

        $objectService = $this->createMock(PublicationObjectServiceStub::class);
        $objectService->method('find')->willReturnCallback(
            static function (string $id, string $register, string $schema): array {
                if ($schema === 'schema-case_type_schema') {
                    return ['id' => 'ct1', 'publicationRequired' => false];
                }

                return ['id' => 'c1', 'caseType' => 'ct1'];
            },
        );

        $this->settingsService->method('getObjectService')->willReturn($objectService);
        $this->clientService->expects($this->never())->method('newClient');

        $result = $this->service->dispatch('c1');
        $this->assertTrue($result['ok']);
        $this->assertTrue($result['skipped']);
    }//end testSkipsWhenPublicationNotRequired()

    /**
     * When publication is required but no decision exists, returns no_decision.
     *
     * @return void
     */
    public function testReturnsNoDecisionWhenMissing(): void
    {
        $this->configureSchemas();

        $objectService = $this->createMock(PublicationObjectServiceStub::class);
        $objectService->method('find')->willReturnCallback(
            static function (string $id, string $register, string $schema): array {
                if ($schema === 'schema-case_type_schema') {
                    return ['id' => 'ct1', 'publicationRequired' => true];
                }

                return ['id' => 'c1', 'caseType' => 'ct1'];
            },
        );
        $objectService->method('findAll')->willReturn(['results' => []]);

        $this->settingsService->method('getObjectService')->willReturn($objectService);

        $result = $this->service->dispatch('c1');
        $this->assertFalse($result['ok']);
        $this->assertSame('no_decision', $result['error']);
    }//end testReturnsNoDecisionWhenMissing()

    /**
     * When the endpoint is unconfigured, dispatch reports not_configured and logs a failure.
     *
     * @return void
     */
    public function testReturnsNotConfiguredWhenEndpointMissing(): void
    {
        $this->configureSchemas();

        $objectService = $this->createMock(PublicationObjectServiceStub::class);
        $objectService->method('find')->willReturnCallback(
            static function (string $id, string $register, string $schema): array {
                if ($schema === 'schema-case_type_schema') {
                    return ['id' => 'ct1', 'publicationRequired' => true];
                }

                return ['id' => 'c1', 'caseType' => 'ct1'];
            },
        );
        $objectService->method('findAll')->willReturn(['results' => [['id' => 'd1', 'title' => 'Besluit']]]);
        $objectService->method('saveObject')->willReturn([]);

        $this->settingsService->method('getObjectService')->willReturn($objectService);
        $this->clientService->expects($this->never())->method('newClient');

        $result = $this->service->dispatch('c1');
        $this->assertFalse($result['ok']);
        $this->assertSame('not_configured', $result['error']);
    }//end testReturnsNotConfiguredWhenEndpointMissing()
}//end class
