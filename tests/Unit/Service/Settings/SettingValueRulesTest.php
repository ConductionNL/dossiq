<?php

/**
 * SettingValueRules tests: the settings the admin tabs save are refused
 * outside their bounds and choices, before anything is written.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Settings;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Settings\ConfigKeys;
use OCA\Dossiq\Service\Settings\SettingValueRules;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SettingValueRules.
 *
 * @spec openspec/changes/admin-tabs-carry-the-settings-board-blocks/specs/tenant-configuration/spec.md
 */
class SettingValueRulesTest extends TestCase {

	/**
	 * The seven keys the admin tabs save are in the allowlist, so a save keeps them.
	 *
	 * @return void
	 */
	public function testTheSevenKeysAreSaved(): void {
		$keys = [
			'queue_in_sight_days',
			'queue_resident_wait_weight',
			'timezone',
			'date_format',
			'feature_new_case_page',
			'publication_template',
			'publication_date_rule',
		];
		foreach ($keys as $key) {
			$this->assertContains(needle: $key, haystack: ConfigKeys::ALL);
		}
	}//end testTheSevenKeysAreSaved()

	/**
	 * The settings save finds the rules by the name it holds.
	 *
	 * @return void
	 */
	public function testTheSettingsSaveNamesTheseRules(): void {
		$this->assertSame(expected: SettingValueRules::class, actual: \OCA\Dossiq\Service\SettingsService::VALUE_RULES);
	}//end testTheSettingsSaveNamesTheseRules()

	/**
	 * Values within their bounds and choices pass.
	 *
	 * @return void
	 */
	public function testValidValuesPass(): void {
		(new SettingValueRules())->assertValid(
			data: [
				'timezone' => 'Europe/Amsterdam',
				'queue_in_sight_days' => '180',
				'queue_resident_wait_weight' => '2.5',
				'date_format' => 'D MMMM YYYY',
				'feature_new_case_page' => 'true',
				'publication_date_rule' => 'first-wednesday',
				'publication_template' => 'bekendmaking-vth',
			]
		);
		$this->addToAssertionCount(count: 1);
	}//end testValidValuesPass()

	/**
	 * Each value outside its rule is refused with a 400 naming what it allows.
	 *
	 * @return void
	 */
	public function testValuesOutsideTheirRuleAreRefused(): void {
		$cases = [
			[['timezone' => 'Mars/Olympus'], 'unknown-timezone', 'Mars/Olympus'],
			[['queue_in_sight_days' => '181'], 'setting-out-of-range', 'queue_in_sight_days: 0 - 180'],
			[['queue_in_sight_days' => '2.5'], 'setting-out-of-range', 'queue_in_sight_days: 0 - 180'],
			[['queue_resident_wait_weight' => 'heavy'], 'setting-out-of-range', 'queue_resident_wait_weight: 0 - 50'],
			[['date_format' => 'MM/DD/YYYY'], 'setting-out-of-range', 'date_format: DD-MM-YYYY, YYYY-MM-DD, D MMMM YYYY'],
			[['publication_date_rule' => 'monday'], 'setting-out-of-range', 'publication_date_rule: first-wednesday, decision-date'],
		];
		foreach ($cases as [$data, $rule, $detail]) {
			try {
				(new SettingValueRules())->assertValid(data: $data);
				$this->fail(message: 'Not refused: '.json_encode($data));
			} catch (RefusedException $e) {
				$this->assertSame(expected: [$rule, $detail, 400], actual: [$e->getRule(), $e->getSentence(), $e->getStatus()]);
			}
		}
	}//end testValuesOutsideTheirRuleAreRefused()
}//end class
