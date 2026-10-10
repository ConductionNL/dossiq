<?php

/**
 * Guards the case-type fields the queue thresholds are overridden through.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use OCA\Dossiq\Service\Queue\UrgencyProfile;
use OCA\Dossiq\Service\Settings\ConfigKeys;
use PHPUnit\Framework\TestCase;

/**
 * Reads the shipped register, because a field the schema does not declare is
 * a field OpenRegister drops on save, and the editor would then report a
 * threshold it never stored.
 *
 * @spec openspec/changes/configurable-queue-urgency/specs/case-types/spec.md
 */
class QueueThresholdSchemaTest extends TestCase {

	/**
	 * The case type properties as shipped.
	 *
	 * @var array<string, mixed>
	 */
	private array $properties = [];

	/**
	 * Load the shipped register.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$raw = file_get_contents(__DIR__ . '/../../../lib/Settings/dossiq_register.json');
		$this->assertIsString($raw);
		$register = json_decode($raw, true);
		$this->assertIsArray($register);

		$this->properties = $register['components']['schemas']['caseType']['properties'];
	}//end setUp()

	/**
	 * Both overrides are optional integers with the profile's own bounds.
	 *
	 * @return void
	 */
	public function testBothOverridesAreOptionalBoundedIntegers(): void {
		$expected = [
			'queueCriticalDays' => UrgencyProfile::MAX_CRITICAL_DAYS,
			'queueWarningDays' => UrgencyProfile::MAX_WARNING_DAYS,
		];

		foreach ($expected as $field => $max) {
			$this->assertArrayHasKey($field, $this->properties);
			$this->assertSame('integer', $this->properties[$field]['type']);
			$this->assertSame(0, $this->properties[$field]['minimum']);
			$this->assertSame($max, $this->properties[$field]['maximum']);
		}
	}//end testBothOverridesAreOptionalBoundedIntegers()

	/**
	 * The four admin keys survive the settings write, which is an allowlist.
	 *
	 * @return void
	 */
	public function testTheAdminKeysAreOnTheSettingsAllowlist(): void {
		foreach (['queue_critical_days', 'queue_warning_days', 'queue_priority_weight', 'queue_idle_weight'] as $key) {
			$this->assertContains($key, ConfigKeys::ALL);
		}
	}//end testTheAdminKeysAreOnTheSettingsAllowlist()
}//end class
