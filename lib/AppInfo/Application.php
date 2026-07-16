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

use OCA\OpenRegister\AppHost\Bootstrap;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Procest\Service\Beschikking\ArchivalAdapterInterface;
use OCA\Procest\Service\Beschikking\LibresignApiClient;
use OCA\Procest\Service\Beschikking\LibresignSigningAdapter;
use OCA\Procest\Service\Beschikking\OpenRegisterArchivalAdapter;
use OCA\Procest\Service\Beschikking\MockSigningAdapter;
use OCA\Procest\Service\Beschikking\MockTemplateEngineAdapter;
use OCA\Procest\Service\Beschikking\SigningAdapterInterface;
use OCA\Procest\Service\Beschikking\TemplateEngineAdapterInterface;
use OCA\Procest\Service\ZgwDocumentService;
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
use OCA\Procest\Listener\BezwaarLegalHoldListener;
use OCA\Procest\Listener\BezwaarLifecycleListener;
use OCA\Procest\Listener\DecisionConcludedListener;
use OCA\Procest\Event\ParafeerTransitionEvent;
use OCA\Procest\Listener\ApprovalStepNotificationListener;
use OCA\Procest\Listener\KpiCacheInvalidationListener;
use OCA\Procest\Listener\LocationBagValidationListener;
use OCA\Procest\Listener\ParaferingAuditListener;
use OCA\Procest\Listener\RoleMutationListener;
use OCA\Procest\Mcp\ProcestToolProvider;
use OCA\Procest\Middleware\MandateValidationMiddleware;
use OCA\Procest\Middleware\QuotaEnforcementMiddleware;
use OCA\Procest\Middleware\TenantClaimValidationMiddleware;
use OCA\Procest\Middleware\TenantContextMiddleware;
use OCA\Procest\Middleware\TenantIsolationMiddleware;
use OCA\Procest\Middleware\TenantMiddleware;
use OCA\Procest\Middleware\ZgwAuthMiddleware;
use OCA\Procest\Service\ShillinqIntegrationService;
use OCA\Procest\Service\TenantJwtService;
use OCP\IConfig;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\Security\IContentSecurityPolicyManager;

/**
 * Main application class for the Procest case management app.
 *
 * @spec openspec/specs/beschikking-generatie/spec.md
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
     *
     * @spec openspec/specs/beschikking-generatie/spec.md
     */
    public function register(IRegistrationContext $context): void
    {
        // OpenRegister AppHost engine (ADR-040). Aliases the mechanical
        // plumbing classes to the shared generics and registers the
        // manifest-driven deep-link listener + the observability (health /
        // metrics) controllers. The dashboard widgets and the MCP provider are
        // passed through here so they no longer need bespoke registration.
        //
        // NOTE: procest's Settings stack (SettingsController + SettingsService +
        // AdminSettings + SettingsSection + InitializeSettings) and the PWA
        // DashboardController are KEPT bespoke and re-aliased back to the
        // concrete procest classes immediately below — they are entangled with
        // the frontend `/api/settings` contract (`{config, openRegisters,
        // isAdmin}`, ~180 SettingsService injection sites, the register.d
        // fragment merge, secret redaction, KCC defaults and the schema-config
        // reconcile that the engine generics do not provide). Only the
        // genuinely-mechanical halves (Health, Metrics, Preferences, DeepLink,
        // SPA page/catch-all) are adopted.
        Bootstrap::register(
            $context,
            self::APP_ID,
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

        // Re-assert the procest-bespoke plumbing classes the engine just
        // registered to generics. A concrete-to-self alias
        // (registerServiceAlias(X, X)) infinitely recurses on NC's container
        // (the alias resolves itself), so each bespoke class is re-registered
        // with an explicit factory that constructs the REAL procest class —
        // overriding the Bootstrap generic factory for the same key.
        $context->registerService(
            \OCA\Procest\Controller\DashboardController::class,
            static function (\Psr\Container\ContainerInterface $c): \OCA\Procest\Controller\DashboardController {
                return new \OCA\Procest\Controller\DashboardController(
                    request: $c->get('OCP\\IRequest')
                );
            }
        );
        $context->registerService(
            \OCA\Procest\Controller\SettingsController::class,
            static function (\Psr\Container\ContainerInterface $c): \OCA\Procest\Controller\SettingsController {
                return new \OCA\Procest\Controller\SettingsController(
                    request: $c->get('OCP\\IRequest'),
                    container: $c,
                    appManager: $c->get('OCP\\App\\IAppManager'),
                    settingsService: $c->get(\OCA\Procest\Service\SettingsService::class),
                    groupManager: $c->get('OCP\\IGroupManager'),
                    userSession: $c->get('OCP\\IUserSession'),
                    l10n: $c->get('OCP\\IL10N')
                );
            }
        );
        $context->registerService(
            \OCA\Procest\Service\SettingsService::class,
            static function (\Psr\Container\ContainerInterface $c): \OCA\Procest\Service\SettingsService {
                return new \OCA\Procest\Service\SettingsService(
                    appConfig: $c->get('OCP\\IAppConfig'),
                    appManager: $c->get('OCP\\App\\IAppManager'),
                    container: $c,
                    logger: $c->get('Psr\\Log\\LoggerInterface')
                );
            }
        );
        $context->registerService(
            \OCA\Procest\Repair\InitializeSettings::class,
            static function (\Psr\Container\ContainerInterface $c): \OCA\Procest\Repair\InitializeSettings {
                return new \OCA\Procest\Repair\InitializeSettings(
                    settingsService: $c->get(\OCA\Procest\Service\SettingsService::class),
                    logger: $c->get('Psr\\Log\\LoggerInterface')
                );
            }
        );
        $context->registerService(
            \OCA\Procest\Settings\AdminSettings::class,
            static function (\Psr\Container\ContainerInterface $c): \OCA\Procest\Settings\AdminSettings {
                return new \OCA\Procest\Settings\AdminSettings(
                    appManager: $c->get('OCP\\App\\IAppManager'),
                    initialState: $c->get('OCP\\AppFramework\\Services\\IInitialState')
                );
            }
        );
        $context->registerService(
            \OCA\Procest\Sections\SettingsSection::class,
            static function (\Psr\Container\ContainerInterface $c): \OCA\Procest\Sections\SettingsSection {
                return new \OCA\Procest\Sections\SettingsSection(
                    l: $c->get('OCP\\IL10N'),
                    urlGenerator: $c->get('OCP\\IURLGenerator')
                );
            }
        );

        // Note @mention notifications (nc-vue #207, ncvue-w2-leaves-adoption):
        // MentionNotificationService raises `note_mention` notifications;
        // this Notifier renders them for the bell menu.
        $context->registerNotifierService(\OCA\Procest\Notification\Notifier::class);

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

        // DSO: listen for new vergunningaanvraag objects from OpenRegister.
        $context->registerEventListener(
            event: ObjectCreatedEvent::class,
            listener: VergunningaanvraagCreatedListener::class
        );

        // Bag-location-save-validation: pre-persist location source=bag
        // enforcement (closes bag-register-adapter tasks.md item 4.1).
        $context->registerEventListener(
            event: ObjectCreatingEvent::class,
            listener: LocationBagValidationListener::class
        );
        $context->registerEventListener(
            event: ObjectUpdatingEvent::class,
            listener: LocationBagValidationListener::class
        );

        $this->registerBezwaarListeners(context: $context);
        $this->registerTermijnListeners(context: $context);
        $this->registerDecisionListeners(context: $context);

        // DSO Omgevingsloket: create Procest zaak when a vergunningaanvraag is written.
        $context->registerEventListener(
            event: ObjectCreatedEvent::class,
            listener: VergunningaanvraagCreatedListener::class
        );

        $context->registerMiddleware(class: ZgwAuthMiddleware::class);
        $context->registerMiddleware(class: TenantMiddleware::class);
        // SaaS chain (member 04): resolve tenant binding then set Postgres
        // search_path. Order matters — Context runs before Isolation.
        $context->registerMiddleware(class: TenantContextMiddleware::class);
        $context->registerMiddleware(class: TenantIsolationMiddleware::class);
        // SaaS chain (member 05): JWT tenant-claim validation against the
        // request-bound tenant. Forged / cross-tenant JWT → 403.
        $context->registerMiddleware(class: TenantClaimValidationMiddleware::class);
        // SaaS chain (member 06): mandate-matrix authorisation gate. Maps the
        // HTTP verb (and URL hints like /transition) to a matrix action key
        // and blocks the request on deny.
        $context->registerMiddleware(class: MandateValidationMiddleware::class);
        // SaaS chain (member 09): per-request quota enforcement (case creation +
        // API calls). Runs last in the SaaS chain.
        $context->registerMiddleware(class: QuotaEnforcementMiddleware::class);
        // SaaS chain (member 05): factory the TenantJwtService with the secret
        // from app config (procest.jwt_signing_secret). Generates a
        // per-instance random fallback when unset (dev-friendly; production
        // must set the secret via occ config:app:set procest jwt_signing_secret).
        $context->registerService(
                TenantJwtService::class,
                function (\Psr\Container\ContainerInterface $c): TenantJwtService {
                    $config = $c->get(IConfig::class);
                    $secret = (string) $config->getAppValue(self::APP_ID, 'jwt_signing_secret', '');
                    if ($secret === '' || strlen($secret) < 16) {
                        $secret = (string) $config->getSystemValue('secret', str_pad(self::APP_ID, 32, '_'));
                    }

                    return new TenantJwtService(signingSecret: $secret);
                }
                );

        // SaaS chain (member 10): factory the ShillinqIntegrationService with
        // the invoicing endpoint + API key from app config. Without this the
        // string constructor args default to '' and exportInvoice short-circuits
        // to "Shillinq not configured" — leaving every tenant invoice unexported
        // (procest#223 finding 2). Empty config keeps the graceful no-op.
        $context->registerService(
                ShillinqIntegrationService::class,
                function (\Psr\Container\ContainerInterface $c): ShillinqIntegrationService {
                    $config  = $c->get(IConfig::class);
                    $baseUrl = (string) $config->getAppValue(self::APP_ID, 'shillinq_base_url', '');
                    $apiKey  = (string) $config->getAppValue(self::APP_ID, 'shillinq_api_key', '');
                    return new ShillinqIntegrationService(
                        httpClientService: $c->get('OCP\\Http\\Client\\IClientService'),
                        logger: $c->get(\Psr\Log\LoggerInterface::class),
                        shillinqBaseUrl: $baseUrl,
                        shillinqApiKey: $apiKey,
                    );
                }
                );

        // Background jobs are declared in appinfo/info.xml under
        // <background-jobs>; Nextcloud auto-registers them with the IJobList.
        // IRegistrationContext has no registerJob() method.
        //
        // Beschikking cross-app integration adapters. These resolve to mock
        // implementations until the real OpenConnector (TSP signing),
        // Docudesk (template render), and OpenRegister (archief ingest)
        // endpoints land in their own repos (tasks T23-T26).
        $context->registerServiceAlias(TemplateEngineAdapterInterface::class, MockTemplateEngineAdapter::class);
        // SigningAdapterInterface: LibreSign (LibreCode) when the app is
        // installed+enabled, else the pre-existing MockSigningAdapter stub —
        // see openspec/changes/libresign-besluit-signing/design.md §6.
        // procest never hard-depends on LibreSign: its absence is a clean,
        // logged, translated fallback to the unchanged pre-existing
        // behaviour, not an error.
        $context->registerService(
            SigningAdapterInterface::class,
            static function (\Psr\Container\ContainerInterface $c): SigningAdapterInterface {
                $appManager = $c->get('OCP\\App\\IAppManager');
                if ($appManager->isEnabledForUser('libresign') === true) {
                    return new LibresignSigningAdapter(
                        apiClient: new LibresignApiClient(
                            clientService: $c->get('OCP\\Http\\Client\\IClientService'),
                            urlGenerator: $c->get('OCP\\IURLGenerator'),
                            appConfig: $c->get('OCP\\IAppConfig'),
                            logger: $c->get('Psr\\Log\\LoggerInterface'),
                        ),
                        appManager: $appManager,
                        appConfig: $c->get('OCP\\IAppConfig'),
                        userManager: $c->get('OCP\\IUserManager'),
                        rootFolder: $c->get('OCP\\Files\\IRootFolder'),
                        documentService: $c->get(ZgwDocumentService::class),
                        logger: $c->get('Psr\\Log\\LoggerInterface'),
                    );
                }

                $c->get('Psr\\Log\\LoggerInterface')->warning(
                    $c->get('OCP\\IL10N')->t(
                        'LibreSign is not installed or enabled. Digital signing falls back to '
                        .'the built-in stub adapter — install and enable the LibreSign app to '
                        .'sign beschikkingen with a real eIDAS-aligned signature.'
                    ),
                    ['app' => Application::APP_ID]
                );

                return $c->get(MockSigningAdapter::class);
            }
        );
        // Beschikking archival is repointed onto OpenRegister's declarative
        // archival pipeline (ADR-022 / migrate-archival-to-or): retention/
        // destruction are governed by x-openregister-archival on the case
        // schema; this adapter records the archival marker + Archiefwet
        // vernietigingsdatum. The former app-local MockArchivalAdapter is retired.
        $context->registerServiceAlias(ArchivalAdapterInterface::class, OpenRegisterArchivalAdapter::class);

        // External auth-broker adapters (lib/Service/Auth/), selected by the
        // `integration.digid.mode` config tier (external-integrations-test-environments).
        // DEFAULT `log` = the dormant Log* implementations which throw + log
        // so a misconfigured environment surfaces "broker not configured"
        // immediately and NEVER makes an external call. `simulator` binds the
        // maykinmedia-pattern local login simulator (no real SAML — capped at
        // beta). `preprod`/`live` (certificate-bound Logius koppelvlak) are
        // documented in docs/admin/integrations.md and bound in a follow-up
        // once the aansluiting + PKIoverheid cert are granted; until then they
        // fall through to the Log adapter (fail-closed).
        $context->registerService(
            \OCA\Procest\Service\Auth\DigidSamlAdapterInterface::class,
            static function (\Psr\Container\ContainerInterface $c): \OCA\Procest\Service\Auth\DigidSamlAdapterInterface {
                $mode = $c->get(\OCA\Procest\Service\External\IntegrationMode::class)
                    ->resolve('digid', [\OCA\Procest\Service\External\IntegrationMode::SIMULATOR]);
                if ($mode === \OCA\Procest\Service\External\IntegrationMode::SIMULATOR) {
                    return new \OCA\Procest\Service\Auth\SimulatorDigidSamlAdapter();
                }

                return $c->get(\OCA\Procest\Service\Auth\LogDigidSamlAdapter::class);
            }
        );
        $context->registerService(
            \OCA\Procest\Service\Auth\EHerkenningSamlAdapterInterface::class,
            static function (\Psr\Container\ContainerInterface $c): \OCA\Procest\Service\Auth\EHerkenningSamlAdapterInterface {
                $mode = $c->get(\OCA\Procest\Service\External\IntegrationMode::class)
                    ->resolve('digid', [\OCA\Procest\Service\External\IntegrationMode::SIMULATOR]);
                if ($mode === \OCA\Procest\Service\External\IntegrationMode::SIMULATOR) {
                    return new \OCA\Procest\Service\Auth\SimulatorEHerkenningSamlAdapter();
                }

                return $c->get(\OCA\Procest\Service\Auth\LogEHerkenningSamlAdapter::class);
            }
        );

        // Wave-4 external-API ports (low-volume families). All dormant
        // log-only by default; flip the matching openconnector
        // source-slug feature flag and override the alias in a
        // downstream Application::register() to activate.
        //
        // - KvK Handelsregister
        // (leverancier-zaakportaal eHerkenning kvkNummer enrichment,
        // bedrijfszaak intake, brp-kvk-register-sets seed).
        // - BRP / Haal Centraal
        // (citizen zaak intake DigiD BSN → persoon envelope,
        // briefcode resolution, register-set seed).
        // - TMLO / MDTO metadata builder + e-Depot submission
        // (archief-edepot-handover-03 metadata bundling +
        // archief-edepot-handover-05 SIP submission).
        // - external-ZGW client
        // (cross-municipality zaak hand-off via Zaken-API +
        // Documenten-API).
        // - ZTC / Catalogi-API client
        // (zaaktype URL resolution before hand-off +
        // regional Catalogi-API zaaktype import).
        // KvK + BRP: selected by the `integration.<name>.mode` config tier
        // (external-integrations-test-environments). DEFAULT `log` = dormant
        // (no external call). KvK `test`/`live` binds the KvkApiAdapter
        // (test tier = api.kvk.nl/test, public key). BRP `mock`/`test` binds
        // the HaalCentraalBrpAdapter (mock = ghcr.io/brp-api/personen-mock
        // offline; test = proefomgeving once the X-API-KEY is granted).
        $context->registerService(
            \OCA\Procest\Service\External\Kvk\KvkHandelsregisterAdapterInterface::class,
            static function (\Psr\Container\ContainerInterface $c): \OCA\Procest\Service\External\Kvk\KvkHandelsregisterAdapterInterface {
                $modeService = $c->get(\OCA\Procest\Service\External\IntegrationMode::class);
                $mode        = $modeService->resolve(
                        'kvk',
                        [
                            \OCA\Procest\Service\External\IntegrationMode::TEST,
                            \OCA\Procest\Service\External\IntegrationMode::LIVE,
                        ]
                        );
                if ($mode !== \OCA\Procest\Service\External\IntegrationMode::LOG) {
                    return new \OCA\Procest\Service\External\Kvk\KvkApiAdapter(
                        clientService: $c->get('OCP\\Http\\Client\\IClientService'),
                        mode: $modeService,
                        logger: $c->get('Psr\\Log\\LoggerInterface'),
                    );
                }

                return $c->get(\OCA\Procest\Service\External\Kvk\LogKvkHandelsregisterAdapter::class);
            }
        );
        $context->registerService(
            \OCA\Procest\Service\External\Brp\BrpHaalCentraalAdapterInterface::class,
            static function (\Psr\Container\ContainerInterface $c): \OCA\Procest\Service\External\Brp\BrpHaalCentraalAdapterInterface {
                $modeService = $c->get(\OCA\Procest\Service\External\IntegrationMode::class);
                $mode        = $modeService->resolve(
                        'brp',
                        [
                            \OCA\Procest\Service\External\IntegrationMode::MOCK,
                            \OCA\Procest\Service\External\IntegrationMode::TEST,
                        ]
                        );
                if ($mode !== \OCA\Procest\Service\External\IntegrationMode::LOG) {
                    return new \OCA\Procest\Service\External\Brp\HaalCentraalBrpAdapter(
                        clientService: $c->get('OCP\\Http\\Client\\IClientService'),
                        mode: $modeService,
                        logger: $c->get('Psr\\Log\\LoggerInterface'),
                    );
                }

                return $c->get(\OCA\Procest\Service\External\Brp\LogBrpHaalCentraalAdapter::class);
            }
        );
        // BAG (Basisregistratie Adressen en Gebouwen) — authoritative address +
        // pand/verblijfsobject lookup (bag-register-adapter). Selected by
        // `integration.bag.mode` (external-integrations-test-environments config-tier
        // model). DEFAULT `log` = dormant (no external call). `test`/`live` binds the
        // BagApiAdapter (Kadaster BAG API Individuele Bevragingen v2). Deliberately
        // distinct from PdokBagService's free/open BAG WFS mirror — see
        // openspec/changes/bag-register-adapter/design.md.
        $context->registerService(
            \OCA\Procest\Service\External\Bag\BagAdapterInterface::class,
            static function (\Psr\Container\ContainerInterface $c): \OCA\Procest\Service\External\Bag\BagAdapterInterface {
                $modeService = $c->get(\OCA\Procest\Service\External\IntegrationMode::class);
                $mode        = $modeService->resolve(
                        'bag',
                        [
                            \OCA\Procest\Service\External\IntegrationMode::TEST,
                            \OCA\Procest\Service\External\IntegrationMode::LIVE,
                        ]
                        );
                if ($mode !== \OCA\Procest\Service\External\IntegrationMode::LOG) {
                    return new \OCA\Procest\Service\External\Bag\BagApiAdapter(
                        clientService: $c->get('OCP\\Http\\Client\\IClientService'),
                        mode: $modeService,
                        mapper: $c->get(\OCA\Procest\Service\External\Bag\BagResponseMapper::class),
                        logger: $c->get('Psr\\Log\\LoggerInterface'),
                    );
                }

                return $c->get(\OCA\Procest\Service\External\Bag\LogBagAdapter::class);
            }
        );
        // BRK (Basisregistratie Kadaster) — authoritative parcel/ownership-reference
        // lookup (brk-woz-register-adapters). Selected by `integration.brk.mode`
        // (external-integrations-test-environments config-tier model). DEFAULT `log` =
        // dormant (no external call). `test`/`live` binds the BrkApiAdapter (Kadaster
        // Haal Centraal BRK Bevragen API v2) — see
        // openspec/changes/brk-woz-register-adapters/design.md.
        $context->registerService(
            \OCA\Procest\Service\External\Brk\BrkAdapterInterface::class,
            static function (\Psr\Container\ContainerInterface $c): \OCA\Procest\Service\External\Brk\BrkAdapterInterface {
                $modeService = $c->get(\OCA\Procest\Service\External\IntegrationMode::class);
                $mode        = $modeService->resolve(
                        'brk',
                        [
                            \OCA\Procest\Service\External\IntegrationMode::TEST,
                            \OCA\Procest\Service\External\IntegrationMode::LIVE,
                        ]
                        );
                if ($mode !== \OCA\Procest\Service\External\IntegrationMode::LOG) {
                    return new \OCA\Procest\Service\External\Brk\BrkApiAdapter(
                        clientService: $c->get('OCP\\Http\\Client\\IClientService'),
                        mode: $modeService,
                        mapper: $c->get(\OCA\Procest\Service\External\Brk\BrkResponseMapper::class),
                        logger: $c->get('Psr\\Log\\LoggerInterface'),
                    );
                }

                return $c->get(\OCA\Procest\Service\External\Brk\LogBrkAdapter::class);
            }
        );
        // WOZ (Waardering Onroerende Zaken) — authoritative property-valuation lookup
        // (brk-woz-register-adapters). Selected by `integration.woz.mode`
        // (external-integrations-test-environments config-tier model). DEFAULT `log` =
        // dormant (no external call). `test`/`live` binds the WozApiAdapter (Kadaster
        // Haal Centraal WOZ Bevragen API). Deliberately NOT bound to the public
        // WOZ-waardeloket, which has no programmatic API — see
        // openspec/changes/brk-woz-register-adapters/design.md Decision 2.
        $context->registerService(
            \OCA\Procest\Service\External\Woz\WozAdapterInterface::class,
            static function (\Psr\Container\ContainerInterface $c): \OCA\Procest\Service\External\Woz\WozAdapterInterface {
                $modeService = $c->get(\OCA\Procest\Service\External\IntegrationMode::class);
                $mode        = $modeService->resolve(
                        'woz',
                        [
                            \OCA\Procest\Service\External\IntegrationMode::TEST,
                            \OCA\Procest\Service\External\IntegrationMode::LIVE,
                        ]
                        );
                if ($mode !== \OCA\Procest\Service\External\IntegrationMode::LOG) {
                    return new \OCA\Procest\Service\External\Woz\WozApiAdapter(
                        clientService: $c->get('OCP\\Http\\Client\\IClientService'),
                        mode: $modeService,
                        mapper: $c->get(\OCA\Procest\Service\External\Woz\WozResponseMapper::class),
                        logger: $c->get('Psr\\Log\\LoggerInterface'),
                    );
                }

                return $c->get(\OCA\Procest\Service\External\Woz\LogWozAdapter::class);
            }
        );
        // TMLO metadata building + e-Depot submission adapter seams retired
        // (migrate-archival-to-or, ADR-022): OpenRegister's TmloService builds
        // TMLO/MDTO metadata from schema config and its Edepot/Transport seam owns
        // submission. Procest contributes the mapping declaratively (tmloDefaults).
        $context->registerServiceAlias(
            \OCA\Procest\Service\External\Zgw\ZgwExternalAdapterInterface::class,
            \OCA\Procest\Service\External\Zgw\LogZgwExternalAdapter::class
        );
        $context->registerServiceAlias(
            \OCA\Procest\Service\External\Ztc\ZtcCatalogiAdapterInterface::class,
            \OCA\Procest\Service\External\Ztc\LogZtcCatalogiAdapter::class
        );
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

        // Bezwaar/beroep legal hold: when an Awb proceeding (objection) is
        // registered the linked case gets an OpenRegister legal hold; when the
        // proceeding reaches its final outcome (bezwaarDecision / appealDecision)
        // the hold is released. Hold storage + enforcement are OpenRegister's
        // (ADR-022 / migrate-archival-to-or) — this replaces the retired
        // ArchivalTriggerService `opgeschort-juridische-procedure` status.
        $context->registerEventListener(
            event: ObjectCreatedEvent::class,
            listener: BezwaarLegalHoldListener::class
        );

        // Parafering audit trail: one listener emits an OR audit-trail entry
        // (hash-chained, natively immutable) for every parafeerroute transition.
        // Per ADR-022 + consume-or-audit-trail-fleet-wide (migrate-parafering-to-or-audit),
        // there is no parallel paraferingAuditEntry write path and no in-app
        // append-only validator — OR's audit trail rejects PUT/DELETE natively.
        $context->registerEventListener(
            event: ParafeerTransitionEvent::class,
            listener: ParaferingAuditListener::class
        );

        // Parafering notifications now observe OpenRegister's approval-workflow
        // step events (ADR-022 / migrate-parafering-to-or-approval-workflow):
        // when a step is approved the next parafeerder is notified; when a step
        // is rejected (terugsturen) the steller is notified. The OpenRegister
        // event classes are registered by FQN string so procest carries no
        // hard compile-time dependency on the optional OpenRegister app.
        $context->registerEventListener(
            event: 'OCA\OpenRegister\Event\ApprovalStepApprovedEvent',
            listener: ApprovalStepNotificationListener::class
        );
        $context->registerEventListener(
            event: 'OCA\OpenRegister\Event\ApprovalStepRejectedEvent',
            listener: ApprovalStepNotificationListener::class
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
     * Register termijnbewaking (AWB deadline engine) listeners.
     *
     * On case creation, an AWB TermijnInstance is automatically bound to
     * the case using the active TermijnDefinitie for the zaaktype. The
     * listener is a pure observer (ADR-022); all logic lives in
     * {@see \OCA\Procest\Service\TermijnService}.
     *
     * @param IRegistrationContext $context Registration context.
     *
     * @return void
     *
     * @spec openspec/changes/termijnbewaking-dwangsom-engine-02-termijn-binding-lifecycle/tasks.md
     */
    private function registerTermijnListeners(IRegistrationContext $context): void
    {
        $context->registerEventListener(
            event: ObjectCreatedEvent::class,
            listener: \OCA\Procest\Listener\TermijnCaseCreatedListener::class
        );
    }//end registerTermijnListeners()

    /**
     * Register the decidesk decision-outcome listener.
     *
     * Procest delegates contract / besluit / bezwaar / advice DECISIONS to
     * decidesk by dispatching `DecisionRequestedEvent`; the terminal outcome
     * arrives back as decidesk's `DecisionConcludedEvent`. This listener
     * materialises the ZGW `Besluit` from that outcome (filtered to this app via
     * `getSourceApp()`). The event class is registered by FQN string and only
     * when decidesk is installed, so procest carries no hard compile-time
     * dependency on the optional decidesk app.
     *
     * @param IRegistrationContext $context Registration context.
     *
     * @return void
     *
     * @spec openspec/changes/procest-delegation-via-events/specs/contract-decision-delegation/spec.md#requirement-req-pdcd-003-the-zgw-besluit-is-materialised-from-the-decisionconcludedevent
     */
    private function registerDecisionListeners(IRegistrationContext $context): void
    {
        if (class_exists('\\OCA\\Decidesk\\Event\\DecisionConcludedEvent') === false) {
            return;
        }

        // FQN string (not ::class) so there is no hard compile-time dependency
        // on the optional decidesk app — mirrors the OpenRegister approval-event
        // registration above.
        $context->registerEventListener(
            event: 'OCA\Decidesk\Event\DecisionConcludedEvent',
            listener: DecisionConcludedListener::class
        );
    }//end registerDecisionListeners()

    /**
     * Boot the application.
     *
     * @param IBootContext $context The boot context
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     *
     * @spec openspec/specs/beschikking-generatie/spec.md
     */
    public function boot(IBootContext $context): void
    {
        $this->relaxCspForMapTiles(server: $context->getServerContainer());
    }//end boot()

    /**
     * Allowlist the map hosts: base-map tiles (img-src) and the address-search
     * geocoder (connect-src).
     *
     * Leaflet loads tiles as plain `<img>` elements, so Nextcloud's default
     * Content-Security-Policy (`img-src 'self' data: blob:`) blocks every
     * third-party tile server. The tile hosts here mirror `mapConfig.basemaps` in
     * `src/manifest.json` and the base maps offered by the location widget — keep
     * them in step, or a base map the user can pick from the switcher will
     * silently render blank (CSP blocks the request outright, so nothing even
     * shows up in the network log — look in the console).
     *
     * Procest declares these itself rather than relying on another app: the OSM
     * host happened to be allowed only because the (optional) Nextcloud `maps` app
     * pushes a default policy, so the map broke on any instance without it.
     *
     * NC merges policies additively via `addDefaultPolicy()` and never narrows, so
     * this is idempotent and cannot loosen anything another app already set.
     *
     * @param mixed $server Server container (passed in from boot()).
     *
     * @return void
     */
    private function relaxCspForMapTiles($server): void
    {
        try {
            $cspManager = $server->get(IContentSecurityPolicyManager::class);
            $policy     = new ContentSecurityPolicy();
            // Base-map tiles.
            $policy->addAllowedImageDomain('https://*.tile.openstreetmap.org');
            $policy->addAllowedImageDomain('https://*.tile.openstreetmap.fr');
            $policy->addAllowedImageDomain('https://*.tile.opentopomap.org');
            // Address search (forward geocoding) in the location widget.
            $policy->addAllowedConnectDomain('https://nominatim.openstreetmap.org');
            $cspManager->addDefaultPolicy($policy);
        } catch (\Throwable $e) {
            // CSP manager unavailable. Degrade to "no base map" rather than
            // failing the boot — every other page keeps working.
        }
    }//end relaxCspForMapTiles()
}//end class
