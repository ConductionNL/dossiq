<?php

/**
 * ZrcCaseFieldRules Unit Tests
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

use OCA\Dossiq\Service\Zgw\ZrcCaseFieldRules;
use PHPUnit\Framework\TestCase;

/**
 * The sequence of the zaak field rules, and the wiring to the relation rules.
 *
 * The rules themselves are pinned through the ZRC rules service in
 * ZgwZrcCaseFieldRulesTest. This suite covers what the split added: the
 * class is reachable on its own, and the context it is given reaches the
 * relation rules it builds.
 *
 * @covers \OCA\Dossiq\Service\Zgw\ZrcCaseFieldRules
 * @uses \OCA\Dossiq\Service\Zgw\ZrcCaseRelationRules
 * @uses \OCA\Dossiq\Service\ZgwRulesBase
 * @uses \OCA\Dossiq\Service\Zgw\ZgwRuleLookups
 * @uses \OCA\Dossiq\Service\Zgw\ZgwRuleResults
 * @uses \OCA\Dossiq\Service\FieldValidator
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class ZrcCaseFieldRulesTest extends TestCase {
	use MakesZrcRuleGroup;

	private const HOOFD = '11111111-1111-4111-8111-111111111111';

	private const TYPE = '33333333-3333-4333-8333-333333333333';

	/**
	 * A rules result around a body, as the entry rules hand it over.
	 *
	 * @param array $body The enriched body.
	 *
	 * @return array
	 */
	private function passing(array $body): array {
		return ['valid' => true, 'status' => 200, 'detail' => '', 'enrichedBody' => $body];
	}//end passing()

	/**
	 * A body that breaks no rule comes back as it went in.
	 *
	 * @return void
	 */
	public function testACleanBodyPassesUnchanged(): void {
		$result = $this->passing(['omschrijving' => 'x', 'betalingsindicatie' => 'geheel', 'laatsteBetaaldatum' => '2026-01-01']);

		$this->assertSame($result, $this->ruleGroup(ZrcCaseFieldRules::class, null)->validateCaseFields($result, null));
	}//end testACleanBodyPassesUnchanged()

	/**
	 * The context reaches the relation rules: the hoofdzaak lookup and the product lookup both run.
	 *
	 * Without the forwarding in setContext() both would skip, and a deelzaak
	 * of a deelzaak would be accepted in silence.
	 *
	 * @return void
	 */
	public function testTheContextReachesTheRelationRules(): void {
		$rules = $this->ruleGroup(
			ZrcCaseFieldRules::class,
			[
				self::HOOFD => ['parentCase' => 'http://example.com/zaken/x'],
				self::TYPE => ['productsOrServices' => ['https://example.com/product/1']],
			]
		);

		$nested = $rules->validateCaseFields($this->passing(['hoofdzaak' => 'http://example.com/zaken/' . self::HOOFD]), null);
		$this->assertSame('deelzaak-als-hoofdzaak', $nested['invalidParams'][0]['code']);

		$product = $rules->validateCaseFields(
			$this->passing(['caseType' => 'http://example.com/zaaktypen/' . self::TYPE, 'productenOfDiensten' => ['https://example.com/product/2']]),
			null
		);
		$this->assertSame('invalid-products-services', $product['invalidParams'][0]['code']);
		$this->assertSame(2, $this->finds);
	}//end testTheContextReachesTheRelationRules()

	/**
	 * The first refusal wins, in the order of the standard.
	 *
	 * Each step removes the earliest broken rule from a body that breaks five.
	 *
	 * @return void
	 */
	public function testTheFirstRefusalWins(): void {
		$rules = $this->ruleGroup(ZrcCaseFieldRules::class, null);
		$body = [
			'identificatie' => 'Z-2',
			'relevanteAndereZaken' => [['url' => 'nope']],
			'verlenging' => ['duur' => 'P1M'],
			'hoofdzaak' => 'zaak 7',
			'archiefstatus' => 'gearchiveerd',
		];
		$existing = ['identifier' => 'Z-1'];
		$steps = [
			['identificatie', 'identificatie'],
			['relevanteAndereZaken', 'relevanteAndereZaken.0.url'],
			['verlenging', 'verlenging.reden'],
			['hoofdzaak', 'hoofdzaak'],
			['archiefstatus', 'archiefnominatie'],
		];
		foreach ($steps as [$remove, $expectedField]) {
			$refused = $rules->validateCaseFields($this->passing($body), $existing);
			$this->assertFalse($refused['valid'], $remove);
			$this->assertSame($expectedField, $refused['invalidParams'][0]['name'], $remove);
			unset($body[$remove]);
		}

		$this->assertTrue($rules->validateCaseFields($this->passing($body), $existing)['valid']);
	}//end testTheFirstRefusalWins()
}//end class
