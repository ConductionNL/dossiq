<?php

/**
 * Procest AppHost engine registrar.
 *
 * Owns the single call into OpenRegister's published AppHost entry point
 * (ADR-040) and the manifest of what procest hands to it: the seven dashboard
 * widgets and the MCP tool provider. Split out of Application so the widget and
 * provider class references — nine of them — live next to the one call that
 * consumes them instead of inflating the bootstrap class's coupling.
 *
 * @category AppInfo
 * @package  OCA\Procest\AppInfo\Registrar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/beschikking-generatie/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\AppInfo\Registrar;

use OCA\OpenRegister\AppHost\Bootstrap;
use OCA\Procest\AppInfo\Application;
use OCA\Procest\AppInfo\OpenRegisterAutoloader;
use OCA\Procest\Dashboard\CasesOverviewWidget;
use OCA\Procest\Dashboard\DeadlineAlertsWidget;
use OCA\Procest\Dashboard\MyTasksWidget;
use OCA\Procest\Dashboard\OverdueCasesWidget;
use OCA\Procest\Dashboard\StalledCasesWidget;
use OCA\Procest\Dashboard\StartCaseWidget;
use OCA\Procest\Dashboard\TaskRemindersWidget;
use OCA\Procest\Mcp\ProcestToolProvider;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the OpenRegister AppHost engine for procest.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/beschikking-generatie/spec.md
 */
class AppHostRegistrar
{
    /**
     * Register the OpenRegister AppHost engine (ADR-040).
     *
     * Aliases the mechanical plumbing classes to the shared generics and
     * registers the manifest-driven deep-link listener + the observability
     * (health / metrics) controllers. The dashboard widgets and the MCP
     * provider are passed through here so they no longer need bespoke
     * registration.
     *
     * NOTE: procest's Settings stack (SettingsController + SettingsService +
     * AdminSettings + SettingsSection + InitializeSettings) and the PWA
     * DashboardController are KEPT bespoke and re-aliased back to the concrete
     * procest classes by {@see BespokeServiceRegistrar} — they are entangled
     * with the frontend `/api/settings` contract (`{config, openRegisters,
     * isAdmin}`, ~180 SettingsService injection sites, the register.d fragment
     * merge, secret redaction, KCC defaults and the schema-config reconcile
     * that the engine generics do not provide). Only the genuinely-mechanical
     * halves (Health, Metrics, Preferences, DeepLink, SPA page/catch-all) are
     * adopted.
     *
     * StaticAccess is suppressed rather than decomposed: `Bootstrap::register()`
     * IS OpenRegister's published AppHost entry point. It is a stateless
     * registration façade with no instance to inject, and wrapping it in a local
     * collaborator would have to make the very same static call — moving the
     * finding instead of removing it.
     *
     * ⚠️ The call is behind a `class_exists()` guard. This runs inside procest's
     * `Application::register()`, which Nextcloud executes on EVERY request, so
     * an unguarded static call to a class in another app fatals the whole
     * instance-wide request — not merely this app's AppHost features. Procest
     * does not declare `<app>openregister</app>`, so an admin can create exactly
     * that configuration. `Bootstrap::class` on the imported name is resolved by
     * the compiler to a plain string and never autoloads, so the guard itself is
     * safe. When openregister is absent the engine registrations are simply
     * skipped: procest still boots and still routes, and the AppHost-backed
     * endpoints degrade individually. See decidesk#377 / #388.
     *
     * @param IRegistrationContext $context The registration context.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.StaticAccess) Bootstrap::register() is OpenRegister's published AppHost entry point; see the note above.
     *
     * @spec openspec/specs/beschikking-generatie/spec.md
     */
    public function register(IRegistrationContext $context): void
    {
        // ADR-040 load-order prelude. OC_App::getEnabledApps() sort()s the app
        // list and Coordinator::registerApps() walks THAT sorted list calling
        // OC_App::registerAutoloading($appId) and then $app->register() one app
        // at a time, so an app registers before the PSR-4 prefix of every
        // alphabetically-LATER app exists. `procest` sorts after `openregister`
        // so this happens to hold today — by alphabet, not by design — and the
        // guard below cannot tell "OpenRegister absent" from "OpenRegister's
        // prefix not registered yet": both answer FALSE and both silently skip
        // the entire engine. Registering the prefix ourselves removes the
        // dependency on ordering; registerAutoloading() is idempotent, so on the
        // current ordering this costs nothing.
        OpenRegisterAutoloader::register();

        if (class_exists(Bootstrap::class) === false) {
            // OpenRegister is absent or disabled. Skip the engine registration
            // rather than fatalling every request; see the note above.
            return;
        }

        Bootstrap::register(
            $context,
            Application::APP_ID,
            [
                'namespace'        => 'OCA\\Procest',
                'sectionName'      => 'Procest',
                'dashboardWidgets' => [
                    CasesOverviewWidget::class,
                    MyTasksWidget::class,
                    OverdueCasesWidget::class,
                    DeadlineAlertsWidget::class,
                    TaskRemindersWidget::class,
                    StalledCasesWidget::class,
                    StartCaseWidget::class,
                ],
                'mcpProvider'      => ProcestToolProvider::class,
            ]
        );
    }//end register()
}//end class
