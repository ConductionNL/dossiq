<?php

/**
 * ZrcCaseRelationRules Unit Tests
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
 * Hoofdzaak and product rules on the class that now owns them.
 *
 * @covers \OCA\Dossiq\Service\Zgw\ZrcCaseRelationRules
 * @uses \OCA\Dossiq\Service\ZgwRulesBase
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 * @uses \OCA\Dossiq\Service\FieldValidator
 */
class ZrcCaseRelationRulesTest extends TestCase {
	use MakesZrcRuleGroup;

	private const HOOFD = '11111111-1111-4111-8111-111111111111';

	private const TYPE = '33333333-3333-4333-8333-333333333333';

	private const HOOFD_URL = 'http://example.com/zaken/' . self::HOOFD;

	private const TYPE_URL = 'http://example.com/zaaktypen/' . self::TYPE;

	/**
	 * The code of a refusal, or null when the rule passed.
	 *
	 * @param array|null $refusal The rule's answer.
	 *
	 * @return string|null
	 */
	private function code(?array $refusal): ?string {
		return $refusal['invalidParams'][0]['code'] ?? null;
	}//end code()

	/**
	 * A zaak without a hoofdzaak passes without a lookup.
	 *
	 * @return void
	 */
	public function testNoHoofdzaakIsNotChecked(): void {
		$rules = $this->ruleGroup(ZrcCaseRelationRules::class, []);

		$this->assertNull($rules->checkHoofdzaak([], null));
		$this->assertNull($rules->checkHoofdzaak(['hoofdzaak' => ''], null));
		$this->assertSame(0, $this->finds);
	}//end testNoHoofdzaakIsNotChecked()

	/**
	 * The self check reads the id from either place a stored zaak carries it, before any lookup.
	 *
	 * @return void
	 */
	public function testItselfAsHoofdzaakIsRefusedBeforeTheLookup(): void {
		$rules = $this->ruleGroup(ZrcCaseRelationRules::class, [self::HOOFD => ['id' => self::HOOFD]]);

		$this->assertSame('self-forbidden', $this->code($rules->checkHoofdzaak(['hoofdzaak' => self::HOOFD_URL], ['id' => self::HOOFD])));
		$this->assertSame('self-forbidden', $this->code($rules->checkHoofdzaak(['hoofdzaak' => self::HOOFD_URL], ['@self' => ['id' => self::HOOFD]])));
		$this->assertSame(0, $this->finds);
		$this->assertNull($rules->checkHoofdzaak(['hoofdzaak' => self::HOOFD_URL], ['id' => 'another']));
	}//end testItselfAsHoofdzaakIsRefusedBeforeTheLookup()

	/**
	 * A hoofdzaak that is itself a deelzaak is refused under each stored spelling of its parent.
	 *
	 * @return void
	 */
	public function testNestingIsReadFromThreeSpellings(): void {
		foreach (['parentCase', 'mainCase', 'hoofdzaak'] as $key) {
			$rules = $this->ruleGroup(ZrcCaseRelationRules::class, [self::HOOFD => [$key => 'http://example.com/zaken/x']]);
			$this->assertSame('deelzaak-als-hoofdzaak', $this->code($rules->checkHoofdzaak(['hoofdzaak' => self::HOOFD_URL], null)), $key);
		}

		$unknown = $this->ruleGroup(ZrcCaseRelationRules::class, []);
		$this->assertSame('does-not-exist', $this->code($unknown->checkHoofdzaak(['hoofdzaak' => self::HOOFD_URL], null)));

		$noContext = $this->ruleGroup(ZrcCaseRelationRules::class, null);
		$this->assertNull($noContext->checkHoofdzaak(['hoofdzaak' => self::HOOFD_URL], null));
		$this->assertSame('bad-url', $this->code($noContext->checkHoofdzaak(['hoofdzaak' => 'zaak 7'], null)));
	}//end testNestingIsReadFromThreeSpellings()

	/**
	 * The offered products are read from three spellings, and from a JSON string.
	 *
	 * @return void
	 */
	public function testOfferedProductsAreReadFromEveryStoredShape(): void {
		$offered = 'https://example.com/product/1';
		$body = ['caseType' => self::TYPE_URL, 'productenOfDiensten' => ['https://example.com/product/2']];
		$shapes = [
			['productsOrServices' => [$offered]],
			['productsAndServices' => [$offered]],
			['productenOfDiensten' => [$offered]],
			['productsOrServices' => json_encode([$offered])],
		];
		foreach ($shapes as $index => $stored) {
			$rules = $this->ruleGroup(ZrcCaseRelationRules::class, [self::TYPE => $stored]);
			$this->assertSame('invalid-products-services', $this->code($rules->validateProductenOfDiensten($body)), 'shape ' . $index);
			$this->assertNull($rules->validateProductenOfDiensten(['productenOfDiensten' => [$offered]] + $body), 'shape ' . $index);
		}
	}//end testOfferedProductsAreReadFromEveryStoredShape()

	/**
	 * Nothing offered, nothing asked or nothing readable: the product rule does not refuse.
	 *
	 * @return void
	 */
	public function testTheProductRuleOnlyBitesWhenTheZaaktypeOffersSome(): void {
		$body = ['caseType' => self::TYPE_URL, 'productenOfDiensten' => ['not a url']];

		foreach ([['productsOrServices' => []], ['productsOrServices' => 'not json'], ['productsOrServices' => 7], []] as $stored) {
			$this->assertNull($this->ruleGroup(ZrcCaseRelationRules::class, [self::TYPE => $stored])->validateProductenOfDiensten($body));
		}

		$offers = $this->ruleGroup(ZrcCaseRelationRules::class, [self::TYPE => ['productsOrServices' => ['https://example.com/product/1']]]);
		$this->assertNull($offers->validateProductenOfDiensten(['caseType' => self::TYPE_URL]));
		$this->assertNull($offers->validateProductenOfDiensten(['caseType' => self::TYPE_URL, 'productenOfDiensten' => 'one']));
		$this->assertNull($offers->validateProductenOfDiensten(['productenOfDiensten' => ['not a url']]));

		$refused = $offers->validateProductenOfDiensten($body);
		$this->assertSame("'not a url' is geen geldige URL.", $refused['invalidParams'][0]['reason']);
	}//end testTheProductRuleOnlyBitesWhenTheZaaktypeOffersSome()
}//end class
