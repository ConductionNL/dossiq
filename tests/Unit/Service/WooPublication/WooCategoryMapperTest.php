<?php

/**
 * WooCategoryMapper Unit Tests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\WooPublication
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/woo-publication-via-opencatalogi/design.md#d3
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\WooPublication;

use OCA\Dossiq\Service\WooPublication\WooCategoryMapper;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\WooPublication\WooCategoryMapper
 */
class WooCategoryMapperTest extends TestCase {

	/**
	 * A WOO-besluit decision maps to infocat014 with the official TOOI URI.
	 *
	 * @return void
	 */
	public function testWooBesluitMapsToInfocat014(): void {
		$mapper = new WooCategoryMapper();

		$category = $mapper->forDecision(['decisionType' => 'WOO-besluit']);

		$this->assertSame('infocat014', $category['code']);
		$this->assertSame('Woo-verzoeken en -besluiten', $category['label']);
		$this->assertSame('https://identifier.overheid.nl/tooi/def/thes/kern/c_3baef532', $category['uri']);
	}//end testWooBesluitMapsToInfocat014()

	/**
	 * An unmapped decisionType falls back to the default Woo category, never null.
	 *
	 * @return void
	 */
	public function testUnmappedDecisionTypeFallsBackToDefault(): void {
		$mapper = new WooCategoryMapper();

		$category = $mapper->forDecision(['decisionType' => 'some-other-type']);

		$this->assertSame('infocat014', $category['code']);
	}//end testUnmappedDecisionTypeFallsBackToDefault()

	/**
	 * A decision with no decisionType at all still resolves (never throws/null).
	 *
	 * @return void
	 */
	public function testMissingDecisionTypeFallsBackToDefault(): void {
		$mapper = new WooCategoryMapper();

		$category = $mapper->forDecision([]);

		$this->assertSame('infocat014', $category['code']);
	}//end testMissingDecisionTypeFallsBackToDefault()
}//end class
