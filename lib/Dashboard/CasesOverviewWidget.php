<?php

/**
 * Cases Overview Dashboard Widget
 *
 * Displays a list of recent open cases in the Nextcloud Dashboard.
 *
 * @category Dashboard
 * @package  OCA\Procest\Dashboard
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

namespace OCA\Procest\Dashboard;

use OCA\Procest\AppInfo\Application;
use OCP\Dashboard\IWidget;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Util;

/**
 * Dashboard widget showing an overview of recent cases.
 */
class CasesOverviewWidget implements IWidget
{


    /**
     * Constructor.
     *
     * @param IL10N         $l10n L10N service
     * @param IURLGenerator $url  URL generator
     */
    public function __construct(
        private IL10N $l10n,
        private IURLGenerator $url
    ) {
    }//end __construct()


    /**
     * @inheritDoc
     */
    public function getId(): string
    {
        return 'procest_cases_overview_widget';

    }//end getId()


    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return $this->l10n->t('Cases overview');

    }//end getTitle()


    /**
     * @inheritDoc
     */
    public function getOrder(): int
    {
        return 10;

    }//end getOrder()


    /**
     * @inheritDoc
     */
    public function getIconClass(): string
    {
        return 'icon-procest-widget';

    }//end getIconClass()


    /**
     * @inheritDoc
     */
    public function getUrl(): ?string
    {
        return null;

    }//end getUrl()


    /**
     * @inheritDoc
     */
    public function load(): void
    {
        Util::addScript(Application::APP_ID, Application::APP_ID . '-casesOverviewWidget');
        Util::addStyle(Application::APP_ID, 'dashboardWidgets');

    }//end load()


}//end class
