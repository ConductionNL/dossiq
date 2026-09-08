<?php

/**
 * Dossiq Settings Controller
 *
 * Controller for managing Dossiq application settings.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
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
 * @link https://conduction.nl
 *
 * @spec openspec/specs/admin-settings/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Settings\AdminSettings;
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
 * Controller for managing Dossiq application settings.
 *
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */
class SettingsController extends Controller {

	/**
	 * The recorder that writes a save's meaning onto an integration card.
	 *
	 * A STRING and not a `::class` reference, deliberately: see
	 * {@see self::recordIntegrationSaves()} for why, and the unit test that
	 * stops it rotting.
	 *
	 * @var string
	 */
	private const INTEGRATION_STATUS_SERVICE = 'OCA\\Dossiq\\Service\\IntegrationStatusService';

	/**
	 * The OpenRegister object service.
	 *
	 * @var \OCA\OpenRegister\Contract\ObjectServiceInterface|null The OpenRegister object service.
	 */
	private ?\OCA\OpenRegister\Contract\ObjectServiceInterface $objectService = null;

	/**
	 * Constructor for the SettingsController.
	 *
	 * @param IRequest $request The request object
	 * @param ContainerInterface $container The container
	 * @param IAppManager $appManager The app manager
	 * @param SettingsService $settingsService The settings service
	 * @param IGroupManager $groupManager The group manager
	 * @param IUserSession $userSession The user session
	 * @param IL10N $l10n The translation service (libresign-besluit-signing hint).
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
	 * @return \OCA\OpenRegister\Contract\ObjectServiceInterface|null The OpenRegister service if available, null otherwise.
	 * @throws \RuntimeException If the service is not available.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function getObjectService(): ?\OCA\OpenRegister\Contract\ObjectServiceInterface {
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
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function getConfigurationService(): ?\OCA\OpenRegister\Service\ConfigurationService {
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
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function index(): JSONResponse {
		$user = $this->userSession->getUser();
		$isAdmin = $user !== null && $this->groupManager->isAdmin($user->getUID());

		$config = match ($isAdmin) {
			true => $this->settingsService->getSettings(),
			default => $this->settingsService->getPublicSettings(),
		};

		$libresignAvailable = $this->appManager->isEnabledForUser('libresign');
		$libresignHint = null;
		if ($libresignAvailable === false) {
			$libresignHint = $this->l10n->t(
				'LibreSign is not installed or enabled. Digital signing falls back to '
				. 'the built-in stub adapter — install and enable the LibreSign app to '
				. 'sign beschikkingen with a real eIDAS-aligned signature.'
			);
		}

		return new JSONResponse(
			[
				'success' => true,
				'openRegisters' => in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()),
				'isAdmin' => $isAdmin,
				'config' => $config,
				'libresignAvailable' => $libresignAvailable,
				'libresignHint' => $libresignHint,
			]
		);
	}//end index()

	/**
	 * Update settings with provided data.
	 *
	 * This is the canonical write, matching `GenericSettingsControllerBase::
	 * update()`. The AppHost route table routes `PUT /api/settings` here, and
	 * because this app ships its own SettingsController the generic is never
	 * aliased in (see `AppHost\Bootstrap::aliasControllerUnlessLeafDefinesIt()`)
	 * — so the method has to exist here or the request dies with a 500 rather
	 * than a 404. `src/store/modules/enforcement.js::saveLhsMatrix()` is the
	 * live caller.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function update(): JSONResponse {
		$data = $this->request->getParams();
		$config = $this->settingsService->updateSettings($data);

		$this->recordIntegrationSaves(saved: $data);

		return new JSONResponse(
			[
				'success' => true,
				'config' => $config,
			]
		);
	}//end update()

	/**
	 * Tell the Integrations page what this save means for each connection.
	 *
	 * Only the connections whose OWN config keys the payload carried are
	 * rewritten, so saving the KCC form never restates the ZGW card. That
	 * decision lives in {@see \OCA\Dossiq\Service\IntegrationStatusService},
	 * which is resolved BY NAME rather than injected: this controller is at
	 * PHPMD's `CouplingBetweenObjects` ceiling of 13, and one more constructor
	 * collaborator fails `composer phpmd` for a dependency used on exactly one
	 * line. `tests/Unit/Controller/IntegrationProbesRecordTest.php` asserts the
	 * name resolves, so the string cannot rot into a silent no-op.
	 *
	 * The `has()` guard, rather than a `try`/`catch`, is the same constraint
	 * again: a `catch (\Throwable)` is itself a type reference and pushes the
	 * count back over. The recorder swallows its own failures, so the write is
	 * a no-op when OpenRegister is absent — the admin asked to save settings,
	 * and a page that cannot be updated must not turn that into a 500.
	 *
	 * @param array<string, mixed> $saved The payload the save carried.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	private function recordIntegrationSaves(array $saved): void {
		if ($this->container->has(self::INTEGRATION_STATUS_SERVICE) === false) {
			return;
		}

		$recorder = $this->container->get(self::INTEGRATION_STATUS_SERVICE);
		$recorder->recordFromSave(saved: $saved);

		// And re-probe the seams no setting decides. Signing depends on whether
		// the LibreSign app is enabled, which an admin does in Nextcloud's own
		// app management and never here, so a save is one of only two moments
		// dossiq is allowed to look. The other is `occ upgrade`.
		$recorder->recordAdapterSeams();
	}//end recordIntegrationSaves()

	/**
	 * Legacy alias for {@see update()}.
	 *
	 * The canonical AppHost route table still ships `settings#create`
	 * (POST /api/settings) for the pre-ADR-066 `index/create/load` dialect, and
	 * three dossiq views still POST to it, so it stays reachable (ADR-029).
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function create(): JSONResponse {
		return $this->update();
	}//end create()

	/**
	 * Re-import the configuration from dossiq_register.json.
	 *
	 * Forces a fresh import regardless of version, auto-configuring
	 * all schema and register IDs from the import result.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function load(): JSONResponse {
		$result = $this->settingsService->loadConfiguration(force: true);

		return new JSONResponse($result);
	}//end load()
}//end class
