<?php

/**
 * Procest Email Settings
 *
 * Admin settings section for SMTP/IMAP email integration configuration.
 * Exposes transport choice, credentials, polling intervals, and a
 * test-connection action via the standard Nextcloud settings panel.
 *
 * @category Settings
 * @package  OCA\Procest\Settings
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

namespace OCA\Procest\Settings;

use OCA\Procest\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;

/**
 * Admin settings page for the Procest email integration.
 *
 * @spec openspec/changes/case-email-integration/tasks.md#task-T02
 */
class EmailSettings implements ISettings
{
    /**
     * Get the settings form template.
     *
     * The SPA router renders the actual email-settings form inside the Vue
     * application; this template response simply bootstraps the app shell
     * at the correct route (src/views/settings/EmailSettings.vue is loaded
     * by the Vue router when the admin navigates to the email section).
     *
     * @return TemplateResponse
     *
     * @spec openspec/changes/case-email-integration/tasks.md#task-T02
     */
    public function getForm(): TemplateResponse
    {
        return new TemplateResponse(
            Application::APP_ID,
            'settings/admin',
            ['section' => 'email'],
        );
    }//end getForm()

    /**
     * Get the section ID this settings page belongs to.
     *
     * @return string
     *
     * @spec openspec/changes/case-email-integration/tasks.md#task-T02
     */
    public function getSection(): string
    {
        return 'procest';
    }//end getSection()

    /**
     * Get the display priority within the section (lower = higher).
     *
     * Email settings render after the general settings (priority 10).
     *
     * @return int
     *
     * @spec openspec/changes/case-email-integration/tasks.md#task-T02
     */
    public function getPriority(): int
    {
        return 20;
    }//end getPriority()
}//end class
