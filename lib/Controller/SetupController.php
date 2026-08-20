<?php

/**
 * Procest first-time setup contract (ADR-042).
 *
 * Backs the abstract CnSetupWizard: reports per-step completion
 * (`GET /api/setup/status`), persists config values from `choice` / `config-fields`
 * steps (`POST /api/setup/config`), and runs privileged server-side actions —
 * notably the bezwaar/beroep seed — from `run-action` steps
 * (`POST /api/setup/action/{actionId}`). The wizard NEVER writes OpenRegister
 * objects from the browser; seeding runs here, in an admin request context, so
 * OpenRegister's RBAC create-check is satisfied.
 *
 * @category Controller
 * @package  OCA\Procest\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/first-time-setup/specs/first-time-setup/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\Service\SeedDataService;
use OCA\Procest\Service\SettingsService;
use OCA\Procest\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\DataResponse;
use OCP\IAppConfig;
use OCP\IRequest;

/**
 * First-time setup status + actions for the abstract setup wizard.
 *
 * @spec openspec/changes/first-time-setup/specs/first-time-setup/spec.md
 */
class SetupController extends Controller {
	/**
	 * Setup contract version; matches manifest.setup.version.
	 *
	 * @var int
	 */
	private const SETUP_VERSION = 1;

	/**
	 * Construct the setup controller.
	 *
	 * @param string $appName The app id.
	 * @param IRequest $request The request.
	 * @param IAppConfig $appConfig App-config reader/writer.
	 * @param SettingsService $settingsService OpenRegister availability + config import.
	 * @param SeedDataService $seedDataService Bezwaar/beroep seeder.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IAppConfig $appConfig,
		private readonly SettingsService $settingsService,
		private readonly SeedDataService $seedDataService,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Report per-step setup status for the wizard.
	 *
	 * @return DataResponse `{ version, completed, steps: { <id>: { done } } }`.
	 *
	 * @spec openspec/changes/first-time-setup/specs/first-time-setup/spec.md
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function status(): DataResponse {
		$registerDone = $this->settingsService->isOpenRegisterAvailable() === true
			&& $this->config(key: 'register') !== ''
			&& $this->config(key: 'case_type_schema') !== '';
		$seedDone = $this->config(key: 'setup_seed_done') === '1';
		$completed = $registerDone;

		if ($completed === true) {
			$this->appConfig->setValueString('procest', 'setup_completed_version', (string)self::SETUP_VERSION);
		}

		$response = [
			'version' => self::SETUP_VERSION,
			'completed' => $completed,
			'steps' => [
				'register-check' => ['done' => $registerDone],
				'seed' => ['done' => $seedDone],
			],
		];

		// Financial-integration (dwangsom uitbetaling) capability: surface a
		// missing callback secret before go-live rather than after an
		// incident (enforce-dwangsom-callback-signature spec).
		if ($this->config(key: 'dwangsom_uitbetaling_schema') !== '') {
			$response['dwangsom_callback_secret_configured'] = $this->config(key: 'dwangsom_callback_secret') !== '';
		}

		return new DataResponse($response);
	}//end status()

	/**
	 * Persist app-config values from a `config-fields` / `choice` step.
	 *
	 * @return DataResponse `{ success }`.
	 *
	 * @spec openspec/changes/first-time-setup/specs/first-time-setup/spec.md
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function saveConfig(): DataResponse {
		foreach ($this->request->getParams() as $key => $value) {
			if (in_array($key, ['_route'], true) === true) {
				continue;
			}

			$stored = $value;
			if (is_scalar($value) === false) {
				$stored = json_encode($value);
			}

			$this->appConfig->setValueString(
				'procest',
				(string)$key,
				(string)$stored,
			);
		}

		return new DataResponse(['success' => true]);
	}//end saveConfig()

	/**
	 * Run a privileged server-side setup action.
	 *
	 * @param string $actionId One of `init-register` | `seed`.
	 *
	 * @return DataResponse `{ success, message, detail }`.
	 *
	 * @spec openspec/changes/first-time-setup/specs/first-time-setup/spec.md
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function runAction(string $actionId): DataResponse {
		if ($actionId === 'init-register') {
			$this->settingsService->loadConfiguration(force: true);
			return new DataResponse(['success' => true, 'message' => 'Register and schemas initialised.']);
		}

		if ($actionId === 'seed') {
			$result = $this->seedDataService->seedBezwaarBeroepData();
			if (($result['success'] ?? false) === false) {
				return new DataResponse(
					['success' => false, 'message' => ($result['message'] ?? 'Seed failed')],
					Http::STATUS_UNPROCESSABLE_ENTITY,
				);
			}

			$this->appConfig->setValueString('procest', 'setup_seed_done', '1');
			$message = sprintf(
				'Seeded %d case types, %d status types, %d role types (%d skipped).',
				($result['caseTypes'] ?? 0),
				($result['statusTypes'] ?? 0),
				($result['roleTypes'] ?? 0),
				($result['skipped'] ?? 0),
			);
			return new DataResponse(['success' => true, 'message' => $message, 'detail' => $result]);
		}

		return new DataResponse(
			['success' => false, 'message' => 'Unknown setup action: ' . $actionId],
			Http::STATUS_NOT_FOUND,
		);
	}//end runAction()

	/**
	 * Read a procest app-config string value.
	 *
	 * @param string $key The config key.
	 *
	 * @return string The value, or '' when unset.
	 */
	private function config(string $key): string {
		return $this->appConfig->getValueString('procest', $key, '');
	}//end config()
}//end class
