<?php

/**
 * EmailSettings Unit Tests
 *
 * Tests for the Procest EmailSettings admin settings panel integration.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/case-email-integration/tasks.md#task-T02
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Settings;

use OCA\Procest\Settings\EmailSettings;
use OCP\AppFramework\Http\TemplateResponse;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for EmailSettings.
 *
 * @covers \OCA\Procest\Settings\EmailSettings
 */
class EmailSettingsTest extends TestCase
{

    /**
     * The settings instance under test.
     *
     * @var EmailSettings
     */
    private EmailSettings $settings;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->settings = new EmailSettings();

    }//end setUp()

    /**
     * Test that getSection returns 'procest'.
     *
     * @return void
     */
    public function testGetSectionReturnsProcest(): void
    {
        $this->assertSame('procest', $this->settings->getSection());

    }//end testGetSectionReturnsProcest()

    /**
     * Test that getPriority returns a positive integer.
     *
     * @return void
     */
    public function testGetPriorityReturnsPositiveInteger(): void
    {
        $priority = $this->settings->getPriority();
        $this->assertIsInt($priority);
        $this->assertGreaterThan(0, $priority);

    }//end testGetPriorityReturnsPositiveInteger()

    /**
     * Test that getPriority returns 20 (email renders after general settings at 10).
     *
     * @return void
     */
    public function testGetPriorityReturns20(): void
    {
        $this->assertSame(20, $this->settings->getPriority());

    }//end testGetPriorityReturns20()

    /**
     * Test that getForm returns a TemplateResponse instance.
     *
     * @return void
     */
    public function testGetFormReturnsTemplateResponse(): void
    {
        $form = $this->settings->getForm();
        $this->assertInstanceOf(TemplateResponse::class, $form);

    }//end testGetFormReturnsTemplateResponse()

    /**
     * Test that the TemplateResponse uses the correct template name.
     *
     * @return void
     */
    public function testGetFormUsesAdminTemplate(): void
    {
        $form = $this->settings->getForm();
        // TemplateResponse::getTemplateName() is public API.
        $this->assertSame('settings/admin', $form->getTemplateName());

    }//end testGetFormUsesAdminTemplate()

    /**
     * Test that the TemplateResponse params include the 'email' section key.
     *
     * @return void
     */
    public function testGetFormPassesSectionParamAsEmail(): void
    {
        $form   = $this->settings->getForm();
        $params = $form->getParams();
        $this->assertArrayHasKey('section', $params);
        $this->assertSame('email', $params['section']);

    }//end testGetFormPassesSectionParamAsEmail()
}//end class
