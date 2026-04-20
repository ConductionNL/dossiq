<?php

/**
 * Procest Doorlooptijd Admin Settings
 *
 * Admin settings page for configuring doorlooptijd tracking and SLA defaults.
 *
 * @category Settings
 * @package  OCA\Procest\Settings
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

namespace OCA\Procest\Settings;

use OCA\Procest\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IAppConfig;
use OCP\Settings\IAdminSettings;

/**
 * Admin settings for doorlooptijd configuration.
 *
 * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#task-17
 */
class DoorlooptijdAdmin implements IAdminSettings
{
    /**
     * Constructor.
     *
     * @param IAppConfig $appConfig App configuration service
     */
    public function __construct(
        private IAppConfig $appConfig,
    ) {
    }//end __construct()

    /**
     * Get the form for the settings page.
     *
     * @return TemplateResponse The settings form template
     *
     * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#task-17
     */
    public function getForm(): TemplateResponse
    {
        $streeftermijn      = $this->appConfig->getValueString(
            Application::APP_ID,
            'doorlooptijd_streeftermijn',
            '30'
        );
        $fatalTermijn       = $this->appConfig->getValueString(
            Application::APP_ID,
            'doorlooptijd_fatal_termijn',
            '60'
        );
        $suspensionStatuses = $this->appConfig->getValueString(
            Application::APP_ID,
            'doorlooptijd_suspension_statuses',
            'suspended,on_hold'
        );

        $parameters = [
            'streeftermijn'      => $streeftermijn,
            'fatalTermijn'       => $fatalTermijn,
            'suspensionStatuses' => $suspensionStatuses,
        ];

        return new TemplateResponse(
            Application::APP_ID,
            'settings/doorlooptijd_admin',
            $parameters,
            ''
        );
    }//end getForm()

    /**
     * Get the priority of this settings form.
     *
     * @return int Priority value (higher = shown first)
     */
    public function getPriority(): int
    {
        return 50;
    }//end getPriority()

    /**
     * Get the section ID for this settings page.
     *
     * @return string The section identifier
     */
    public function getSection(): string
    {
        return 'procest_doorlooptijd';
    }//end getSection()
}//end class
