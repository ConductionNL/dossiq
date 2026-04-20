<?php

/**
 * Task Reminders Dashboard Widget
 *
 * Displays tasks approaching or past their due date
 * in the Nextcloud Dashboard.
 *
 * @category Dashboard
 * @package  OCA\Procest\Dashboard
 *
 * @author    Conduction Development Team <info@conduction.nl>
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
 * Dashboard widget showing task due reminders.
 */
class TaskRemindersWidget implements IWidget
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
     * Get the unique identifier for this widget.
     *
     * @inheritDoc
     * @return     string The widget identifier
     */
    public function getId(): string
    {
        return 'procest_task_reminders_widget';

    }//end getId()

    /**
     * Get the display title for this widget.
     *
     * @inheritDoc
     * @return     string The widget title
     */
    public function getTitle(): string
    {
        return $this->l10n->t('Task Reminders');

    }//end getTitle()

    /**
     * Get the display order for this widget.
     *
     * @inheritDoc
     * @return     int The widget order
     */
    public function getOrder(): int
    {
        return 12;

    }//end getOrder()

    /**
     * Get the CSS icon class for this widget.
     *
     * @inheritDoc
     * @return     string The icon CSS class
     */
    public function getIconClass(): string
    {
        return 'icon-procest-widget';

    }//end getIconClass()

    /**
     * Get the URL for the widget's full view.
     *
     * @inheritDoc
     * @return     string|null The widget URL or null
     */
    public function getUrl(): ?string
    {
        return $this->url->linkToRouteAbsolute(Application::APP_ID.'.dashboard.index');

    }//end getUrl()

    /**
     * Load the widget scripts and styles.
     *
     * @inheritDoc
     * @return     void
     *
     * @SuppressWarnings(PHPMD.StaticAccess) — Nextcloud Util API is static by design
     */
    public function load(): void
    {
        Util::addScript(Application::APP_ID, Application::APP_ID.'-taskRemindersWidget');
        Util::addStyle(Application::APP_ID, 'dashboardWidgets');

    }//end load()
}//end class
