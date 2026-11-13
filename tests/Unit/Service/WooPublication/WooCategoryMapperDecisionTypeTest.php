<?php

/**
 * The decision types the Woo category mapper recognises, by name and by uuid.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\WooPublication
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\WooPublication;

use OCA\Dossiq\Service\WooPublication\WooCategoryMapper;
use PHPUnit\Framework\TestCase;

/**
 * The seeded decision type's uuid, and decision types that are not strings.
 *
 * @covers \OCA\Dossiq\Service\WooPublication\WooCategoryMapper
 */
class WooCategoryMapperDecisionTypeTest extends TestCase {

	private const INFOCAT014 = [
		'code' => 'infocat014',
		'label' => 'Woo-verzoeken en -besluiten',
		'uri' => 'https://identifier.overheid.nl/tooi/def/thes/kern/c_3baef532',
	];

	/**
	 * A decision that carries the seeded WOO-besluit type by uuid gets the whole category.
	 *
	 * @return void
	 */
	public function testTheSeededDecisionTypeUuidMapsToInfocat014(): void {
		$this->assertSame(
			self::INFOCAT014,
			(new WooCategoryMapper())->forDecision(['decisionType' => '3c0f5a00-0000-4000-a000-00000000d001', 'title' => 'Besluit'])
		);
	}//end testTheSeededDecisionTypeUuidMapsToInfocat014()

	/**
	 * Name lookups are exact: another spelling falls back to the same default, whole.
	 *
	 * @return void
	 */
	public function testLookupsAreExactAndFallBackToTheWholeDefault(): void {
		$mapper = new WooCategoryMapper();

		foreach (['woo-besluit', ' WOO-besluit', '3C0F5A00-0000-4000-A000-00000000D001'] as $decisionType) {
			$this->assertSame(self::INFOCAT014, $mapper->forDecision(['decisionType' => $decisionType]), $decisionType);
		}
	}//end testLookupsAreExactAndFallBackToTheWholeDefault()

	/**
	 * A decision type that is null or a number does not throw and resolves to the default.
	 *
	 * @return void
	 */
	public function testANonStringDecisionTypeResolvesToTheDefault(): void {
		$mapper = new WooCategoryMapper();

		$this->assertSame(self::INFOCAT014, $mapper->forDecision(['decisionType' => null]));
		$this->assertSame(self::INFOCAT014, $mapper->forDecision(['decisionType' => 14]));
	}//end testANonStringDecisionTypeResolvesToTheDefault()
}//end class
