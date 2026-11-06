<?php

/**
 * The case deadline calculation is a fallback: the statutory term owns the
 * deadline once there is one, so rematerialising a term-owned case is not a
 * failure.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Tests
 * @package   OCA\Dossiq\Tests\Unit\Settings
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 *
 * @spec openspec/changes/one-term-engine/specs/termijn-binding/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The deadline calculation, the base and the fragment merged.
 */
class CaseDeadlineCalculationIsAFallbackTest extends TestCase {

	/**
	 * The merged deadline calculation is materialised and a fallback.
	 *
	 * @return void
	 */
	public function testTheDeadlineCalculationIsAMaterialisedFallback(): void {
		$root     = __DIR__.'/../../../lib/Settings/';
		$base     = json_decode((string)file_get_contents($root.'dossiq_register.json'), true);
		$fragment = json_decode((string)file_get_contents($root.'register.d/99-case-deadline-is-a-fallback.json'), true);

		$calc   = $base['components']['schemas']['case']['configuration']['x-openregister-calculations']['deadline'];
		$merged = array_merge($calc, $fragment['components']['schemas']['case']['configuration']['x-openregister-calculations']['deadline']);

		$this->assertTrue(condition: $merged['materialise']);
		$this->assertTrue(condition: $merged['fallback']);
		$this->assertArrayHasKey(key: 'dateAdd', array: $merged['expression']);
	}//end testTheDeadlineCalculationIsAMaterialisedFallback()
}//end class
