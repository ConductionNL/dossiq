<?php

/**
 * Procest Application
 *
 * Main application class for the Procest case management app.
 *
 * @category AppInfo
 * @package  OCA\Procest\AppInfo
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

namespace OCA\Procest\AppInfo;

use OCA\OpenRegister\Event\DeepLinkRegistrationEvent;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Procest\Dashboard\CasesOverviewWidget;
use OCA\Procest\Dashboard\DeadlineAlertsWidget;
use OCA\Procest\Dashboard\MyTasksWidget;
use OCA\Procest\Dashboard\OverdueCasesWidget;
use OCA\Procest\Dashboard\StalledCasesWidget;
use OCA\Procest\Dashboard\TaskRemindersWidget;
use OCA\Procest\Dashboard\StartCaseWidget;
use OCA\Procest\Listener\BezwaarAdviceRequestedListener;
use OCA\Procest\Listener\VergunningaanvraagCreatedListener;
use OCA\Procest\Listener\BezwaarDecisionListener;
use OCA\Procest\Listener\BezwaarHearingScheduledListener;
use OCA\Procest\Listener\BezwaarLifecycleListener;
use OCA\Procest\Event\ParafeerTransitionEvent;
use OCA\Procest\Listener\DeepLinkRegistrationListener;
use OCA\Procest\Listener\KpiCacheInvalidationListener;
use OCA\Procest\Listener\ParaferingAuditListener;
use OCA\Procest\Listener\RoleMutationListener;
use OCA\Procest\Listener\VergunningaanvraagCreatedListener;
use OCA\Procest\Mcp\ProcestToolProvider;
use OCA\Procest\Middleware\TenantMiddleware;
use OCA\Procest\Middleware\ZgwAuthMiddleware;
use OCA\Procest\Validator\ParaferingAuditAppendOnlyValidator;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Main application class for the Procest case management app.
 */
class Application extends App implements IBootstrap
{
    public const APP_ID = 'procest';

    /**
     * Constructor for the Application class.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct(appName: self::APP_ID);
    }//end __construct()

    /**
     * Register event listeners and services.
     *
     * @param IRegistrationContext $context The registration context
     *
     * @return void
     */
    public function register(IRegistrationContext $context): void
    {
        $context->registerEventListener(
            event: DeepLinkRegistrationEvent::class,
            listener: DeepLinkRegistrationListener::class
        );

        $context->registerEventListener(
            event: ObjectCreatedEvent::class,
            listener: KpiCacheInvalidationListener::class
        );

        $context->registerEventListener(
            event: ObjectUpdatedEvent::class,
            listener: KpiCacheInvalidationListener::class
        );

        $context->registerEventListener(
            event: ObjectDeletedEvent::class,
            listener: KpiCacheInvalidationListener::class
        );

        // Role-routing cache invalidation on role mutations.
        $context->registerEventListener(
            event: ObjectCreatedEvent::class,
            listener: RoleMutationListener::class
        );
        $context->registerEventListener(
            event: ObjectUpdatedEvent::class,
            listener: RoleMutationListener::class
        );
        $context->registerEventListener(
            event: ObjectDeletedEvent::class,
            listener: RoleMutationListener::class
        );

        $this->registerBezwaarListeners(context: $context);

        // DSO Omgevingsloket: create Procest zaak when a vergunningaanvraag is written.
        $context->registerEventListener(
            event: ObjectCreatedEvent::class,
            listener: VergunningaanvraagCreatedListener::class
        );

        $context->registerMiddleware(class: ZgwAuthMiddleware::class);
        $context->registerMiddleware(class: TenantMiddleware::class);

        $this->registerWidgetsAndProviders(context: $context);
    }//end register()

    /**
     * Register bezwaar-lifecycle and parafering-audit event listeners.
     *
     * @param IRegistrationContext $context The registration context
     *
     * @return void
     */
    private function registerBezwaarListeners(IRegistrationContext $context): void
    {
        // Bezwaar-lifecycle observer — routes bezwaar/hearing/advice/decision
        // events onto the status-transition-engine without duplicating
        // transition logic. See ADR-022 + REQ-BL-8.
        $context->registerEventListener(
            event: ObjectCreatedEvent::class,
            listener: BezwaarLifecycleListener::class
        );
        $context->registerEventListener(
            event: ObjectUpdatedEvent::class,
            listener: BezwaarLifecycleListener::class
        );

        // Parafering audit trail: one listener writes append-only audit entries
        // for every parafeerroute transition (spec parafering-audit-trail).
        $context->registerEventListener(
            event: ParafeerTransitionEvent::class,
            listener: ParaferingAuditListener::class
        );

        // Parafering audit trail: append-only validator blocks UPDATE/DELETE
        // on paraferingAuditEntry objects via OR's pre-save hooks.
        $context->registerEventListener(
            event: ObjectCreatingEvent::class,
            listener: ParaferingAuditAppendOnlyValidator::class
        );
        $context->registerEventListener(
            event: ObjectUpdatingEvent::class,
            listener: ParaferingAuditAppendOnlyValidator::class
        );
        $context->registerEventListener(
            event: ObjectDeletingEvent::class,
            listener: ParaferingAuditAppendOnlyValidator::class
        );

        // Bezwaar-advisory-committee auto-assignment when a bezwaar enters
        // status "Hoorzitting gepland" — listener defers to
        // AdvisoryCommitteeService::autoAssignDefaultCommittee.
        $context->registerEventListener(
            event: ObjectUpdatedEvent::class,
            listener: BezwaarAdviceRequestedListener::class
        );

        // Bezwaar-hearing default-session seeding when a bezwaar enters
        // status "Hoorzitting gepland" — listener defers to
        // HearingService::seedDefaultHearing.
        $context->registerEventListener(
            event: ObjectUpdatedEvent::class,
            listener: BezwaarHearingScheduledListener::class
        );

        // Bezwaar-decision guard: a bezwaar may only enter status
        // "Beslissing op bezwaar" when a published bezwaarDecision
        // exists for it. The listener reverts illegal transitions
        // without bypassing the status-transition-engine.
        $context->registerEventListener(
            event: ObjectUpdatedEvent::class,
            listener: BezwaarDecisionListener::class
        );
    }//end registerBezwaarListeners()

    /**
     * Register dashboard widgets and the MCP tool provider.
     *
     * @param IRegistrationContext $context The registration context
     *
     * @return void
     */
    private function registerWidgetsAndProviders(IRegistrationContext $context): void
    {
        // Dashboard widgets.
        $context->registerDashboardWidget(CasesOverviewWidget::class);
        $context->registerDashboardWidget(MyTasksWidget::class);
        $context->registerDashboardWidget(OverdueCasesWidget::class);
        $context->registerDashboardWidget(DeadlineAlertsWidget::class);
        $context->registerDashboardWidget(TaskRemindersWidget::class);
        $context->registerDashboardWidget(StalledCasesWidget::class);
        $context->registerDashboardWidget(StartCaseWidget::class);

        // Register ProcestToolProvider as the MCP tool provider for the AI Chat
        // Companion. The alias key 'OCA\OpenRegister\Mcp\IMcpToolProvider::procest'
        // is the format that OR's McpToolsService enumerates to discover per-app
        // providers (hydra ADR-034 / ADR-035, design D3). The interface ships in
        // openregister PR #1466 (ai-chat-companion-orchestrator); until it merges
        // procest implements the stub at tests/Stubs/Mcp/IMcpToolProvider.php.
        $context->registerServiceAlias(
            'OCA\\OpenRegister\\Mcp\\IMcpToolProvider::procest',
            ProcestToolProvider::class
        );
    }//end registerWidgetsAndProviders()

    /**
     * Boot the application.
     *
     * @param IBootContext $context The boot context
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function boot(IBootContext $context): void
    {
    }//end boot()
}//end class
