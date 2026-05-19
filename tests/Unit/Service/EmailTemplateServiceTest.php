<?php

/**
 * EmailTemplateService Unit Tests
 *
 * Tests for the Procest EmailTemplateService template lifecycle management.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/case-email-integration/tasks.md#task-T04
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\EmailTemplateService;
use OCA\Procest\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for EmailTemplateService.
 *
 * @covers \OCA\Procest\Service\EmailTemplateService
 */
class EmailTemplateServiceTest extends TestCase
{

    /**
     * The mocked settings service.
     *
     * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
     */
    private SettingsService $settingsService;

    /**
     * The mocked logger.
     *
     * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private LoggerInterface $logger;

    /**
     * The service under test.
     *
     * @var EmailTemplateService
     */
    private EmailTemplateService $service;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->logger          = $this->createMock(LoggerInterface::class);

        $this->service = new EmailTemplateService(
            $this->settingsService,
            $this->logger,
        );

    }//end setUp()

    /**
     * Test that getAvailableVariables returns a grouped catalog with expected keys.
     *
     * @return void
     */
    public function testGetAvailableVariablesReturnsGroupedCatalog(): void
    {
        $vars = $this->service->getAvailableVariables();
        $this->assertArrayHasKey('case', $vars);
        $this->assertArrayHasKey('contact', $vars);
        $this->assertArrayHasKey('caseType', $vars);
        $this->assertArrayHasKey('zaakNummer', $vars['case']);

    }//end testGetAvailableVariablesReturnsGroupedCatalog()

    /**
     * Test that listTemplates returns empty array when OpenRegister is unavailable.
     *
     * @return void
     */
    public function testListTemplatesReturnsEmptyWhenOpenRegisterUnavailable(): void
    {
        $this->settingsService->method('getObjectService')->willReturn(null);
        $result = $this->service->listTemplates(caseTypeId: 'test-uuid');
        $this->assertSame([], $result);

    }//end testListTemplatesReturnsEmptyWhenOpenRegisterUnavailable()

    /**
     * Test that listTemplates returns empty array when schema config is empty.
     *
     * @return void
     */
    public function testListTemplatesReturnsEmptyWhenSchemaNotConfigured(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->settingsService->method('getObjectService')->willReturn($objectService);
        $this->settingsService->method('getConfigValue')->willReturn('');

        $result = $this->service->listTemplates(caseTypeId: 'test-uuid');
        $this->assertSame([], $result);

    }//end testListTemplatesReturnsEmptyWhenSchemaNotConfigured()

    /**
     * Test that listTemplates returns found templates from OpenRegister.
     *
     * @return void
     */
    public function testListTemplatesReturnsSavedTemplates(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->settingsService->method('getObjectService')->willReturn($objectService);
        $this->settingsService->method('getConfigValue')->willReturnMap(
            [
                ['register', '', 'procest'],
                ['email_template_schema', '', 'emailTemplate'],
                ['register', 'procest'],
                ['email_template_schema', 'emailTemplate'],
            ]
        );

        $expected = [['id' => 'uuid-1', 'name' => 'Ontvangst', 'isActive' => true]];
        // phpcs:disable CustomSn.Functions.NamedParameters
        $objectService->method('findObjects')->willReturn($expected);
        // phpcs:enable CustomSn.Functions.NamedParameters

        $result = $this->service->listTemplates(caseTypeId: 'casetype-uuid');
        $this->assertSame($expected, $result);

    }//end testListTemplatesReturnsSavedTemplates()

    /**
     * Test that createTemplate throws RuntimeException when OpenRegister unavailable.
     *
     * @return void
     */
    public function testCreateTemplateThrowsWhenOpenRegisterUnavailable(): void
    {
        $this->settingsService->method('getObjectService')->willReturn(null);
        $this->expectException(\RuntimeException::class);
        $this->service->createTemplate(
            caseTypeId: 'uuid',
            data: ['name' => 'Test', 'subject' => 'S', 'body' => 'B'],
        );

    }//end testCreateTemplateThrowsWhenOpenRegisterUnavailable()

    /**
     * Test that createTemplate persists and returns the saved object.
     *
     * @return void
     */
    public function testCreateTemplatePersistsAndReturnsObject(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->settingsService->method('getObjectService')->willReturn($objectService);
        $this->settingsService->method('getConfigValue')->willReturn('somevalue');

        $saved = ['id' => 'new-uuid', 'name' => 'Test', 'version' => 1];
        // phpcs:disable CustomSn.Functions.NamedParameters
        $objectService->method('saveObject')->willReturn($saved);
        // phpcs:enable CustomSn.Functions.NamedParameters

        $result = $this->service->createTemplate(
            caseTypeId: 'casetype-uuid',
            data: ['name' => 'Test', 'subject' => 'Subject', 'body' => 'Body {{naam}}'],
        );

        $this->assertSame('new-uuid', $result['id']);
        $this->assertSame(1, $result['version']);

    }//end testCreateTemplatePersistsAndReturnsObject()

    /**
     * Test that updateTemplate throws RuntimeException when template not found.
     *
     * @return void
     */
    public function testUpdateTemplateThrowsWhenTemplateNotFound(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->settingsService->method('getObjectService')->willReturn($objectService);
        $this->settingsService->method('getConfigValue')->willReturn('somevalue');
        // phpcs:disable CustomSn.Functions.NamedParameters
        $objectService->method('findObject')->willReturn(null);
        // phpcs:enable CustomSn.Functions.NamedParameters

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Email template not found');
        $this->service->updateTemplate(
            templateId: 'nonexistent',
            data: ['body' => 'New body'],
        );

    }//end testUpdateTemplateThrowsWhenTemplateNotFound()

    /**
     * Test that updateTemplate creates a new version and marks old one inactive.
     *
     * @return void
     */
    public function testUpdateTemplateCreatesNewVersion(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->settingsService->method('getObjectService')->willReturn($objectService);
        $this->settingsService->method('getConfigValue')->willReturn('somevalue');

        $existing = [
            'id'       => 'old-uuid',
            'name'     => 'Old Template',
            'subject'  => 'Old Subject',
            'body'     => 'Old body',
            'caseType' => 'casetype-uuid',
            'version'  => 1,
            'isActive' => true,
        ];

        $newVersion = array_merge($existing, ['id' => 'new-uuid', 'version' => 2, 'isActive' => true]);

        // phpcs:disable CustomSn.Functions.NamedParameters
        $objectService->method('findObject')->willReturn($existing);
        $objectService->method('saveObject')->willReturn($newVersion);
        // phpcs:enable CustomSn.Functions.NamedParameters

        $result = $this->service->updateTemplate(
            templateId: 'old-uuid',
            data: ['body' => 'Updated body'],
        );

        $this->assertSame(2, $result['version']);

    }//end testUpdateTemplateCreatesNewVersion()

    /**
     * Test that seedDefaultTemplates skips when templates already exist for a case type.
     *
     * @return void
     */
    public function testSeedDefaultTemplatesSkipsWhenTemplatesExist(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->settingsService->method('getObjectService')->willReturn($objectService);
        $this->settingsService->method('getConfigValue')->willReturn('somevalue');

        // Already has templates.
        // phpcs:disable CustomSn.Functions.NamedParameters
        $objectService->method('findObjects')->willReturn([['id' => 'existing']]);

        // saveObject should NOT be called.
        $objectService->expects($this->never())->method('saveObject');
        // phpcs:enable CustomSn.Functions.NamedParameters

        $this->service->seedDefaultTemplates(caseTypeId: 'some-casetype');

    }//end testSeedDefaultTemplatesSkipsWhenTemplatesExist()

    /**
     * Test that seedDefaultTemplates creates default templates when none exist.
     *
     * @return void
     */
    public function testSeedDefaultTemplatesCreatesDefaultsWhenNoneExist(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->settingsService->method('getObjectService')->willReturn($objectService);
        $this->settingsService->method('getConfigValue')->willReturn('somevalue');

        // phpcs:disable CustomSn.Functions.NamedParameters
        $objectService->method('findObjects')->willReturn([]);
        $objectService->method('saveObject')->willReturn(['id' => 'new-uuid']);
        // phpcs:enable CustomSn.Functions.NamedParameters

        // saveObject should be called (3 default templates).
        $objectService->expects($this->exactly(3))->method('saveObject');

        $this->service->seedDefaultTemplates(caseTypeId: 'some-casetype');

    }//end testSeedDefaultTemplatesCreatesDefaultsWhenNoneExist()

    /**
     * Build a minimal object-service mock with no-op save and find methods.
     *
     * @return object
     */
    private function createObjectServiceMock(): object
    {
        $mock = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['findObjects', 'findObject', 'saveObject'])
            ->getMock();
        // phpcs:disable CustomSn.Functions.NamedParameters
        $mock->method('saveObject')->willReturn(['id' => 'new-uuid']);
        $mock->method('findObject')->willReturn(null);
        $mock->method('findObjects')->willReturn([]);
        // phpcs:enable CustomSn.Functions.NamedParameters
        return $mock;

    }//end createObjectServiceMock()
}//end class
