<?php

/**
 * Example data for DqBeheerinstellingen: AI-assisted processing switched on
 * with a local Ollama model, and mail linked to cases by case number.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec exclude example-set data integrity; no requirement covers the demo fragment
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use OCA\Dossiq\Service\Settings\ConfigKeys;
use PHPUnit\Framework\TestCase;

/**
 * The admin settings the Zuiddrecht set fills in.
 */
class ZuiddrechtAdminSettingsTest extends TestCase {

	/**
	 * The fragment's app config, value by key.
	 *
	 * @return array<string, string>
	 */
	private function appConfig(): array {
		$fragment = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);

		return array_column($fragment['appConfig'], 'value', 'key');
	}//end appConfig()

	/**
	 * The AI and mail keys the fragment writes are ones the settings endpoint saves.
	 *
	 * @return void
	 */
	public function testTheKeysAreDossiqSettings(): void {
		$keys = ['ai_enabled', 'ai_model_type', 'ai_model_url', 'ai_model_name', 'email_case_matching_enabled', 'email_transport'];
		foreach ($keys as $key) {
			$this->assertArrayHasKey(key: $key, array: $this->appConfig());
			$this->assertContains(needle: $key, haystack: ConfigKeys::ALL, message: $key);
		}
	}//end testTheKeysAreDossiqSettings()

	/**
	 * AI is on with the local model the board names; the DPIA is left for the
	 * admin to acknowledge, so no AI feature runs on the demo by itself.
	 *
	 * @return void
	 */
	public function testAiRunsOnTheLocalModel(): void {
		$values = $this->appConfig();

		$this->assertSame(
			expected: ['1', 'local', 'http://ollama.intern:11434', 'llama3.1:8b'],
			actual: [$values['ai_enabled'], $values['ai_model_type'], $values['ai_model_url'], $values['ai_model_name']]
		);
		$this->assertArrayNotHasKey(key: 'ai_dpia_acknowledged', array: $values);
	}//end testAiRunsOnTheLocalModel()

	/**
	 * Mail to zaken@zuiddrecht.nl is linked to its case by the case number.
	 *
	 * @return void
	 */
	public function testMailIsLinkedByCaseNumber(): void {
		$values = $this->appConfig();

		$this->assertSame(expected: ['1', 'zaken@zuiddrecht.nl'], actual: [$values['email_case_matching_enabled'], $values['email_transport']]);
	}//end testMailIsLinkedByCaseNumber()
}//end class
