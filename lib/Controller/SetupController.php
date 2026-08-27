<?php

/**
 * Dossiq first-time setup contract (ADR-042).
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
 * @package  OCA\Dossiq\Controller
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/first-time-setup/specs/first-time-setup/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Service\SeedDataService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Settings\AdminSettings;
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

		// Dwangsom callback signing secret. Only meaningful once the payout
		// integration is configured at all: with no dwangsom schema there is
		// no callback to sign, so the step is settled rather than outstanding.
		$dwangsomActive = $this->config(key: 'dwangsom_uitbetaling_schema') !== '';
		$dwangsomSecretDone = $dwangsomActive === false
			|| $this->config(key: 'dwangsom_callback_secret') !== '';

		if ($completed === true) {
			$this->appConfig->setValueString('dossiq', 'setup_completed_version', (string)self::SETUP_VERSION);
		}

		$response = [
			'version' => self::SETUP_VERSION,
			'completed' => $completed,
			'steps' => [
				'register-check'  => ['done' => $registerDone],
				'seed'            => ['done' => $seedDone],
				// Reported unconditionally so the wizard can tell "configured"
				// from "never mentioned" — an unreported step is UNKNOWN to
				// CnAppRoot and never prompts.
				'dwangsom-secret' => ['done' => $dwangsomSecretDone],
			],
		];

		// Financial-integration (dwangsom uitbetaling) capability: surface a
		// missing callback secret before go-live rather than after an
		// incident (enforce-dwangsom-callback-signature spec).
		//
		// This flag is the ORIGINAL surface for that warning and had no reader
		// anywhere in the frontend — it was computed, serialised and dropped on
		// the floor on every request, so the incident it exists to prevent was
		// never actually being prevented. It is kept for any API consumer that
		// reads it, but derived from the SAME value as the step above so the
		// two can never disagree.
		if ($dwangsomActive === true) {
			$response['dwangsom_callback_secret_configured'] = $dwangsomSecretDone;
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
				'dossiq',
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

			// A seeder that touched NOTHING has not done the step. It used to
			// be marked done regardless, which made the affordance one-shot and
			// silently useless: `seedBezwaarBeroepData()` returns
			// `success: true` with every counter at zero when its payload is
			// absent — which is exactly the state it is in, its case types
			// having been parked under `_caseTypes_disabled` in favour of a
			// register.d fragment. So one click reported "Seeded 0 case types,
			// 0 status types, 0 role types (0 skipped)" as a success, recorded
			// the step as complete, and the wizard never offered it again.
			$touched = (int)($result['caseTypes'] ?? 0)
				+ (int)($result['statusTypes'] ?? 0)
				+ (int)($result['roleTypes'] ?? 0)
				+ (int)($result['workflows'] ?? 0)
				+ (int)($result['skipped'] ?? 0);

			$message = sprintf(
				'Seeded %d case types, %d status types, %d role types (%d skipped).',
				($result['caseTypes'] ?? 0),
				($result['statusTypes'] ?? 0),
				($result['roleTypes'] ?? 0),
				($result['skipped'] ?? 0),
			);

			if ($touched === 0) {
				return new DataResponse(
					[
						'success' => false,
						'message' => 'Nothing to seed: the sample-data set is empty. '
							. 'See lib/Settings/bezwaar_seed_data.json.',
						'detail'  => $result,
					],
					Http::STATUS_UNPROCESSABLE_ENTITY,
				);
			}

			$this->appConfig->setValueString('dossiq', 'setup_seed_done', '1');
			return new DataResponse(['success' => true, 'message' => $message, 'detail' => $result]);
		}

		return new DataResponse(
			['success' => false, 'message' => 'Unknown setup action: ' . $actionId],
			Http::STATUS_NOT_FOUND,
		);
	}//end runAction()

	/**
	 * Read a dossiq app-config string value.
	 *
	 * @param string $key The config key.
	 *
	 * @return string The value, or '' when unset.
	 */
	private function config(string $key): string {
		return $this->appConfig->getValueString('dossiq', $key, '');
	}//end config()
}//end class
