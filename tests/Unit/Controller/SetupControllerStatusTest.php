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
use OCA\Dossiq\Service\SeedDataService;
use OCA\Dossiq\Service\SettingsService;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SetupController::status().
 *
 * @covers \OCA\Dossiq\Controller\SetupController
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
	 * @param array<string,string> $config             App-config values.
	 * @param bool                 $openRegisterOnline Whether OpenRegister is available.
	 * @param array<string,mixed>|null $seedResult     What the seeder returns, when it is asked.
	 *
	 * @return array{controller: SetupController, written: array<string,string>} The controller and a
	 *   live view of every app-config value it wrote.
	 */
	private function build(array $config, bool $openRegisterOnline = true, ?array $seedResult = null): array {
		$written = [];

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')
			->willReturnCallback(
				static function (string $app, string $key, string $default = '') use ($config): string {
					return $config[$key] ?? $default;
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

		$seeder = $this->createMock(SeedDataService::class);
		if ($seedResult !== null) {
			$seeder->method('seedBezwaarBeroepData')->willReturn($seedResult);
		}

		$controller = new SetupController(
			appName: 'dossiq',
			request: $this->createMock(IRequest::class),
			appConfig: $appConfig,
			settingsService: $settings,
			seedDataService: $seeder,
		);

		return ['controller' => $controller, 'written' => &$written];

	}//end build()

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
	 * The optional seed step must be reported as not done, not omitted.
	 *
	 * This is the exact payload observed on a clean install: the required step
	 * auto-satisfies on app-enable, so `completed` is true, while the demo
	 * data has never been loaded. The wizard offering `seed` is the app's only
	 * demo-data affordance, so an omitted or misreported step here is the
	 * difference between an app that can be filled and one that cannot.
	 *
	 * @return void
	 */
	public function testSeedIsReportedAsNotDoneOnACleanInstall(): void {
		$data = $this->controller($this->provisioned())->status()->getData();

		$this->assertTrue($data['completed'], 'completed describes REQUIRED steps, and the required one is done');
		$this->assertArrayHasKey('demo-data', $data['steps'], 'an omitted step is invisible to the wizard');
		$this->assertFalse($data['steps']['demo-data']['done']);
		$this->assertTrue($data['steps']['register-check']['done']);

	}//end testSeedIsReportedAsNotDoneOnACleanInstall()

	/**
	 * Every step the manifest declares must appear in the payload.
	 *
	 * Read straight from the shipped manifest rather than from a literal list,
	 * so adding a setup step without reporting it fails here instead of
	 * silently producing a step no wizard can ever prompt for.
	 *
	 * @return void
	 */
	public function testEveryActionableManifestStepIsReported(): void {
		$manifest = json_decode(file_get_contents(__DIR__ . '/../../../src/manifest.json'), true);
		$declared = [];
		foreach (($manifest['setup']['steps'] ?? []) as $step) {
			// `info` and `summary` carry no work, so the server has nothing to
			// report for them by design.
			if (in_array($step['type'], ['info', 'summary'], true) === true) {
				continue;
			}

			$declared[] = $step['id'];
		}

		$this->assertNotEmpty($declared, 'the manifest must declare actionable setup steps');

		$reported = array_keys($this->controller($this->provisioned())->status()->getData()['steps']);
		$this->assertEqualsCanonicalizing($declared, $reported);

	}//end testEveryActionableManifestStepIsReported()

	/**
	 * The seed step flips to done once the seeder has run.
	 *
	 * @return void
	 */
	public function testSeedIsDoneOnceItHasRun(): void {
		$data = $this->controller($this->provisioned() + ['setup_seed_done' => '1'])->status()->getData();

		$this->assertTrue($data['steps']['demo-data']['done']);

	}//end testSeedIsDoneOnceItHasRun()

	/**
	 * An unprovisioned register blocks the app.
	 *
	 * @return void
	 */
	public function testRegisterCheckIsUnmetWithoutARegister(): void {
		$data = $this->controller([])->status()->getData();

		$this->assertFalse($data['completed']);
		$this->assertFalse($data['steps']['register-check']['done']);

	}//end testRegisterCheckIsUnmetWithoutARegister()

	/**
	 * OpenRegister being unreachable is itself an unmet required step.
	 *
	 * @return void
	 */
	public function testRegisterCheckIsUnmetWhenOpenRegisterIsUnavailable(): void {
		$data = $this->controller($this->provisioned(), openRegisterOnline: false)->status()->getData();

		$this->assertFalse($data['steps']['register-check']['done']);

	}//end testRegisterCheckIsUnmetWhenOpenRegisterIsUnavailable()

	/**
	 * With the payout integration configured and no signing secret, the step
	 * is outstanding.
	 *
	 * DwangsomPaymentCallbackController cannot verify a callback's origin
	 * without it, so this is the state the warning exists to catch.
	 *
	 * @return void
	 */
	public function testDwangsomSecretIsOutstandingWhenThePayoutIntegrationIsConfigured(): void {
		$config = $this->provisioned() + ['dwangsom_uitbetaling_schema' => 'dwangsomUitbetaling'];
		$data   = $this->controller($config)->status()->getData();

		$this->assertFalse($data['steps']['dwangsom-secret']['done']);
		$this->assertFalse($data['dwangsom_callback_secret_configured']);

	}//end testDwangsomSecretIsOutstandingWhenThePayoutIntegrationIsConfigured()

	/**
	 * A configured secret settles the step.
	 *
	 * @return void
	 */
	public function testDwangsomSecretIsDoneOnceSet(): void {
		$config = $this->provisioned() + [
			'dwangsom_uitbetaling_schema' => 'dwangsomUitbetaling',
			'dwangsom_callback_secret'    => 's3cret',
		];
		$data = $this->controller($config)->status()->getData();

		$this->assertTrue($data['steps']['dwangsom-secret']['done']);
		$this->assertTrue($data['dwangsom_callback_secret_configured']);

	}//end testDwangsomSecretIsDoneOnceSet()

	/**
	 * With no payout integration there is no callback to sign, so the step is
	 * settled rather than nagging.
	 *
	 * @return void
	 */
	public function testDwangsomSecretIsSettledWhenThePayoutIntegrationIsNotConfigured(): void {
		$data = $this->controller($this->provisioned())->status()->getData();

		$this->assertTrue($data['steps']['dwangsom-secret']['done']);
		$this->assertArrayNotHasKey(
			'dwangsom_callback_secret_configured',
			$data,
			'the legacy flag stays absent when the capability is off'
		);

	}//end testDwangsomSecretIsSettledWhenThePayoutIntegrationIsNotConfigured()

	/**
	 * The step and the legacy flag must never disagree.
	 *
	 * They are two representations of one fact, and two representations of one
	 * fact drift. Deriving both from the same value is the fix; this pins it.
	 *
	 * @return void
	 */
	public function testTheStepAndTheLegacyFlagAlwaysAgree(): void {
		foreach ([['secret' => ''], ['secret' => 's3cret']] as $case) {
			$config = $this->provisioned() + [
				'dwangsom_uitbetaling_schema' => 'dwangsomUitbetaling',
				'dwangsom_callback_secret'    => $case['secret'],
			];
			$data = $this->controller($config)->status()->getData();

			$this->assertSame(
				$data['steps']['dwangsom-secret']['done'],
				$data['dwangsom_callback_secret_configured']
			);
		}

	}//end testTheStepAndTheLegacyFlagAlwaysAgree()
	/**
	 * A seeder that created nothing has not completed the step.
	 *
	 * `seedBezwaarBeroepData()` returns `success: true` with every counter at
	 * zero when its payload is absent — which is the state it is in, its case
	 * types having been parked under `_caseTypes_disabled` in favour of a
	 * register.d fragment. Recording that as done made the affordance one-shot
	 * and silently useless: one click reported "Seeded 0 case types, 0 status
	 * types, 0 role types (0 skipped)" as a success, marked the step complete,
	 * and the wizard never offered it again.
	 *
	 * @return void
	 */
	public function testASeedThatCreatedNothingDoesNotRecordTheStepAsDone(): void {
		$built = $this->build(
			config: $this->provisioned(),
			seedResult: ['success' => true, 'caseTypes' => 0, 'statusTypes' => 0, 'roleTypes' => 0, 'workflows' => 0, 'skipped' => 0]
		);

		$response = $built['controller']->runAction(actionId: 'demo-data');

		$this->assertSame(422, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
		$this->assertArrayNotHasKey('setup_seed_done', $built['written']);

	}//end testASeedThatCreatedNothingDoesNotRecordTheStepAsDone()

	/**
	 * A seeder that created something completes the step.
	 *
	 * The positive control: without it the assertion above would pass just as
	 * happily if the step could never be recorded at all.
	 *
	 * @return void
	 */
	public function testASeedThatCreatedSomethingRecordsTheStepAsDone(): void {
		$built = $this->build(
			config: $this->provisioned(),
			seedResult: ['success' => true, 'caseTypes' => 3, 'statusTypes' => 9, 'roleTypes' => 2, 'workflows' => 1, 'skipped' => 0]
		);

		$response = $built['controller']->runAction(actionId: 'demo-data');

		$this->assertTrue($response->getData()['success']);
		$this->assertSame('1', $built['written']['setup_seed_done'] ?? null);

	}//end testASeedThatCreatedSomethingRecordsTheStepAsDone()

	/**
	 * A run that only SKIPPED objects still counts as having done the step:
	 * everything it would have created is already there.
	 *
	 * @return void
	 */
	public function testASeedThatOnlySkippedStillRecordsTheStepAsDone(): void {
		$built = $this->build(
			config: $this->provisioned(),
			seedResult: ['success' => true, 'caseTypes' => 0, 'statusTypes' => 0, 'roleTypes' => 0, 'workflows' => 0, 'skipped' => 12]
		);

		$response = $built['controller']->runAction(actionId: 'demo-data');

		$this->assertTrue($response->getData()['success']);
		$this->assertSame('1', $built['written']['setup_seed_done'] ?? null);

	}//end testASeedThatOnlySkippedStillRecordsTheStepAsDone()

	/**
	 * The pre-rename `seed` action id still runs the step.
	 *
	 * ADR-111 rule 4 renamed this step to `demo-data` so it can lead the
	 * wizard. The action id is a POST route (`/api/setup/action/{actionId}`),
	 * not just an internal label, so the rename is a public-surface change: a
	 * wizard a user left half-finished, or anything holding the old URL, keeps
	 * posting `seed`. Without the alias that becomes a silent 400 and the demo
	 * data never lands, which presents as "the button does nothing" — the
	 * hardest kind of failure to trace back to a rename.
	 *
	 * @return void
	 */
	public function testTheOldSeedActionIdStillRunsTheStep(): void {
		$built = $this->build(
			config: $this->provisioned(),
			seedResult: ['success' => true, 'caseTypes' => 3, 'statusTypes' => 9, 'roleTypes' => 2, 'workflows' => 1, 'skipped' => 0]
		);

		$response = $built['controller']->runAction(actionId: 'seed');

		$this->assertTrue(
			$response->getData()['success'],
			'the pre-rename action id must keep working, or a half-finished wizard 400s'
		);
		$this->assertSame('1', $built['written']['setup_seed_done'] ?? null);

	}//end testTheOldSeedActionIdStillRunsTheStep()
}//end class
