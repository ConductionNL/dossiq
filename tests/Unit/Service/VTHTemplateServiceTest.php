<?php

/**
 * VTHTemplateService Unit Tests
 *
 * Tests for the VTH zaaktype template loader and activator service.
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
 * @spec openspec/changes/vth-module/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\VTHTemplateService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for the VTHTemplateService class.
 *
 * @covers \OCA\Procest\Service\VTHTemplateService
 */
class VTHTemplateServiceTest extends TestCase
{

    /**
     * The mocked settings service.
     *
     * @var SettingsService|MockObject
     */
    private SettingsService $settingsService;

    /**
     * The mocked logger.
     *
     * @var LoggerInterface|MockObject
     */
    private LoggerInterface $logger;

    /**
     * The service under test.
     *
     * @var VTHTemplateService
     */
    private VTHTemplateService $service;


    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->logger          = $this->createMock(LoggerInterface::class);

        $this->service = new VTHTemplateService(
            settingsService: $this->settingsService,
            logger: $this->logger,
        );

    }//end setUp()


    /**
     * Test that listTemplates() returns an array.
     *
     * @return void
     */
    public function testListTemplatesReturnsArray(): void
    {
        $templates = $this->service->listTemplates();

        self::assertIsArray($templates);

    }//end testListTemplatesReturnsArray()


    /**
     * Test that listTemplates() includes the vth-omgevingsvergunning template.
     *
     * @return void
     */
    public function testListTemplatesIncludesVthOmgevingsvergunning(): void
    {
        $templates = $this->service->listTemplates();
        $slugs     = array_column($templates, 'slug');

        self::assertContains('vth-omgevingsvergunning', $slugs);

    }//end testListTemplatesIncludesVthOmgevingsvergunning()


    /**
     * Test that listTemplates() includes the vth-toezichtzaak template.
     *
     * @return void
     */
    public function testListTemplatesIncludesVthToezichtzaak(): void
    {
        $templates = $this->service->listTemplates();
        $slugs     = array_column($templates, 'slug');

        self::assertContains('vth-toezichtzaak', $slugs);

    }//end testListTemplatesIncludesVthToezichtzaak()


    /**
     * Test that listTemplates() includes the vth-handhavingszaak template.
     *
     * @return void
     */
    public function testListTemplatesIncludesVthHandhavingszaak(): void
    {
        $templates = $this->service->listTemplates();
        $slugs     = array_column($templates, 'slug');

        self::assertContains('vth-handhavingszaak', $slugs);

    }//end testListTemplatesIncludesVthHandhavingszaak()


    /**
     * Test that each template entry contains the required metadata keys.
     *
     * @return void
     */
    public function testListTemplatesEachEntryHasRequiredKeys(): void
    {
        $templates = $this->service->listTemplates();

        foreach ($templates as $template) {
            self::assertArrayHasKey('slug', $template, 'Template must have slug');
            self::assertArrayHasKey('title', $template, 'Template must have title');
            self::assertArrayHasKey('description', $template, 'Template must have description');
            self::assertArrayHasKey('version', $template, 'Template must have version');
            self::assertArrayHasKey('file', $template, 'Template must have file');
        }

    }//end testListTemplatesEachEntryHasRequiredKeys()


    /**
     * Test that activateTemplate() throws when the template slug does not exist.
     *
     * @return void
     */
    public function testActivateTemplateThrowsWhenTemplateNotFound(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('VTH template not found');

        $this->service->activateTemplate(slug: 'non-existent-template');

    }//end testActivateTemplateThrowsWhenTemplateNotFound()


    /**
     * Test that activateTemplate() throws when OpenRegister is unavailable.
     *
     * @return void
     */
    public function testActivateTemplateThrowsWhenOpenRegisterUnavailable(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OpenRegister is not available');

        $this->settingsService->method('getObjectService')->willReturn(null);

        $this->service->activateTemplate(slug: 'vth-omgevingsvergunning');

    }//end testActivateTemplateThrowsWhenOpenRegisterUnavailable()


    /**
     * Test that listTemplates() returns at least three templates (the three shipped VTH types).
     *
     * @return void
     */
    public function testListTemplatesContainsAtLeastThreeEntries(): void
    {
        $templates = $this->service->listTemplates();

        self::assertGreaterThanOrEqual(3, count($templates));

    }//end testListTemplatesContainsAtLeastThreeEntries()


}//end class
