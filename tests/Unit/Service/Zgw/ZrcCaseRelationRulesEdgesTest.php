<?php

/**
 * ZrcCaseRelationRules edges: a zaaktype that cannot be named or found offers no products.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Zgw
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

namespace OCA\Dossiq\Tests\Unit\Service\Zgw;

use OCA\Dossiq\Service\Zgw\ZrcCaseRelationRules;
use PHPUnit\Framework\TestCase;

/**
 * Zrc-015 skips, rather than refuses, when the zaaktype's products cannot be read.
 *
 * The requested product is not even a URL, so any check that did run would refuse it.
 *
 * @covers \OCA\Dossiq\Service\Zgw\ZrcCaseRelationRules
 * @uses \OCA\Dossiq\Service\ZgwRulesBase
 * @uses \OCA\Dossiq\Service\Zgw\ZgwRuleLookups
 * @uses \OCA\Dossiq\Service\Zgw\ZgwRuleResults
 * @uses \OCA\Dossiq\Service\FieldValidator
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */
class ZrcCaseRelationRulesEdgesTest extends TestCase {
	use MakesZrcRuleGroup;

	private const TYPE = '33333333-3333-4333-8333-333333333333';

	private const OFFERS = [self::TYPE => ['productsOrServices' => ['https://example.com/product/1']]];

	/**
	 * A caseType URL without a uuid is not looked up, and the product rule passes.
	 *
	 * @return void
	 */
	public function testACaseTypeUrlWithoutAUuidIsNotLookedUp(): void {
		$rules = $this->ruleGroup(class: ZrcCaseRelationRules::class, objects: self::OFFERS);

		$this->assertNull(
			actual: $rules->validateProductenOfDiensten(['caseType' => 'http://example.com/zaaktypen/geen-uuid', 'productenOfDiensten' => ['not a url']])
		);
		$this->assertSame(expected: 0, actual: $this->finds);
	}//end testACaseTypeUrlWithoutAUuidIsNotLookedUp()

	/**
	 * A zaaktype the store does not have is looked up once, and the product rule passes.
	 *
	 * @return void
	 */
	public function testACaseTypeTheStoreDoesNotHaveOffersNothing(): void {
		$rules = $this->ruleGroup(class: ZrcCaseRelationRules::class, objects: self::OFFERS);
		$other = '44444444-4444-4444-8444-444444444444';

		$this->assertNull(
			actual: $rules->validateProductenOfDiensten(['caseType' => 'http://example.com/zaaktypen/' . $other, 'productenOfDiensten' => ['not a url']])
		);
		$this->assertSame(expected: 1, actual: $this->finds);

		$refused = $rules->validateProductenOfDiensten(
			['caseType' => 'http://example.com/zaaktypen/' . self::TYPE, 'productenOfDiensten' => ['not a url']]
		);
		$this->assertSame(
			expected: 'invalid-products-services',
			actual: $refused['invalidParams'][0]['code'],
			message: 'the control: a zaaktype that is found does refuse'
		);
	}//end testACaseTypeTheStoreDoesNotHaveOffersNothing()
}//end class
