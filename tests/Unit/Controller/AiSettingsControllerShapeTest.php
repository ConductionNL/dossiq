<?php

/**
 * AI settings response-shape unit tests.
 *
 * THE ASSERTION THAT CARRIES THE REQUIREMENT is `testStoredOffFlagsAreReported
 * AsOff()`: a flag stored as OFF must come back as `false`. A test that only
 * checked the ON case would have passed against the defect, because the defect
 * reported everything as on.
 *
 * What the defect was. `AiSettingsController::getSettings()` answered the
 * settings FLAT, while `AiSettingsTab.vue` read `response.settings` and merged
 * whatever it found over hard-coded defaults of `true` for all six feature
 * toggles and for `ai_pii_stripping`. `response.settings` was always
 * `undefined`, so the merge was always a merge of `{}`, and the administrator
 * was shown every switch ON regardless of what was stored — including the PII
 * stripping switch, whose whole job is to report whether personal data is being
 * scrubbed out of prompts before they leave the instance.
 *
 * TRUE-POSITIVE CONTROL, run rather than predicted. With `getSettings()` stashed
 * back to `new JSONResponse($settings)`, `testSettingsAreReturnedUnderASettings
 * Key` fails with "Failed asserting that an array has the key 'settings'". With
 * `getAiSettings()` stashed back to its string-returning form,
 * `testStoredOffFlagsAreReportedAsOff` fails with "Failed asserting that ''
 * is false" and `testStoredOnFlagsAreReportedAsOn` with "'1' is true".
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/ai-assistance/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\AiSettingsController;
use OCA\Dossiq\Service\Ai\AiAuditLog;
use OCA\Dossiq\Service\Ai\AiEndpointGuard;
use OCA\Dossiq\Service\Ai\AiModelIdentity;
use OCA\Dossiq\Service\Ai\AiPiiRedactor;
use OCA\Dossiq\Service\Ai\AiPromptFactory;
use OCA\Dossiq\Service\AiService;
use OCA\Dossiq\Service\SettingsService;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The AI settings endpoint reports what is STORED, in the shape the admin tab
 * reads.
 *
 * @covers \OCA\Dossiq\Controller\AiSettingsController
 * @covers \OCA\Dossiq\Service\AiService
 *
 * @uses \OCA\Dossiq\Service\Ai\AiAuditLog
 * @uses \OCA\Dossiq\Service\Ai\AiEndpointGuard
 * @uses \OCA\Dossiq\Service\Ai\AiModelIdentity
 * @uses \OCA\Dossiq\Service\Ai\AiPromptFactory
 */
class AiSettingsControllerShapeTest extends TestCase {

	/**
	 * Every on/off setting the admin tab draws as a switch.
	 *
	 * @var string[]
	 */
	private const SWITCH_KEYS = [
		'ai_enabled',
		'ai_feature_classification',
		'ai_feature_extraction',
		'ai_feature_qa',
		'ai_feature_summary',
		'ai_feature_routing',
		'ai_feature_decision_support',
		'ai_pii_stripping',
		'ai_dpia_acknowledged',
	];

	/**
	 * Build a controller over an app-config stubbed with the given stored values.
	 *
	 * @param array<string, string> $stored The stored app-config values.
	 *
	 * @return AiSettingsController The controller under test.
	 */
	private function controller(array $stored): AiSettingsController {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')
			->willReturnCallback(
				static function (string $app, string $key, string $default) use ($stored): string {
					return ($stored[$key] ?? $default);
				}
			);

		$logger = $this->createMock(LoggerInterface::class);

		$aiService = new AiService(
			appConfig: $appConfig,
			prompts: new AiPromptFactory(),
			pii: new AiPiiRedactor(),
			endpointGuard: new AiEndpointGuard($logger),
			audit: new AiAuditLog($appConfig, $this->createMock(ContainerInterface::class), $logger),
			modelIdentity: new AiModelIdentity($appConfig),
			logger: $logger,
		);

		return new AiSettingsController(
			appName: 'dossiq',
			request: $this->createMock(IRequest::class),
			aiService: $aiService,
			settingsService: $this->createMock(SettingsService::class),
		);
	}//end controller()

	/**
	 * The settings arrive under a `settings` key — the one the admin tab reads.
	 *
	 * @return void
	 */
	public function testSettingsAreReturnedUnderASettingsKey(): void {
		$body = $this->controller(stored: [])->getSettings()->getData();

		$this->assertIsArray($body);
		$this->assertArrayHasKey('settings', $body);
		$this->assertIsArray($body['settings']);
		$this->assertArrayHasKey('ai_pii_stripping', $body['settings']);
	}//end testSettingsAreReturnedUnderASettingsKey()

	/**
	 * A switch stored as OFF is reported as off.
	 *
	 * The assertion the whole defect turns on. `ai_pii_stripping` is included
	 * with an EXPLICIT stored `''` rather than by omission, because its stored
	 * default is `'1'` — omitting it would test the default, not the off state.
	 *
	 * @return void
	 */
	public function testStoredOffFlagsAreReportedAsOff(): void {
		$stored = [];
		foreach (self::SWITCH_KEYS as $key) {
			$stored[$key] = '';
		}

		$settings = $this->controller(stored: $stored)->getSettings()->getData()['settings'];

		foreach (self::SWITCH_KEYS as $key) {
			$this->assertFalse($settings[$key], $key . ' is stored off and must report false');
		}
	}//end testStoredOffFlagsAreReportedAsOff()

	/**
	 * A switch stored as ON is reported as on.
	 *
	 * @return void
	 */
	public function testStoredOnFlagsAreReportedAsOn(): void {
		$stored = [];
		foreach (self::SWITCH_KEYS as $key) {
			$stored[$key] = '1';
		}

		$settings = $this->controller(stored: $stored)->getSettings()->getData()['settings'];

		foreach (self::SWITCH_KEYS as $key) {
			$this->assertTrue($settings[$key], $key . ' is stored on and must report true');
		}
	}//end testStoredOnFlagsAreReportedAsOn()

	/**
	 * The stored API key never leaves the server; only whether one exists does.
	 *
	 * @return void
	 */
	public function testTheApiKeyItselfIsNotReturned(): void {
		$settings = $this->controller(stored: ['ai_api_key' => 'sk-secret-value'])
			->getSettings()->getData()['settings'];

		$this->assertArrayNotHasKey('ai_api_key', $settings);
		$this->assertTrue($settings['ai_api_key_set']);
		$this->assertStringNotContainsString('sk-secret-value', json_encode($settings));
	}//end testTheApiKeyItselfIsNotReturned()

	/**
	 * With nothing stored, PII stripping reports ON (its stored default) and
	 * every other switch reports off.
	 *
	 * @return void
	 */
	public function testUnsetFlagsReportTheirStoredDefault(): void {
		$settings = $this->controller(stored: [])->getSettings()->getData()['settings'];

		$this->assertTrue($settings['ai_pii_stripping'], 'PII stripping defaults to on');

		foreach (self::SWITCH_KEYS as $key) {
			if ($key === 'ai_pii_stripping') {
				continue;
			}

			$this->assertFalse($settings[$key], $key . ' has no stored default and must report false');
		}
	}//end testUnsetFlagsReportTheirStoredDefault()
}//end class
