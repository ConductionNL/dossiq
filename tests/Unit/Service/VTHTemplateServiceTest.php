<?php

/**
 * VTHTemplateService Unit Tests
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
 * @spec openspec/changes/vth-module/tasks.md#task-2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\VTHTemplateService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for VTHTemplateService.
 *
 * @covers \OCA\Procest\Service\VTHTemplateService
 */
class VTHTemplateServiceTest extends TestCase
{

    /**
     * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
     */
    private SettingsService $settingsService;

    /**
     * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private LoggerInterface $logger;

    /**
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
     * Test that listTemplates returns an array.
     *
     * @return void
     *
     * @spec openspec/changes/vth-module/tasks.md#task-2
     */
    public function testListTemplatesReturnsArray(): void
    {
        $templates = $this->service->listTemplates();

        $this->assertIsArray($templates);
    }//end testListTemplatesReturnsArray()

    /**
     * Test that listTemplates includes shipped VTH templates.
     *
     * @return void
     *
     * @spec openspec/changes/vth-module/tasks.md#task-2
     */
    public function testListTemplatesIncludesVthTemplates(): void
    {
        $templates = $this->service->listTemplates();

        $ids = array_column($templates, 'id');

        $this->assertContains(needle: 'vth-omgevingsvergunning', haystack: $ids);
        $this->assertContains(needle: 'vth-toezichtzaak', haystack: $ids);
        $this->assertContains(needle: 'vth-handhavingszaak', haystack: $ids);
    }//end testListTemplatesIncludesVthTemplates()

    /**
     * Test that activateTemplate throws on unknown slug.
     *
     * @return void
     *
     * @spec openspec/changes/vth-module/tasks.md#task-2
     */
    public function testActivateThrowsOnUnknownSlug(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('VTH template not found');

        $this->service->activateTemplate(slug: 'vth-does-not-exist');
    }//end testActivateThrowsOnUnknownSlug()

    /**
     * Test that activateTemplate throws when OpenRegister unavailable.
     *
     * @return void
     *
     * @spec openspec/changes/vth-module/tasks.md#task-2
     */
    public function testActivateThrowsWhenOpenRegisterUnavailable(): void
    {
        $this->settingsService
            ->method('getObjectService')
            ->willReturn(null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OpenRegister is not available');

        $this->service->activateTemplate(slug: 'vth-omgevingsvergunning');
    }//end testActivateThrowsWhenOpenRegisterUnavailable()

    /**
     * Test that each template metadata has required fields.
     *
     * @return void
     *
     * @spec openspec/changes/vth-module/tasks.md#task-2
     */
    public function testTemplateMetadataHasRequiredFields(): void
    {
        $templates = $this->service->listTemplates();

        foreach ($templates as $template) {
            $this->assertArrayHasKey(key: 'id', array: $template, message: 'Template missing id');
            $this->assertArrayHasKey(key: 'title', array: $template, message: 'Template missing title');
            $this->assertArrayHasKey(key: 'category', array: $template, message: 'Template missing category');
            $this->assertSame(expected: 'vth', actual: $template['category']);
        }
    }//end testTemplateMetadataHasRequiredFields()
}//end class
