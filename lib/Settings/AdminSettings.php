<?php

/**
 * Procest Admin Settings
 *
 * Provides the admin settings form for the Procest application.
 *
 * @category Settings
 * @package  OCA\Procest\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Settings;

use OCA\Procest\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Settings\ISettings;

/**
 * Provides the admin settings form for the Procest application.
 */
class AdminSettings implements ISettings
{
    /**
     * Constructor.
     *
     * @param IAppManager   $appManager   The app manager.
     * @param IInitialState $initialState The initial state service.
     */
    public function __construct(
        private IAppManager $appManager,
        private IInitialState $initialState,
    ) {
    }//end __construct()

    /**
     * Get the settings form template.
     *
     * @return TemplateResponse
     */
    public function getForm(): TemplateResponse
    {
        $version = $this->appManager->getAppVersion(appId: Application::APP_ID);

        $this->initialState->provideInitialState('version', $version);

        return new TemplateResponse(
            Application::APP_ID,
            'settings/admin',
            []
        );
    }//end getForm()

    /**
     * Get the section ID this settings page belongs to.
     *
     * @return string
     */
    public function getSection(): string
    {
        return 'procest';
    }//end getSection()

    /**
     * Get the priority for ordering within the section.
     *
     * @return int
     */
    public function getPriority(): int
    {
        return 10;
    }//end getPriority()
}//end class
