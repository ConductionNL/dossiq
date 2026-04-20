<?php

/**
 * Overdue Cases Count Dashboard Widget
 *
 * Displays count of overdue cases by SLA status.
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
 * Dashboard widget showing count of overdue cases.
 *
 * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#task-11
 */
class OverdueCountWidget implements IWidget
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
     * @spec       openspec/changes/doorlooptijd-dashboard/tasks.md#task-11
     */
    public function getId(): string
    {
        return 'procest_overdue_count_widget';

    }//end getId()

    /**
     * Get the display title for this widget.
     *
     * @inheritDoc
     * @return     string The widget title
     * @spec       openspec/changes/doorlooptijd-dashboard/tasks.md#task-11
     */
    public function getTitle(): string
    {
        return $this->l10n->t('Overdue Cases');

    }//end getTitle()

    /**
     * Get the display order for this widget.
     *
     * @inheritDoc
     * @return     int The widget order
     * @spec       openspec/changes/doorlooptijd-dashboard/tasks.md#task-11
     */
    public function getOrder(): int
    {
        return 40;

    }//end getOrder()

    /**
     * Get the CSS icon class for this widget.
     *
     * @inheritDoc
     * @return     string The icon CSS class
     * @spec       openspec/changes/doorlooptijd-dashboard/tasks.md#task-11
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
     * @spec       openspec/changes/doorlooptijd-dashboard/tasks.md#task-11
     */
    public function getUrl(): ?string
    {
        return $this->url->linkToRouteAbsolute(
            Application::APP_ID.'.reporting.get_report'
        );

    }//end getUrl()

    /**
     * Load the widget scripts and styles.
     *
     * @inheritDoc
     * @return     void
     * @spec       openspec/changes/doorlooptijd-dashboard/tasks.md#task-11
     *
     * @SuppressWarnings(PHPMD.StaticAccess) — Nextcloud Util API is static by design
     */
    public function load(): void
    {
        Util::addScript(Application::APP_ID, Application::APP_ID.'-overdueCountWidget');
        Util::addStyle(Application::APP_ID, 'dashboardWidgets');

    }//end load()
}//end class
