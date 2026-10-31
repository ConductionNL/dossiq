<?php

/**
 * SetupController::status() Unit Tests
 *
 * The wizard's whole behaviour is decided by this payload, and until now
 * nothing tested it. Two things it has to get right:
 *
 * 1. Every declared step must appear in `steps`. CnAppRoot distinguishes "the
 *    server reports this step as not done" from "the server never mentioned
 *    this step"; only the first prompts. A step omitted here is invisible to
 *    the wizard however unfinished it is.
 * 2. `completed` describes REQUIRED steps only. It was read as "nothing left
 *    to do at all", which suppressed the optional `seed` step and left dossiq
 *    with no reachable way to load demo data.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\SetupController;
use OCA\Dossiq\Service\DemoDataService;
use OCA\Dossiq\Service\CaseDefinition\PackageInstaller;
use OCA\Dossiq\Service\Setup\ActiveOrganisationProfile;
use OCA\Dossiq\Service\Setup\CaseTypePackageChoice;
use OCA\Dossiq\Service\Setup\FirstRunReadiness;
use OCA\Dossiq\Service\SettingsService;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SetupController::status().
 *
 * @covers \OCA\Dossiq\Controller\SetupController
 * @uses \OCA\Dossiq\Service\Setup\FirstRunReadiness
 */
class SetupControllerStatusTest extends TestCase {

	/**
	 * Build a controller whose app config answers from a fixed map.
	 *
	 * @param array<string,string> $config             App-config values.
	 * @param bool                 $openRegisterOnline Whether OpenRegister is available.
	 *
	 * @return SetupController The controller under test.
	 */
	private function controller(array $config, bool $openRegisterOnline = true): SetupController {
		return $this->build(config: $config, openRegisterOnline: $openRegisterOnline)['controller'];

	}//end controller()

	/**
	 * Build a controller and hand back its collaborators.
	 *
	 * @param array<string,string>     $config             App-config values.
	 * @param bool                     $openRegisterOnline Whether OpenRegister is available.
	 * @param array<string,mixed>|null $seedResult         What the package installer answers, when it is asked.
	 * @param array<string,mixed>|\Throwable|null $demoResult What the demo import returns, or throws.
	 * @param array<string,mixed>|null $requestParams      Request params, for the config-fields step.
	 * @param ActiveOrganisationProfile|null $profile      The Organisation step's store; a named organisation by default.
	 * @param array<int,array<string,mixed>> $catalogue    The case definition packages the installer lists.
	 *
	 * @return array{controller: SetupController, written: array<string,string>, settings: SettingsService, asked: list<string>} The
	 *   controller, a live view of every app-config value it wrote, its settings collaborator, and the package ids the installer was asked for.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) — one harness, one controller, five collaborators
	 */
	private function build(
		array $config,
		bool $openRegisterOnline = true,
		?array $seedResult = null,
		array|\Throwable|null $demoResult = null,
		?array $requestParams = null,
		?ActiveOrganisationProfile $profile = null,
		array $catalogue = [],
	): array {
		$written = [];
		$asked = [];

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')
			->willReturnCallback(
				static function (string $app, string $key, string $default = '') use ($config, &$written): string {
					return $written[$key] ?? $config[$key] ?? $default;
				}
			);
		$appConfig->method('setValueString')
			->willReturnCallback(
				static function (string $app, string $key, string $value) use (&$written): bool {
					$written[$key] = $value;
					return true;
				}
			);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn($openRegisterOnline);

		$packages = $this->createMock(PackageInstaller::class);
		$packages->method('catalogue')->willReturn($catalogue);
		// ADR-111 added the generated demo dataset as its own service and its
		// own setup step. Since the bezwaar/beroep `seed` step was retired,
		// the demo import IS the app's demo-data affordance, so it is driven
		// here rather than left as a bare constructor argument.
		$demo = $this->createMock(DemoDataService::class);
		// The offered list, so a test about VALIDATING a pick is not silently
		// asserting that an empty list rejects everything.
		$demo->method('listChoices')->willReturn(
			[
				['id' => 'none', 'label' => 'None', 'description' => 'Nothing.', 'objectCount' => 0, 'icon' => 'CloseCircleOutline'],
				['id' => 'demo', 'label' => 'Example data', 'description' => 'Sample values.', 'objectCount' => 6, 'icon' => 'DatabaseOutline'],
			]
		);
		if ($seedResult !== null) {
			$packages->method('install')->willReturnCallback(
				static function (string $id) use ($seedResult, &$asked): array {
					$asked[] = $id;
					return $seedResult;
				}
			);
		}

		if ($demoResult instanceof \Throwable) {
			$demo->method('install')->willThrowException($demoResult);
		} elseif ($demoResult !== null) {
			$demo->method('install')->willReturn($demoResult);
		}

		$request = $this->createMock(IRequest::class);
		if ($requestParams !== null) {
			$request->method('getParams')->willReturn($requestParams);
			// 🔴 BOTH READERS, OR THE VALIDATION IS INVISIBLE TO THE TEST.
			// saveConfig() reads the dataset with getParam() and the rest of
			// the body with getParams(); a fake that answers only the second
			// makes every assertion about the first pass for the wrong reason.
			$request->method('getParam')
				->willReturnCallback(
					static function (string $key, $default = null) use ($requestParams) {
						return ($requestParams[$key] ?? $default);
					}
				);
		}

		$controller = new SetupController(
			appName: 'dossiq',
			request: $request,
			appConfig: $appConfig,
			demoDataService: $demo,
			settingsService: $settings,
			packages: $packages,
			readiness: new FirstRunReadiness(settingsService: $settings, appConfig: $appConfig),
			organisationProfile: ($profile ?? $this->namedOrganisation()),
			caseTypes: new CaseTypePackageChoice(packages: $packages, appConfig: $appConfig),
		);

		return ['controller' => $controller, 'written' => &$written, 'settings' => $settings, 'asked' => &$asked];

	}//end build()

	/**
	 * An organisation profile that reads a named organisation and accepts every write.
	 *
	 * @return ActiveOrganisationProfile The fake.
	 */
	private function namedOrganisation(): ActiveOrganisationProfile {
		$profile = $this->createMock(ActiveOrganisationProfile::class);
		$profile->method('isSettled')->willReturn(true);
		$profile->method('write')->willReturn(true);

		return $profile;

	}//end namedOrganisation()

	/**
	 * A fully-provisioned register with the sample data not yet loaded.
	 *
	 * @return array<string,string> The app-config map.
	 */
	private function provisioned(): array {
		return [
			'register'         => 'dossiq',
			'case_type_schema' => 'caseType',
		];

	}//end provisioned()

	/**
	 * The retired seed step is not reported at all.
	 *
	 * It used to be reported as "not done", which was the honest answer to the
	 * wrong question. The step ran the `seed` action over
	 * the old `bezwaar_seed_data.json`, whose case types were parked under
	 * `_caseTypes_disabled`, so every click answered 422 and the step could
	 * never become done. The manifest no longer declares it, and this payload
	 * is the wizard's step contract: a step reported here but declared nowhere
	 * is one CnSetupWizard can never prompt for.
	 *
	 * The demo-data offer, which is what actually shows a new reader the app
	 * working, is asserted alongside so this cannot pass on a payload that
	 * simply lost its steps.
	 *
	 * @return void
	 */
	public function testTheRetiredSeedStepIsNotReported(): void {
		$data = $this->controller($this->provisioned())->status()->getData();

		$this->assertTrue($data['completed'], 'completed describes REQUIRED steps, and none is left');
		$this->assertArrayNotHasKey('seed', $data['steps'], 'the wizard declares no seed step to report on');
		$this->assertArrayHasKey('demo-data', $data['steps'], 'an omitted step is invisible to the wizard');
		$this->assertArrayNotHasKey('register-check', $data['steps'], 'the register is repaired from the admin page now');
		$this->assertTrue($data['registerReady']);

	}//end testTheRetiredSeedStepIsNotReported()

	/**
	 * Every step the manifest declares must appear in the payload, and
	 * nothing else.
	 *
	 * Read straight from the shipped manifest rather than from a literal list,
	 * so adding a setup step without reporting it fails here instead of
	 * silently producing a step no wizard can ever prompt for. `info` and
	 * `summary` steps are reported too (always done), so the payload names
	 * every manifest step id (wizard-dataset-card-load).
	 *
	 * @return void
	 */
	public function testEveryActionableManifestStepIsReported(): void {
		$manifest = json_decode(file_get_contents(__DIR__ . '/../../../src/manifest.json'), true);
		$declared = array_column(($manifest['setup']['steps'] ?? []), 'id');

		$this->assertNotEmpty($declared, 'the manifest must declare actionable setup steps');

		$reported = array_keys($this->controller($this->provisioned())->status()->getData()['steps']);
		$this->assertEqualsCanonicalizing($declared, $reported);

	}//end testEveryActionableManifestStepIsReported()

	/**
	 * A recorded seed does not resurrect the step in the payload.
	 *
	 * `setup_seed_done` is still written by the `seed` action, which stays
	 * reachable over the API for an operator who un-parks the Dutch profile.
	 * That record must not put a step back into the wizard's contract: the
	 * manifest decides which steps exist, and app-config decides nothing.
	 *
	 * @return void
	 */
	public function testARecordedSeedDoesNotResurrectTheStep(): void {
		$data = $this->controller($this->provisioned() + ['setup_seed_done' => '1'])->status()->getData();

		$this->assertArrayNotHasKey('seed', $data['steps']);

	}//end testARecordedSeedDoesNotResurrectTheStep()

	/**
	 * An unprovisioned register no longer holds the wizard open, but the
	 * status still says the register is not ready.
	 *
	 * Initialising the register is an admin settings action now
	 * (wizard-dataset-card-load), so no wizard step is required.
	 *
	 * @return void
	 */
	public function testAMissingRegisterIsReportedNotGated(): void {
		$data = $this->controller([])->status()->getData();

		$this->assertTrue($data['completed']);
		$this->assertFalse($data['registerReady']);
		$this->assertArrayNotHasKey('register-check', $data['steps']);

	}//end testAMissingRegisterIsReportedNotGated()

	/**
	 * OpenRegister being unreachable reads as a register that is not ready.
	 *
	 * @return void
	 */
	public function testTheRegisterIsNotReadyWhenOpenRegisterIsUnavailable(): void {
		$data = $this->controller($this->provisioned(), openRegisterOnline: false)->status()->getData();

		$this->assertFalse($data['registerReady']);

	}//end testTheRegisterIsNotReadyWhenOpenRegisterIsUnavailable()

	/**
	 * The dwangsom secret is not a wizard step: it is a procedure-specific
	 * setting, set under admin settings > Financial integration.
	 *
	 * @return void
	 */
	public function testTheDwangsomSecretIsNotAWizardStep(): void {
		$config = $this->provisioned() + ['payout_schema' => 'dwangsomUitbetaling'];
		$data   = $this->controller($config)->status()->getData();

		$this->assertArrayNotHasKey('dwangsom-secret', $data['steps']);

	}//end testTheDwangsomSecretIsNotAWizardStep()

	/**
	 * The go-live flag still reports a missing secret while the payout
	 * integration is configured, and a configured one once it is set.
	 *
	 * @return void
	 */
	public function testTheGoLiveFlagReportsTheSecret(): void {
		$config = $this->provisioned() + ['payout_schema' => 'dwangsomUitbetaling'];
		$this->assertFalse($this->controller($config)->status()->getData()['dwangsom_callback_secret_configured']);

		$config['payout_callback_secret'] = 's3cret';
		$this->assertTrue($this->controller($config)->status()->getData()['dwangsom_callback_secret_configured']);

	}//end testTheGoLiveFlagReportsTheSecret()

	/**
	 * With no payout integration the go-live flag stays absent.
	 *
	 * @return void
	 */
	public function testTheGoLiveFlagIsAbsentWithoutThePayoutIntegration(): void {
		$data = $this->controller($this->provisioned())->status()->getData();

		$this->assertArrayNotHasKey('dwangsom_callback_secret_configured', $data);

	}//end testTheGoLiveFlagIsAbsentWithoutThePayoutIntegration()

	/**
	 * The completion marker written by THIS call does not settle the case
	 * types step yet.
	 *
	 * status() records completion as a side effect. If the step read that
	 * marker after it was written, the very visit that finishes the wizard
	 * would skip the step without ever offering it.
	 *
	 * @return void
	 */
	public function testTheMarkerWrittenByThisCallDoesNotSettleTheStepYet(): void {
		$built = $this->build(config: $this->provisioned());
		$data  = $built['controller']->status()->getData();

		$this->assertSame('1', $built['written']['setup_completed_version'] ?? null, 'the call records completion');
		$this->assertFalse($data['steps']['case-types']['done'], 'and still offers the step this once');

	}//end testTheMarkerWrittenByThisCallDoesNotSettleTheStepYet()
	/**
	 * The `seed` action installs the on-request package and records the step.
	 *
	 * @return void
	 */
	public function testASeedThatInstalledThePackageRecordsTheStepAsDone(): void {
		$built = $this->build(
			config: $this->provisioned(),
			seedResult: ['status' => PackageInstaller::INSTALLED, 'package' => 'bezwaar-beroep', 'version' => '1.0.0', 'message' => 'Installed.', 'written' => 33, 'skipped' => 0]
		);

		$response = $built['controller']->runAction(actionId: 'seed');

		$this->assertSame(200, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
		$this->assertSame(33, $response->getData()['detail']['written']);
		$this->assertSame('1', $built['written']['setup_seed_done'] ?? null);
		$this->assertSame(['bezwaar-beroep'], $built['asked'], 'the action installs exactly the bezwaar-beroep package');

	}//end testASeedThatInstalledThePackageRecordsTheStepAsDone()

	/**
	 * A package already at this version still completes the step: its case
	 * types are on this instance.
	 *
	 * @return void
	 */
	public function testASeedOfAnAlreadyInstalledPackageStillRecordsTheStepAsDone(): void {
		$built = $this->build(
			config: $this->provisioned(),
			seedResult: ['status' => PackageInstaller::CURRENT, 'package' => 'bezwaar-beroep', 'version' => '1.0.0', 'message' => 'Already installed.']
		);

		$response = $built['controller']->runAction(actionId: 'seed');

		$this->assertTrue($response->getData()['success']);
		$this->assertSame('1', $built['written']['setup_seed_done'] ?? null);

	}//end testASeedOfAnAlreadyInstalledPackageStillRecordsTheStepAsDone()

	/**
	 * A refused or failed install answers 422 with the installer's own message
	 * and leaves the step open.
	 *
	 * @return void
	 */
	public function testARefusedOrFailedSeedReportsTheInstallersOwnMessage(): void {
		foreach ([PackageInstaller::REFUSED, PackageInstaller::FAILED] as $status) {
			$built = $this->build(
				config: $this->provisioned(),
				seedResult: ['status' => $status, 'package' => 'bezwaar-beroep', 'version' => '1.0.0', 'message' => "Package 'bezwaar-beroep' was not installed."]
			);

			$response = $built['controller']->runAction(actionId: 'seed');

			$this->assertSame(422, $response->getStatus(), $status);
			$this->assertFalse($response->getData()['success'], $status);
			$this->assertSame("Package 'bezwaar-beroep' was not installed.", $response->getData()['message'], $status);
			$this->assertArrayNotHasKey('setup_seed_done', $built['written'], $status);
		}

	}//end testARefusedOrFailedSeedReportsTheInstallersOwnMessage()

	/**
	 * An unknown action id answers 404 rather than doing nothing quietly.
	 *
	 * The step that names it is dead either way, but a 404 says so on screen
	 * where a silent 200 would let the wizard record it as finished.
	 *
	 * @return void
	 */
	public function testAnUnknownActionIsRefused(): void {
		$built = $this->build(config: $this->provisioned());

		$response = $built['controller']->runAction(actionId: 'no-such-action');

		$this->assertSame(404, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
		$this->assertStringContainsString('no-such-action', $response->getData()['message']);
		$this->assertSame([], $built['written']);

	}//end testAnUnknownActionIsRefused()

	/**
	 * Initialising the register forces the import.
	 *
	 * `force: true` is the whole point of the step. OpenRegister's importer
	 * version-gates a non-forced import and skips silently when the version
	 * has not moved, so an operator who clicks the button on an instance whose
	 * configuration is stale would be told it succeeded while nothing changed.
	 *
	 * @return void
	 */
	public function testInitialisingTheRegisterForcesTheImport(): void {
		$built = $this->build(config: $this->provisioned());
		$built['settings']->expects($this->once())
			->method('loadConfiguration')
			->with(true);

		$response = $built['controller']->runAction(actionId: 'init-register');

		$this->assertTrue($response->getData()['success']);

	}//end testInitialisingTheRegisterForcesTheImport()

	/**
	 * Installing the demo data reports the COUNTS, and records the decision.
	 *
	 * "Demo data installed" with no numbers cannot be told apart from an
	 * import that wrote nothing, which is the failure this whole step exists
	 * to make visible. With the bezwaar `seed` step retired, this is the app's
	 * only demo-data affordance, so it is the one that has to be right.
	 *
	 * @return void
	 */
	public function testInstallingTheDemoDataReportsTheCountsAndRecordsTheDecision(): void {
		$built = $this->build(
			config: $this->provisioned(),
			// The full shape DemoDataService::install() returns. A narrower
			// double let the controller read two undefined keys and the
			// message render them as empty, which is exactly the "installed
			// with no numbers" state this test exists to forbid.
			demoResult: [
				'objects' => 412,
				'requested' => 420,
				'refused' => 8,
				'unchanged' => 0,
				'registers' => 0,
				'schemas' => 0,
			]
		);

		$response = $built['controller']->runAction(actionId: 'install-demo-data');
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertStringContainsString('412', $data['message']);
		$this->assertStringContainsString('420', $data['message']);
		$this->assertStringContainsString('8 refused', $data['message']);
		$this->assertStringContainsString('0 already present', $data['message']);
		$this->assertSame(412, $data['detail']['objects']);
		$this->assertSame('installed', $built['written']['demo_data_decided'] ?? null);

		// 🔴 THE SCHEMA COUNT IS GONE FROM THE SENTENCE ON PURPOSE. It used to
		// read "stored across %d schemas", filled from `schemas` — how many
		// schemas the IMPORT DEFINED. Those two only ever agreed while the
		// demo import was forking a schema set of its own, so the message was
		// reporting the defect as if it were a feature. A demo set defines no
		// schemas now, and "across 0 schemas" would be worse than silence.
		$this->assertStringNotContainsString(
			'schemas',
			$data['message'],
			'the message must not claim a schema count the import does not have'
		);
		$this->assertSame(0, $data['detail']['schemas'], 'a demo set defines no schema');

	}//end testInstallingTheDemoDataReportsTheCountsAndRecordsTheDecision()

	/**
	 * A failed demo import does not record the step as decided.
	 *
	 * Marking the decision first would let a failed install present as a
	 * finished step, and the operator would never be offered it again.
	 *
	 * @return void
	 */
	public function testAFailedDemoImportIsNotRecordedAsDecided(): void {
		$built = $this->build(
			config: $this->provisioned(),
			demoResult: new \RuntimeException('No demo dataset ships with this app')
		);

		$response = $built['controller']->runAction(actionId: 'install-demo-data');

		$this->assertFalse($response->getData()['success']);
		$this->assertStringContainsString('No demo dataset', $response->getData()['message']);
		$this->assertArrayNotHasKey('demo_data_decided', $built['written']);

	}//end testAFailedDemoImportIsNotRecordedAsDecided()

	/**
	 * Declining the demo data is a decision the wizard can record.
	 *
	 * A step that reports itself undone until demo objects exist can never be
	 * completed by an operator who does not want them, which is every
	 * production install.
	 *
	 * @return void
	 */
	public function testDecliningTheDemoDataFinishesTheStep(): void {
		$built = $this->build(config: $this->provisioned());

		$response = $built['controller']->runAction(actionId: 'skip-demo-data');

		$this->assertTrue($response->getData()['success']);
		$this->assertSame('skipped', $built['written']['demo_data_decided'] ?? null);
		// 🔴 IT HAS TO ANSWER BOTH STEPS. The step split into a choice plus a
		// run-action, and CnAppRoot opens the wizard while ANY optional step is
		// outstanding — so writing only the decision flag would leave the
		// choice open and the wizard covering every page.
		$this->assertSame('none', $built['written']['demo_dataset'] ?? null, 'skipping IS choosing none');

		$done = $this->controller(
			$this->provisioned() + ['demo_data_decided' => 'skipped', 'demo_dataset' => 'none']
		)->status()->getData();
		$this->assertTrue($done['steps']['demo-data']['done'], 'a recorded decline finishes the choice');
		$this->assertArrayNotHasKey('load-demo-data', $done['steps'], 'the cards load themselves; no load step is left');

	}//end testDecliningTheDemoDataFinishesTheStep()

	/**
	 * The status document carries the datasets the choice step offers.
	 *
	 * 🔴 THIS RESPONSE *IS* THE OPTION LIST. The step declares
	 * `optionsSource: datasets` and carries no options of its own, so a dataset
	 * missing here is a dataset nobody can pick.
	 *
	 * @return void
	 */
	public function testStatusCarriesTheOptionListTheChoiceStepReads(): void {
		// What the SERVICE offers is asserted in DemoDataServiceTest, against
		// the real descriptor. What matters here is that the controller passes
		// it through under the key the manifest names: `optionsSource:
		// datasets` reads exactly this, and a step whose source is absent
		// offers nothing at all.
		$data = $this->controller($this->provisioned())->status()->getData();

		$this->assertArrayHasKey('datasets', $data);
		$this->assertIsArray($data['datasets']);

	}//end testStatusCarriesTheOptionListTheChoiceStepReads()

	/**
	 * Running the load step with no dataset picked refuses rather than guessing.
	 *
	 * 🔴 NO SILENT DEFAULT. Importing because the operator clicked Run one step
	 * early would plant example objects nobody asked for.
	 *
	 * @return void
	 */
	public function testLoadingWithoutAChoiceRefusesRatherThanGuessing(): void {
		$built = $this->build(config: $this->provisioned());

		$response = $built['controller']->runAction(actionId: 'load-demo-data');

		$this->assertFalse($response->getData()['success']);
		$this->assertStringContainsString('Pick a dataset', $response->getData()['message']);

	}//end testLoadingWithoutAChoiceRefusesRatherThanGuessing()

	/**
	 * A dataset nobody ships is refused rather than stored.
	 *
	 * Storing it would leave the load step pointing at nothing, so the failure
	 * would surface one step later with no clue why.
	 *
	 * @return void
	 */
	public function testAnUnknownDatasetIsRefusedRatherThanStored(): void {
		$built = $this->build(config: $this->provisioned(), requestParams: ['demo_dataset' => 'atlantis']);

		$response = $built['controller']->saveConfig();

		$this->assertFalse($response->getData()['success']);
		$this->assertSame([], $built['written']);

	}//end testAnUnknownDatasetIsRefusedRatherThanStored()

	/**
	 * A known dataset is stored, and the rest of the body still goes through.
	 *
	 * @return void
	 */
	public function testAKnownDatasetIsStoredAlongsideTheOtherFields(): void {
		$built = $this->build(
			config: $this->provisioned(),
			demoResult: ['objects' => 4, 'requested' => 4, 'refused' => 0, 'unchanged' => 0],
			requestParams: ['demo_dataset' => 'demo', 'some_field' => 'kept']
		);

		$response = $built['controller']->saveConfig();

		$this->assertTrue($response->getData()['success']);
		$this->assertSame('demo', $built['written']['demo_dataset'] ?? null);
		$this->assertSame('kept', $built['written']['some_field'] ?? null);
		$this->assertSame('installed', $built['written']['demo_data_decided'] ?? null, 'Next on the step loads the picked set');

	}//end testAKnownDatasetIsStoredAlongsideTheOtherFields()

	/**
	 * A list is accepted, because the wizard contract allows one.
	 *
	 * The step is not `multiple`, but the same endpoint serves steps that are,
	 * so an array must not reach `(string)` and become "Array".
	 *
	 * @return void
	 */
	public function testAListIsAcceptedBecauseTheWizardContractAllowsOne(): void {
		$built = $this->build(
			config: $this->provisioned(),
			demoResult: ['objects' => 4, 'requested' => 4, 'refused' => 0, 'unchanged' => 0],
			requestParams: ['demo_dataset' => ['demo']]
		);

		$this->assertTrue($built['controller']->saveConfig()->getData()['success']);

	}//end testAListIsAcceptedBecauseTheWizardContractAllowsOne()

	/**
	 * A value that is not a scalar is refused rather than cast.
	 *
	 * The body is whatever the browser posted. A nested array would otherwise
	 * reach `(string)` and raise a fatal.
	 *
	 * @return void
	 */
	public function testAValueThatIsNotAScalarIsRefused(): void {
		$built = $this->build(
			config: $this->provisioned(),
			requestParams: ['demo_dataset' => [['demo']]]
		);

		$this->assertFalse($built['controller']->saveConfig()->getData()['success']);
		$this->assertSame([], $built['written']);

	}//end testAValueThatIsNotAScalarIsRefused()

	/**
	 * Choosing none and then running imports nothing, rather than refusing.
	 *
	 * 🔴 REFUSING WOULD LEAVE THE STEP OPEN. The load step still runs after
	 * "None"; it has to record the decision and import nothing.
	 *
	 * @return void
	 */
	public function testChoosingNoneAndThenRunningImportsNothing(): void {
		$built = $this->build(config: $this->provisioned() + ['demo_dataset' => 'none']);

		$data = $built['controller']->runAction(actionId: 'load-demo-data')->getData();

		$this->assertTrue($data['success']);
		$this->assertStringContainsString('No example data', $data['message']);
		$this->assertSame('skipped', $built['written']['demo_data_decided'] ?? null);

	}//end testChoosingNoneAndThenRunningImportsNothing()

	/**
	 * The legacy action still imports the shipped dataset.
	 *
	 * `install-demo-data` was the id before the step asked WHICH dataset. A
	 * runbook or script that still posts it must keep working, and it names the
	 * shipped set by naming itself.
	 *
	 * @return void
	 */
	public function testTheLegacyActionStillImportsTheShippedDataset(): void {
		$built = $this->build(
			config: $this->provisioned(),
			demoResult: ['objects' => 4, 'requested' => 4, 'refused' => 0, 'unchanged' => 0]
		);

		$data = $built['controller']->runAction(actionId: 'install-demo-data')->getData();

		$this->assertTrue($data['success']);
		$this->assertStringContainsString('4', $data['message']);

	}//end testTheLegacyActionStillImportsTheShippedDataset()

	/**
	 * A config-fields step stores its values, encoding what is not a scalar.
	 *
	 * `_route` is the router's own parameter rather than a field someone
	 * filled in, so storing it would write a junk app-config key on every save.
	 *
	 * @return void
	 */
	public function testAConfigStepStoresItsFieldsAndSkipsTheRouteParameter(): void {
		$built = $this->build(
			config: [],
			requestParams: [
				'_route' => 'dossiq.setup.saveConfig',
				'payout_callback_secret' => 's3cret',
				'retry_delays' => [1, 5, 15],
			]
		);

		$response = $built['controller']->saveConfig();

		$this->assertTrue($response->getData()['success']);
		$this->assertSame(expected: 's3cret', actual: $built['written']['payout_callback_secret'] ?? null);
		$this->assertSame('[1,5,15]', $built['written']['retry_delays'] ?? null);
		$this->assertArrayNotHasKey('_route', $built['written']);

	}//end testAConfigStepStoresItsFieldsAndSkipsTheRouteParameter()

	/**
	 * The card's Load button posts `{ dataset }`; the load records the pick.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/wizard-dataset-card-load/specs/first-time-setup/spec.md
	 */
	public function testTheCardPostsItsDatasetAndTheLoadRecordsTheChoice(): void {
		$built = $this->build(
			config: $this->provisioned(),
			demoResult: ['objects' => 6, 'requested' => 6, 'refused' => 0, 'unchanged' => 0, 'registers' => 0, 'schemas' => 0],
			requestParams: ['dataset' => 'demo'],
		);

		$data = $built['controller']->runAction(actionId: 'load-demo-data')->getData();

		$this->assertTrue($data['success']);
		$this->assertSame('demo', $built['written']['demo_dataset'] ?? null);
		$this->assertSame('installed', $built['written']['demo_data_decided'] ?? null);

	}//end testTheCardPostsItsDatasetAndTheLoadRecordsTheChoice()

	/**
	 * A posted dataset no card offers is refused, and nothing loads.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/wizard-dataset-card-load/specs/first-time-setup/spec.md
	 */
	public function testAnUnknownPostedDatasetIsRefusedAndNothingLoads(): void {
		$built = $this->build(
			config: $this->provisioned() + ['demo_dataset' => 'demo'],
			demoResult: new \RuntimeException('install must not run'),
			requestParams: ['dataset' => 'atlantis'],
		);

		$response = $built['controller']->runAction(actionId: 'load-demo-data');

		$this->assertSame(400, $response->getStatus());
		$this->assertStringContainsString('atlantis', $response->getData()['message']);
		$this->assertSame([], $built['written']);

	}//end testAnUnknownPostedDatasetIsRefusedAndNothingLoads()

	/**
	 * A failed card load stores neither the pick nor the decision.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/wizard-dataset-card-load/specs/first-time-setup/spec.md
	 */
	public function testAFailedCardLoadStoresNothing(): void {
		$built = $this->build(
			config: $this->provisioned(),
			demoResult: new \RuntimeException('OpenRegister is not installed.'),
			requestParams: ['dataset' => 'demo'],
		);

		$data = $built['controller']->runAction(actionId: 'load-demo-data')->getData();

		$this->assertFalse($data['success']);
		$this->assertSame([], $built['written']);

	}//end testAFailedCardLoadStoresNothing()

	/**
	 * Closing the wizard records the setup version and writes nothing else.
	 *
	 * 🔴 NOTHING ELSE. The close is not an answer to any step: writing the
	 * demo-data choice here would record a decision the administrator never
	 * made, and on an instance where they did make one it would overwrite it.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/first-time-setup/spec.md
	 */
	public function testClosingTheWizardRecordsOnlyTheSetupVersion(): void {
		$built = $this->build(config: $this->provisioned(), requestParams: ['finished' => false]);

		$response = $built['controller']->runAction(actionId: 'dismiss-setup');

		$this->assertSame(200, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
		$this->assertSame(['setup_dismissed_version' => '1'], $built['written']);

	}//end testClosingTheWizardRecordsOnlyTheSetupVersion()

	/**
	 * A real demo-data choice survives the close.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/first-time-setup/spec.md
	 */
	public function testARealChoiceSurvivesTheClose(): void {
		$built = $this->build(
			config: $this->provisioned() + ['demo_dataset' => 'demo', 'demo_data_decided' => 'installed'],
			requestParams: ['finished' => true],
		);

		$built['controller']->runAction(actionId: 'dismiss-setup');

		$this->assertArrayNotHasKey('demo_dataset', $built['written']);
		$this->assertArrayNotHasKey('demo_data_decided', $built['written']);

	}//end testARealChoiceSurvivesTheClose()

	/**
	 * The status reports the version the wizard was closed at.
	 *
	 * CnAppRoot keeps the wizard closed when `dismissed` is at least the
	 * manifest `setup.version`, in every browser. An integer, not a string:
	 * the library ignores anything that is not a finite number or `true`.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/first-time-setup/spec.md
	 */
	public function testStatusReportsTheClosedVersion(): void {
		$data = $this->controller($this->provisioned() + ['setup_dismissed_version' => '1'])->status()->getData();

		$this->assertSame(1, $data['dismissed'] ?? null);
		$this->assertFalse($data['steps']['demo-data']['done'], 'the close answers no step');

		$manifest = json_decode(file_get_contents(__DIR__ . '/../../../src/manifest.json'), true);
		$this->assertSame('dismiss-setup', $manifest['setup']['dismissAction'] ?? null);
		$this->assertGreaterThanOrEqual($manifest['setup']['version'], $data['dismissed']);

	}//end testStatusReportsTheClosedVersion()

	/**
	 * Without a close the status carries no `dismissed` key.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/first-time-setup/spec.md
	 */
	public function testNoCloseNoDismissedKey(): void {
		$data = $this->controller($this->provisioned())->status()->getData();

		$this->assertArrayNotHasKey('dismissed', $data);

	}//end testNoCloseNoDismissedKey()
	/**
	 * The board's three opening steps are reported, and the old welcome is gone.
	 *
	 * @return void
	 */
	public function testTheBoardStepsAreReported(): void {
		$data = $this->controller($this->provisioned())->status()->getData();

		$this->assertTrue($data['steps']['organisation-details']['done'], 'a named organisation settles the optional step');
		$this->assertTrue($data['steps']['house-style']['done'], 'the house style is a hand-off, never outstanding');
		$this->assertFalse($data['steps']['case-types']['done'], 'nothing chosen and nothing installed leaves the step open');
		$this->assertArrayNotHasKey('welcome', $data['steps']);

	}//end testTheBoardStepsAreReported()

	/**
	 * An unsettled organisation leaves the Organisation step open.
	 *
	 * @return void
	 */
	public function testAnUnnamedOrganisationLeavesTheStepOpen(): void {
		$profile = $this->createMock(ActiveOrganisationProfile::class);
		$profile->method('isSettled')->willReturn(false);

		$data = $this->build(config: $this->provisioned(), profile: $profile)['controller']->status()->getData();

		$this->assertFalse($data['steps']['organisation-details']['done']);

	}//end testAnUnnamedOrganisationLeavesTheStepOpen()

	/**
	 * The Case types step's cards are the on-request packages, after a none card.
	 *
	 * @return void
	 */
	public function testStatusCarriesTheCaseTypeCards(): void {
		$catalogue = [
			['id' => 'bezwaar-beroep', 'title' => 'Bezwaar en beroep', 'version' => '1.0.0', 'install' => 'on-request', 'installed' => '', 'valid' => true],
			['id' => 'vth', 'title' => 'VTH', 'version' => '1.0.0', 'install' => 'auto', 'installed' => '1.0.0', 'valid' => true],
		];

		$data = $this->build(config: $this->provisioned(), catalogue: $catalogue)['controller']->status()->getData();

		$this->assertSame(['none', 'bezwaar-beroep'], array_column($data['caseTypePackages'], 'id'), 'an auto package is already there and is not offered');

	}//end testStatusCarriesTheCaseTypeCards()

	/**
	 * The organisation fields go to the organisation, never into app config.
	 *
	 * @return void
	 */
	public function testOrganisationFieldsAreWrittenToTheOrganisation(): void {
		$profile = $this->createMock(ActiveOrganisationProfile::class);
		$profile->expects($this->once())
			->method('write')
			->with(['organisation_name' => 'Gemeente Waterland', 'organisation_logo' => ''])
			->willReturn(true);

		$built = $this->build(
			config: $this->provisioned(),
			requestParams: ['organisation_name' => 'Gemeente Waterland', 'organisation_logo' => '', '_route' => 'x'],
			profile: $profile,
		);

		$this->assertTrue($built['controller']->saveConfig()->getData()['success']);
		$this->assertSame([], $built['written'], 'the name and logo live on the OpenRegister organisation');

	}//end testOrganisationFieldsAreWrittenToTheOrganisation()

	/**
	 * A failed organisation write is reported and stores nothing else.
	 *
	 * @return void
	 */
	public function testAFailedOrganisationWriteIsReported(): void {
		$profile = $this->createMock(ActiveOrganisationProfile::class);
		$profile->method('write')->willReturn(false);

		$built = $this->build(
			config: $this->provisioned(),
			requestParams: ['organisation_name' => 'Gemeente Waterland', 'other_key' => 'x'],
			profile: $profile,
		);

		$this->assertFalse($built['controller']->saveConfig()->getData()['success']);
		$this->assertSame([], $built['written']);

	}//end testAFailedOrganisationWriteIsReported()

	/**
	 * A case type card installs its package and records the choice.
	 *
	 * @return void
	 */
	public function testACaseTypeCardInstallsItsPackage(): void {
		$built = $this->build(
			config: $this->provisioned(),
			seedResult: ['status' => PackageInstaller::INSTALLED, 'message' => 'Installed.'],
			requestParams: ['dataset' => 'subsidie'],
			catalogue: [['id' => 'subsidie', 'title' => 'Subsidie', 'version' => '1.0.0', 'install' => 'on-request', 'installed' => '', 'valid' => true]],
		);

		$response = $built['controller']->runAction(actionId: 'install-case-types');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['subsidie'], $built['asked']);
		$this->assertSame('subsidie', $built['written']['case_type_package']);

	}//end testACaseTypeCardInstallsItsPackage()

	/**
	 * A package the step does not offer is refused before the installer is asked.
	 *
	 * @return void
	 */
	public function testAnUnofferedCaseTypePackageIsRefused(): void {
		$built = $this->build(
			config: $this->provisioned(),
			seedResult: ['status' => PackageInstaller::INSTALLED, 'message' => 'Installed.'],
			requestParams: ['dataset' => 'vth'],
			catalogue: [['id' => 'vth', 'title' => 'VTH', 'version' => '1.0.0', 'install' => 'auto', 'installed' => '', 'valid' => true]],
		);

		$response = $built['controller']->runAction(actionId: 'install-case-types');

		$this->assertSame(422, $response->getStatus());
		$this->assertSame([], $built['asked']);
		$this->assertArrayNotHasKey('case_type_package', $built['written']);

	}//end testAnUnofferedCaseTypePackageIsRefused()
	/**
	 * Every setup endpoint is refused to anyone but an admin.
	 *
	 * saveConfig() writes the active OpenRegister organisation and app config,
	 * runAction() installs case type packages. Neither has an in-method check:
	 * the refusal is `#[AuthorizedAdminSetting]`, enforced by the framework
	 * before the body runs, so a non-admin never reaches it. Dropping the
	 * attribute, or adding one that opens the route, fails here.
	 *
	 * @return void
	 */
	public function testEverySetupEndpointRefusesEveryoneButAnAdmin(): void {
		$opening = [
			\OCP\AppFramework\Http\Attribute\NoAdminRequired::class,
			\OCP\AppFramework\Http\Attribute\PublicPage::class,
		];
		foreach (['status', 'saveConfig', 'runAction'] as $endpoint) {
			$method = new \ReflectionMethod(SetupController::class, $endpoint);
			$names = array_map(static fn (\ReflectionAttribute $a): string => $a->getName(), $method->getAttributes());

			$this->assertContains(\OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting::class, $names, $endpoint . '() must be admin-only');
			$this->assertSame([], array_values(array_intersect($opening, $names)), $endpoint . '() must not be opened to non-admins');
			$this->assertStringNotContainsString('@NoAdminRequired', (string)$method->getDocComment());
			$this->assertStringNotContainsString('@PublicPage', (string)$method->getDocComment());
		}

	}//end testEverySetupEndpointRefusesEveryoneButAnAdmin()

	/**
	 * An organisation id in the request never reaches the organisation write.
	 *
	 * Only the name and logo fields are handed on; the profile writes the
	 * active organisation, so a posted id cannot point the write elsewhere.
	 *
	 * @return void
	 */
	public function testAPostedOrganisationIdIsNotPassedOn(): void {
		$profile = $this->createMock(ActiveOrganisationProfile::class);
		$profile->expects($this->once())
			->method('write')
			->with(['organisation_name' => 'Gemeente Waterland'])
			->willReturn(true);

		$built = $this->build(
			config: $this->provisioned(),
			requestParams: ['organisation_name' => 'Gemeente Waterland', 'organisation' => 'another-uuid', 'uuid' => 'another-uuid'],
			profile: $profile,
		);

		$this->assertTrue($built['controller']->saveConfig()->getData()['success']);

	}//end testAPostedOrganisationIdIsNotPassedOn()
	/**
	 * Picking "Start empty" and pressing Next records the decline and imports nothing.
	 *
	 * @return void
	 */
	public function testNextOnStartEmptyRecordsTheDecline(): void {
		$built = $this->build(config: $this->provisioned(), requestParams: ['demo_dataset' => 'none']);

		$this->assertTrue($built['controller']->saveConfig()->getData()['success']);
		$this->assertSame('skipped', $built['written']['demo_data_decided'] ?? null);

	}//end testNextOnStartEmptyRecordsTheDecline()

	/**
	 * A failed load on Next answers 422, so the wizard stays on the step.
	 *
	 * @return void
	 */
	public function testAFailedLoadOnNextKeepsTheWizardOnTheStep(): void {
		$built = $this->build(
			config: $this->provisioned(),
			demoResult: new \RuntimeException('The register is not ready.'),
			requestParams: ['demo_dataset' => 'demo']
		);

		$response = $built['controller']->saveConfig();

		$this->assertSame(422, $response->getStatus());
		$this->assertSame('The register is not ready.', $response->getData()['message']);
		$this->assertArrayNotHasKey('demo_data_decided', $built['written']);

	}//end testAFailedLoadOnNextKeepsTheWizardOnTheStep()
}//end class
