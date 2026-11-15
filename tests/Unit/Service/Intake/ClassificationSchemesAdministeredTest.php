<?php

/**
 * The classification schemes an administrator adds next to the shipped one.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Service\Intake
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 *
 * @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Intake;

use OCA\Dossiq\Service\Intake\ClassificationSchemes;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Intake\ClassificationSchemes
 */
class ClassificationSchemesAdministeredTest extends TestCase {

	/**
	 * The schemes, over this stored administrator setting.
	 *
	 * @param string $stored The stored JSON.
	 *
	 * @return ClassificationSchemes
	 */
	private function schemes(string $stored): ClassificationSchemes {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->expects(self::atLeastOnce())->method('getValueString')
			->with('dossiq', 'case_classification_schemes', '')
			->willReturn($stored);

		return new ClassificationSchemes($appConfig);
	}//end schemes()

	/**
	 * A blank scheme name resolves: a field that names no scheme is not refused for it.
	 *
	 * @return void
	 */
	public function testABlankSchemeResolves(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->expects(self::never())->method('getValueString');

		self::assertTrue((new ClassificationSchemes($appConfig))->resolves(scheme: '  '));
	}//end testABlankSchemeResolves()

	/**
	 * A setting that is no JSON object adds nothing to the shipped scheme.
	 *
	 * @return void
	 */
	public function testASettingThatIsNoObjectAddsNothing(): void {
		self::assertSame(ClassificationSchemes::SHIPPED, $this->schemes(stored: 'not json')->schemes());
		self::assertSame(ClassificationSchemes::SHIPPED, $this->schemes(stored: '"text"')->schemes());
	}//end testASettingThatIsNoObjectAddsNothing()

	/**
	 * Administered schemes are tidied: nameless ones dropped, values trimmed and deduplicated, a non-list empty.
	 *
	 * @return void
	 */
	public function testAdministeredSchemesAreTidied(): void {
		$schemes = $this->schemes(
			stored: json_encode(
				[
					'0' => ['numbered'],
					'  ' => ['blank'],
					' zone ' => [' noord ', 'zuid', 'noord', '', 5, null],
					'flat' => 'not a list',
				]
			)
		);

		self::assertSame(
			ClassificationSchemes::SHIPPED + ['zone' => ['noord', 'zuid'], 'flat' => []],
			$schemes->schemes()
		);
		self::assertTrue($schemes->allows(scheme: 'zone', value: ' zuid '));
		self::assertFalse($schemes->allows(scheme: 'zone', value: 'oost'));
		// A scheme with no values constrains nothing.
		self::assertTrue($schemes->allows(scheme: 'flat', value: 'anything'));
		// A scheme nobody declared has no values, and so constrains nothing either.
		self::assertSame([], $schemes->valuesOf(scheme: 'unknown'));
		self::assertTrue($schemes->allows(scheme: 'unknown', value: 'anything'));
		self::assertSame(['noord', 'zuid'], $schemes->valuesOf(scheme: ' zone '));
	}//end testAdministeredSchemesAreTidied()
}//end class
