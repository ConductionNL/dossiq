<?php

/**
 * Dossiq first-time setup contract (ADR-042).
 *
 * Backs the abstract CnSetupWizard: reports per-step completion
 * (`GET /api/setup/status`), persists config values from `choice` / `config-fields`
 * steps (`POST /api/setup/config`), and runs privileged server-side actions —
 * notably installing the on-request case definition package — from `run-action` steps
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

use OCA\Dossiq\Service\CaseDefinition\PackageInstaller;
use OCA\Dossiq\Service\DemoDataService;
use OCA\Dossiq\Service\Setup\ActiveOrganisationProfile;
use OCA\Dossiq\Service\Setup\CaseTypePackageChoice;
use OCA\Dossiq\Service\Setup\FirstRunReadiness;
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
	 * The case definition package the `seed` action installs.
	 *
	 * It ships with `install: on-request`, so no upgrade writes it; this
	 * action and `occ dossiq:package:install bezwaar-beroep` are the two ways in.
	 *
	 * @var string
	 */
	private const SEED_PACKAGE = 'bezwaar-beroep';
	/**
	 * Setup contract version; matches manifest.setup.version.
	 *
	 * @var int
	 */
	private const SETUP_VERSION = 1;
	/**
	 * App-config key recording that the optional demo-data step has been dealt with.
	 *
	 * Records a DECISION, not a state: "installed" and "declined" both set it.
	 * A step that reports itself undone until demo objects exist can never be
	 * completed by an operator who does not want them.
	 *
	 * @var string
	 */
	private const DEMO_DATA_DECIDED_KEY = DemoDataService::DECIDED_KEY;

	/**
	 * App-config key holding the dataset the operator picked.
	 *
	 * The wizard's `choice` step writes it through `POST /api/setup/config`, and
	 * the `run-action` step that follows reads it back. Two steps rather than
	 * one because `CnSetupWizard::runAction()` posts to
	 * `/api/setup/action/{action}` with no body: an action cannot carry the
	 * answer, so the answer has to be stored before the action runs.
	 *
	 * @var string
	 */
	private const DATASET_KEY = 'demo_dataset';

	/**
	 * App-config key holding the setup version the wizard was closed at.
	 *
	 * Written by the `dismiss-setup` action that CnAppRoot posts when the
	 * wizard is closed or finished (`manifest.setup.dismissAction`), read back
	 * by `status()` as `dismissed`. A later setup version opens the wizard again.
	 *
	 * @var string
	 */
	private const DISMISSED_VERSION_KEY = 'setup_dismissed_version';

	/**
	 * Construct the setup controller.
	 *
	 * @param string $appName The app id.
	 * @param IRequest $request The request.
	 * @param IAppConfig $appConfig App-config reader/writer.
	 * @param DemoDataService $demoDataService Demo dataset import (ADR-111 rule 4).
	 * @param SettingsService $settingsService OpenRegister availability + config import.
	 * @param PackageInstaller $packages Installs the case definition package the `seed` action asks for.
	 * @param FirstRunReadiness $readiness The minimum an instance needs, read live.
	 * @param ActiveOrganisationProfile $organisationProfile The active organisation's name and logo (Organisation step).
	 * @param CaseTypePackageChoice $caseTypes The on-request case type packages (Case types step).
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IAppConfig $appConfig,
		private readonly DemoDataService $demoDataService,
		private readonly SettingsService $settingsService,
		private readonly PackageInstaller $packages,
		private readonly FirstRunReadiness $readiness,
		private readonly ActiveOrganisationProfile $organisationProfile,
		private readonly CaseTypePackageChoice $caseTypes,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Report per-step setup status for the wizard.
	 *
	 * No step is required any more. Initialising the register and schemas
	 * moved to the admin settings page (wizard-dataset-card-load): its
	 * Re-import configuration button runs the same forced import the
	 * `init-register` action runs. `registerReady` travels as information,
	 * not as a step.
	 *
	 * @return DataResponse `{ version, completed, registerReady, steps: { <id>: { done } } }`.
	 *
	 * @spec openspec/changes/first-time-setup/specs/first-time-setup/spec.md
	 * @spec openspec/changes/wizard-dataset-card-load/specs/first-time-setup/spec.md
	 * @spec openspec/specs/first-time-setup/spec.md
	 * @spec openspec/specs/first-time-setup/spec.md
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function status(): DataResponse {
		$registerDone = $this->settingsService->isOpenRegisterAvailable() === true
			&& $this->config(key: 'register') !== ''
			&& $this->config(key: 'case_type_schema') !== '';
		// NO `seed` KEY. The wizard no longer declares that step, and the
		// payload is its step contract: reporting a step no manifest renders
		// gives CnSetupWizard something it can never prompt for, and
		// `testEveryActionableManifestStepIsReported` compares the two sets
		// in both directions for exactly that reason. `setup_seed_done` is
		// still written by the `seed` action below, so an operator who runs it
		// over the API keeps a record that it ran.
		//
		// DEALT WITH, not "demo objects exist". An operator who declines demo
		// data has finished the step; re-offering it every visit would make
		// "no thanks" impossible to express.
		$demoDecided = $this->config(key: self::DEMO_DATA_DECIDED_KEY) !== '';
		$pickedDataset = $this->config(key: self::DATASET_KEY);
		// No step is required, so setup is complete by definition. A missing
		// OpenRegister is the manifest's dependency gate, not a wizard step.
		$completed = true;

		// The payout callback secret is no wizard step: it is set under admin
		// settings > Financial integration. Its go-live flag is reported below.
		$payoutActive = $this->config(key: 'payout_schema') !== '';
		$secretConfigured = $this->config(key: 'payout_callback_secret') !== '';
		// Read BEFORE the completion marker is written below, so the visit that
		// finishes the wizard still offers the case types step once.
		$wizardDoneBefore = $this->config(key: 'setup_completed_version') === (string)self::SETUP_VERSION;

		$this->appConfig->setValueString('dossiq', 'setup_completed_version', (string)self::SETUP_VERSION);

		$response = [
			'version' => self::SETUP_VERSION,
			'completed' => $completed,
			'registerReady' => $registerDone,
			// The choice step reads its options from here: it declares
			// `optionsSource: datasets` and no options of its own, so a dataset
			// missing from this list is a dataset nobody can pick.
			'datasets' => $this->demoDataService->listChoices(),
			'caseTypePackages' => $this->caseTypes->options(),
			// Exactly the ids of `manifest.setup.steps`: a step the server
			// never reports stays open and reopens the wizard.
			'steps' => [
				'organisation-details' => ['done' => $this->organisationProfile->isSettled()],
				'house-style' => ['done' => true],
				'case-types' => ['done' => ($this->caseTypes->isDecided() === true || $wizardDoneBefore === true)],
				// A pick without a load still counts: a wizard that predates
				// `loadAction` can only record the pick.
				'demo-data' => ['done' => ($demoDecided === true || $pickedDataset !== '')],
				'done' => ['done' => true],
			],
		];

		$response = array_merge($response, $this->firstRun());

		// The close is recorded on the server, so a new browser does not
		// reopen the wizard. Left out until someone closed it: CnAppRoot then
		// falls back to the per-browser record, as before.
		$dismissedAt = $this->config(key: self::DISMISSED_VERSION_KEY);
		if ($dismissedAt !== '') {
			$response['dismissed'] = (int)$dismissedAt;
		}

		// Financial-integration (payout) capability: surface a
		// missing callback secret before go-live rather than after an
		// incident (enforce-dwangsom-callback-signature spec).
		//
		// This flag is the ORIGINAL surface for that warning and had no reader
		// anywhere in the frontend — it was computed, serialised and dropped on
		// the floor on every request, so the incident it exists to prevent was
		// never actually being prevented. It is kept for any API consumer that
		// reads it, under its original name. It answers a different question from the step: the step
		// says whether the wizard still has to ASK, this says whether a secret
		// IS configured, and only the second one is the go-live warning.
		if ($payoutActive === true) {
			$response['dwangsom_callback_secret_configured'] = $secretConfigured;
		}

		return new DataResponse($response);
	}//end status()

	/**
	 * The first-run report: the minimum this instance needs, and the tour it lost.
	 *
	 * REPORTED, never gated. No wizard step is required: the register is
	 * imported on install and repaired from the admin settings, and an
	 * instance an administrator deliberately leaves half configured is a
	 * legitimate instance. An instance nobody can see the state of is not.
	 *
	 * NOT folded into `steps`. A step is something CnSetupWizard prompts for,
	 * and `testEveryActionableManifestStepIsReported` compares the declared
	 * steps against the reported ones in both directions; a readiness item is
	 * a live read of the tree that no wizard step corresponds to. Putting one
	 * in `steps` would give the wizard five prompts it cannot fulfil.
	 *
	 * The tour steps are here for the same reason the readiness items are: a
	 * step naming a page that is gone is skipped in silence by the runner,
	 * which is how a tour stops teaching half the product with nothing to show
	 * for it.
	 *
	 * Held apart from `status()` because it is a second report rather than
	 * more of the first, and inlining it put that method one line over the
	 * length the house allows.
	 *
	 * @return array{readiness: array<int, array<string, mixed>>, tourSteps: array<int, array<string, mixed>>}
	 *         The readiness items and the broken tour steps.
	 *
	 * @spec openspec/changes/first-run-and-the-tour/specs/first-time-setup/spec.md
	 */
	private function firstRun(): array {
		return [
			'readiness' => $this->readiness->report(),
			'tourSteps' => $this->readiness->brokenTourSteps(),
		];
	}//end firstRun()

	/**
	 * Persist app-config values from a `config-fields` / `choice` step.
	 *
	 * @return DataResponse `{ success }`.
	 *
	 * @spec openspec/changes/first-time-setup/specs/first-time-setup/spec.md
	 * @spec openspec/specs/first-time-setup/spec.md
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function saveConfig(): DataResponse {
		// 🔴 THE DATASET IS VALIDATED BEFORE IT IS STORED. Everything else here
		// is written as posted, because the `config-fields` step declares its
		// own keys and this endpoint cannot know them. The dataset is
		// different: the load step reads it back and hands it to the importer,
		// so an unknown value would surface one step later as a failed import
		// with no clue why.
		$refusal = $this->refuseUnknownDataset();
		if ($refusal !== null) {
			return $refusal;
		}

		// The organisation's name and logo live on the OpenRegister
		// Organisation, not in app config.
		$params = $this->request->getParams();
		$organisationFields = [ActiveOrganisationProfile::NAME_FIELD, ActiveOrganisationProfile::LOGO_FIELD];
		if ($this->organisationProfile->write(fields: array_intersect_key($params, array_flip($organisationFields))) === false) {
			return new DataResponse(
				['success' => false, 'message' => 'The organisation name and logo could not be saved. A logo address starts with http:// or https://.']
			);
		}

		foreach ($params as $key => $value) {
			if (in_array($key, array_merge(['_route'], $organisationFields), true) === true) {
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

		return $this->loadPostedDataset() ?? new DataResponse(['success' => true]);
	}//end saveConfig()

	/**
	 * Act on the Example data pick the wizard posted with Next.
	 *
	 * The step loads on Next (DqInstellen), not from a button on each
	 * choice, so the pick posted here is imported at once, or recorded as
	 * declined. The import is idempotent, so a second Next reports the
	 * objects as already present. A failure
	 * answers 422 so the wizard stays on the step and shows the message.
	 *
	 * @return DataResponse|null The failure, or null when there was nothing to do or the load succeeded.
	 *
	 * @spec openspec/specs/first-time-setup/spec.md
	 */
	private function loadPostedDataset(): ?DataResponse {
		if ($this->request->getParam(self::DATASET_KEY) === null) {
			return null;
		}

		$outcome = $this->loadDataset(actionId: 'load-demo-data');
		if (($outcome->getData()['success'] ?? false) === true) {
			return null;
		}

		return new DataResponse($outcome->getData(), Http::STATUS_UNPROCESSABLE_ENTITY);
	}//end loadPostedDataset()

	/**
	 * Run a privileged server-side setup action.
	 *
	 * @param string $actionId One of `load-demo-data` | `install-demo-data` | `skip-demo-data` | `dismiss-setup` |
	 *                         `init-register` | `seed` | `install-case-types`.
	 *
	 * @return DataResponse `{ success, message, detail }`.
	 *
	 * @spec openspec/changes/first-time-setup/specs/first-time-setup/spec.md
	 * @spec openspec/specs/first-time-setup/spec.md
	 * @spec openspec/specs/first-time-setup/spec.md
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function runAction(string $actionId): DataResponse {
		// `install-demo-data` is the id the step used before it asked WHICH
		// dataset, and it still means "import the one this app ships". Kept so
		// an older manifest, a runbook or a script that posts it keeps working.
		if ($actionId === 'load-demo-data' || $actionId === 'install-demo-data') {
			return $this->loadDataset(actionId: $actionId);
		}

		if ($actionId === 'skip-demo-data') {
			return $this->skipDemoData();
		}

		// The wizard was closed or finished (`manifest.setup.dismissAction`).
		// Only the setup version is stored: the close answers no step, so a
		// demo-data choice or a secret the administrator set stays as it was.
		if ($actionId === 'dismiss-setup') {
			$this->appConfig->setValueString('dossiq', self::DISMISSED_VERSION_KEY, (string)self::SETUP_VERSION);
			return new DataResponse(['success' => true, 'message' => 'The setup wizard stays closed.']);
		}

		// No longer a wizard step: the admin settings page repairs the register
		// (wizard-dataset-card-load). Kept for runbooks and scripts.
		if ($actionId === 'init-register') {
			$this->settingsService->loadConfiguration(force: true);
			return new DataResponse(['success' => true, 'message' => 'Register and schemas initialised.']);
		}

		if ($actionId === 'seed') {
			return $this->installSeedPackage();
		}

		// The Case types step: a card's Load button posts its package as `dataset`.
		if ($actionId === 'install-case-types') {
			$outcome = $this->caseTypes->install(id: (string)$this->request->getParam('dataset', ''));
			if ($outcome['success'] === false) {
				return new DataResponse($outcome, Http::STATUS_UNPROCESSABLE_ENTITY);
			}

			return new DataResponse($outcome);
		}

		return new DataResponse(
			['success' => false, 'message' => 'Unknown setup action: ' . $actionId],
			Http::STATUS_NOT_FOUND,
		);
	}//end runAction()

	/**
	 * Install the package the `seed` action stands for, through the installer.
	 *
	 * Installed and already-current both complete the step: either way the
	 * case types are on this instance. A refusal or a failed write answers 422
	 * with the installer's own message and leaves the step open.
	 *
	 * @return DataResponse `{ success, message, detail }`, where detail is the installer's outcome.
	 *
	 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
	 */
	private function installSeedPackage(): DataResponse {
		$outcome = $this->packages->install(id: self::SEED_PACKAGE);
		$done = in_array($outcome['status'], [PackageInstaller::INSTALLED, PackageInstaller::CURRENT], true);
		if ($done === false) {
			return new DataResponse(
				['success' => false, 'message' => $outcome['message'], 'detail' => $outcome],
				Http::STATUS_UNPROCESSABLE_ENTITY,
			);
		}

		$this->appConfig->setValueString('dossiq', 'setup_seed_done', '1');
		return new DataResponse(['success' => true, 'message' => $outcome['message'], 'detail' => $outcome]);
	}//end installSeedPackage()

	/**
	 * Import the dataset a card's Load button posted as `dataset`, or the
	 * stored pick when nothing is posted (ADR-111 rule 4).
	 *
	 * @param string $actionId The action that asked, which decides whether an
	 *                         unanswered choice is refused or means the shipped set.
	 *
	 * @return DataResponse The outcome, carrying the counts.
	 *
	 * @spec openspec/changes/wizard-dataset-card-load/specs/first-time-setup/spec.md
	 */
	private function loadDataset(string $actionId): DataResponse {
		$picked = $this->config(key: self::DATASET_KEY);

		// The card's Load button names its dataset in the body. An older wizard
		// posts nothing and relies on the pick stored a step earlier. Nothing is
		// stored before the load succeeds: a failed load must leave the step
		// open for an operator who asked for data and got none.
		$posted = $this->request->getParam('dataset');
		if ($posted !== null) {
			$refusal = $this->refuseDataset(value: $posted);
			if ($refusal !== null) {
				return $refusal;
			}

			$picked = (string)$posted;
		}

		// The legacy id carries no answer, so it means the shipped dataset. A
		// caller that posts it has said which one by posting it.
		if ($actionId === 'install-demo-data' && $picked === '') {
			$picked = DemoDataService::DEMO_DATASET;
		}

		// 🔴 NO SILENT DEFAULT. Importing here because the operator clicked Run
		// one step early would plant example objects nobody asked for, which is
		// the failure this whole step exists to avoid.
		if ($picked === '') {
			return new DataResponse(['success' => false, 'message' => 'Pick a dataset first.']);
		}

		if ($picked === DemoDataService::NONE_DATASET) {
			$this->appConfig->setValueString('dossiq', self::DATASET_KEY, DemoDataService::NONE_DATASET);
			$this->appConfig->setValueString('dossiq', self::DEMO_DATA_DECIDED_KEY, 'skipped');

			return new DataResponse(['success' => true, 'message' => 'No example data was loaded.']);
		}

		try {
			$imported = $this->demoDataService->install();
		} catch (\Throwable $e) {
			return new DataResponse(['success' => false, 'message' => $e->getMessage()]);
		}

		// Recorded only after the import actually returned. Marking it first
		// would let a failed install present as a finished step, and an import
		// that stored nothing now throws above rather than returning zeroes.
		// Loading IS choosing the set, so the pick is recorded too.
		$this->appConfig->setValueString('dossiq', self::DATASET_KEY, $picked);
		$this->appConfig->setValueString('dossiq', self::DEMO_DATA_DECIDED_KEY, DemoDataService::DECIDED_INSTALLED);

		// 🔴 THE COUNTS, ALWAYS, AND BOTH OF THEM. "Demo data installed" with no
		// numbers cannot be told apart from an import that wrote nothing — and
		// one number cannot be told apart from a number read out of the file it
		// was asked to import. The landing is stated against the ask.
		//
		// 🔴 THE SCHEMA COUNT IS GONE, BECAUSE IT WAS COUNTING THE DAMAGE.
		// `schemas` is how many schemas the import DEFINED, and this message
		// read it as "how many schemas the objects landed in". Those were the
		// same number only while the demo import was forking a schema set of
		// its own: "across 139 schemas" was the fork, reported as a feature.
		// A demo set defines no schemas now, so the honest sentence is about
		// objects. The full counts stay in `detail`.
		return new DataResponse(
			[
				'success' => true,
				'message' => sprintf(
					'Demo data installed: %d of %d objects stored, %d refused, %d already present.',
					$imported['objects'],
					$imported['requested'],
					$imported['refused'],
					$imported['unchanged']
				),
				'detail'  => $imported,
			]
		);
	}//end loadDataset()

	/**
	 * Refuse a posted dataset id no dataset answers to.
	 *
	 * @param mixed $value The posted value.
	 *
	 * @return DataResponse|null The refusal, or null when the dataset is known.
	 *
	 * @spec openspec/changes/wizard-dataset-card-load/specs/first-time-setup/spec.md
	 */
	private function refuseDataset(mixed $value): ?DataResponse {
		$named = 'that';
		if (is_scalar($value) === true) {
			$named = (string)$value;
		}

		$known = array_column($this->demoDataService->listChoices(), 'id');
		if (is_scalar($value) === true && in_array($named, $known, true) === true) {
			return null;
		}

		return new DataResponse(
			['success' => false, 'message' => 'No dataset is called "' . $named . '".'],
			Http::STATUS_BAD_REQUEST,
		);
	}//end refuseDataset()

	/**
	 * Record that the operator declined the demo dataset.
	 *
	 * Its own action so "no thanks" is a decision the wizard can record. Without
	 * it the only way past the step would be to install demo data, which is
	 * wrong on a production instance.
	 *
	 * @return DataResponse The outcome.
	 *
	 * @spec exclude Demo-data skip action (ADR-111 rule 4); no per-app openspec change yet.
	 */
	private function skipDemoData(): DataResponse {
		// Skipping IS choosing "None", so both keys are written: an older
		// runbook may read either one.
		$this->appConfig->setValueString('dossiq', self::DATASET_KEY, DemoDataService::NONE_DATASET);
		$this->appConfig->setValueString('dossiq', self::DEMO_DATA_DECIDED_KEY, 'skipped');

		return new DataResponse(['success' => true, 'message' => 'No example data was loaded.']);
	}//end skipDemoData()

	/**
	 * Refuse a posted dataset the demo data service does not offer.
	 *
	 * @return DataResponse|null The refusal, or null when no dataset was posted or it is known.
	 */
	private function refuseUnknownDataset(): ?DataResponse {
		$dataset = $this->request->getParam(self::DATASET_KEY);
		if ($dataset !== null) {
			$submitted = $dataset;
			if (is_array($dataset) === true) {
				$submitted = ($dataset[0] ?? null);
			}

			$named = 'that';
			if (is_scalar($submitted) === true) {
				$named = (string)$submitted;
			}

			$known = array_column($this->demoDataService->listChoices(), 'id');
			if (is_scalar($submitted) === false || in_array($named, $known, true) === false) {
				return new DataResponse(
					['success' => false, 'message' => 'No dataset is called "' . $named . '".']
				);
			}
		}

		return null;
	}//end refuseUnknownDataset()

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
