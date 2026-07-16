<?php

/**
 * Procest Settings Controller
 *
 * Controller for managing Procest application settings.
 *
 * @category Controller
 * @package  OCA\Procest\Controller
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
 *
 * @spec openspec/specs/admin-settings/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\SettingsService;
use OCA\Procest\Settings\AdminSettings;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Controller for managing Procest application settings.
 *
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */
class SettingsController extends Controller
{

    /**
     * The OpenRegister object service.
     *
     * @var \OCA\OpenRegister\Service\ObjectService|null The OpenRegister object service.
     */
    private ?\OCA\OpenRegister\Service\ObjectService $objectService = null;

    /**
     * Constructor for the SettingsController.
     *
     * @param IRequest           $request         The request object
     * @param ContainerInterface $container       The container
     * @param IAppManager        $appManager      The app manager
     * @param SettingsService    $settingsService The settings service
     * @param IGroupManager      $groupManager    The group manager
     * @param IUserSession       $userSession     The user session
     * @param IL10N              $l10n            The translation service (libresign-besluit-signing hint).
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private readonly ContainerInterface $container,
        private readonly IAppManager $appManager,
        private SettingsService $settingsService,
        private readonly IGroupManager $groupManager,
        private readonly IUserSession $userSession,
        private readonly IL10N $l10n,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Attempts to retrieve the OpenRegister service from the container.
     *
     * @return \OCA\OpenRegister\Service\ObjectService|null The OpenRegister service if available, null otherwise.
     * @throws \RuntimeException If the service is not available.

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function getObjectService(): ?\OCA\OpenRegister\Service\ObjectService
    {
        if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === true) {
            $this->objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            return $this->objectService;
        }

        throw new RuntimeException('OpenRegister service is not available.');

    }//end getObjectService()

    /**
     * Attempts to retrieve the Configuration service from the container.
     *
     * @return \OCA\OpenRegister\Service\ConfigurationService|null The Configuration service if available, null otherwise.
     * @throws \RuntimeException If the service is not available.

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function getConfigurationService(): ?\OCA\OpenRegister\Service\ConfigurationService
    {
        if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === true) {
            $configurationService = $this->container->get('OCA\OpenRegister\Service\ConfigurationService');
            return $configurationService;
        }

        throw new RuntimeException('Configuration service is not available.');

    }//end getConfigurationService()

    /**
     * Retrieve all current settings.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function index(): JSONResponse
    {
        $user    = $this->userSession->getUser();
        $isAdmin = $user !== null && $this->groupManager->isAdmin($user->getUID());

        if ($isAdmin === true) {
            $config = $this->settingsService->getSettings();
        } else {
            $config = $this->settingsService->getPublicSettings();
        }//end if

        $libresignAvailable = $this->appManager->isEnabledForUser('libresign');
        $libresignHint      = null;
        if ($libresignAvailable === false) {
            $libresignHint = $this->l10n->t(
                'LibreSign is not installed or enabled. Digital signing falls back to '
                .'the built-in stub adapter — install and enable the LibreSign app to '
                .'sign beschikkingen with a real eIDAS-aligned signature.'
            );
        }

        return new JSONResponse(
            [
                'success'            => true,
                'openRegisters'      => in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()),
                'isAdmin'            => $isAdmin,
                'config'             => $config,
                'libresignAvailable' => $libresignAvailable,
                'libresignHint'      => $libresignHint,
            ]
        );
    }//end index()

    /**
     * Update settings with provided data.
     *
     * @return JSONResponse

      * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
      */
    #[AuthorizedAdminSetting(AdminSettings::class)]
    public function create(): JSONResponse
    {
        $data   = $this->request->getParams();
        $config = $this->settingsService->updateSettings($data);

        return new JSONResponse(
            [
                'success' => true,
                'config'  => $config,
            ]
        );
    }//end create()

    /**
     * Re-import the configuration from procest_register.json.
     *
     * Forces a fresh import regardless of version, auto-configuring
     * all schema and register IDs from the import result.
     *
     * @return JSONResponse

      * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
      */
    #[AuthorizedAdminSetting(AdminSettings::class)]
    public function load(): JSONResponse
    {
        $result = $this->settingsService->loadConfiguration(force: true);

        return new JSONResponse($result);
    }//end load()
}//end class
