<?php

/**
 * BrcImmutabilityRules Unit Tests
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

use OCA\Dossiq\Service\Zgw\BrcImmutabilityRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a besluit may not change, on the rule group itself.
 *
 * @covers \OCA\Dossiq\Service\Zgw\BrcImmutabilityRules
 * @uses \OCA\Dossiq\Service\ZgwRulesBase
 * @uses \OCA\Dossiq\Service\Zgw\ZgwRuleLookups
 * @uses \OCA\Dossiq\Service\Zgw\ZgwRuleResults
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 * @uses \OCA\Dossiq\Service\FieldValidator
 */
class BrcImmutabilityRulesTest extends TestCase {
	use MakesZrcRuleGroup;

	private const TYPE = '22222222-2222-4222-8222-222222222222';

	private const OTHER = '44444444-4444-4444-8444-444444444444';

	private const STORED = ['decisionType' => self::TYPE, 'title' => 'B-1', 'responsibleOrganisation' => '000000000'];

	/**
	 * A body that changes one immutable field, and the field the refusal names.
	 *
	 * @return array<string, array{0: array, 1: string}>
	 */
	public static function changes(): array {
		return [
			'besluittype' => [['besluittype' => 'https://ztc.example.com/besluittypen/' . self::OTHER], 'besluittype'],
			'identificatie' => [['identificatie' => 'B-2'], 'identificatie'],
			'organisatie' => [['verantwoordelijkeOrganisatie' => '111111111'], 'verantwoordelijkeOrganisatie'],
			'besluittype first' => [
				['identificatie' => 'B-2', 'besluittype' => 'https://ztc.example.com/besluittypen/' . self::OTHER],
				'besluittype',
			],
			'identificatie before organisatie' => [
				['verantwoordelijkeOrganisatie' => '111111111', 'identificatie' => 'B-2'],
				'identificatie',
			],
		];
	}//end changes()

	/**
	 * Update and patch refuse the same change with the same answer.
	 *
	 * @param array $body The request body.
	 * @param string $field The field the refusal names.
	 *
	 * @return void
	 */
	#[DataProvider('changes')]
	public function testAChangedImmutableFieldIsRefusedOnUpdateAndPatch(array $body, string $field): void {
		$rules = $this->ruleGroup(class: BrcImmutabilityRules::class, objects: null);
		$detail = 'Het veld ' . $field . ' mag niet gewijzigd worden.';
		$expected = [
			'valid' => false,
			'status' => 400,
			'detail' => $detail,
			'invalidParams' => [['name' => $field, 'code' => 'wijzigen-niet-toegelaten', 'reason' => $detail]],
			'enrichedBody' => [],
		];

		$this->assertSame($expected, $rules->rulesBesluitenUpdate(body: $body, existingObject: self::STORED));
		$this->assertSame($expected, $rules->rulesBesluitenPatch(body: $body, existingObject: self::STORED));
	}//end testAChangedImmutableFieldIsRefusedOnUpdateAndPatch()

	/**
	 * The rules read no other object: they answer without a context and never ask for one.
	 *
	 * @return void
	 */
	public function testUnchangedFieldsPassWithoutAnyLookup(): void {
		$rules = $this->ruleGroup(class: BrcImmutabilityRules::class, objects: []);
		$body = [
			'besluittype' => 'https://ztc.example.com/besluittypen/' . self::TYPE,
			'identificatie' => 'B-1',
			'verantwoordelijkeOrganisatie' => '000000000',
		];

		$this->assertSame($body, $rules->rulesBesluitenPatch(body: $body, existingObject: self::STORED)['enrichedBody']);
		$this->assertSame($body, $rules->rulesBesluitenUpdate(body: $body, existingObject: self::STORED)['enrichedBody']);
		$this->assertSame(0, $this->finds);
	}//end testUnchangedFieldsPassWithoutAnyLookup()

	/**
	 * Only a PUT carries the stored identificatie and organisation into the body.
	 *
	 * @return void
	 */
	public function testOnlyUpdateCarriesTheStoredFieldsForward(): void {
		$rules = $this->ruleGroup(class: BrcImmutabilityRules::class, objects: null);

		$this->assertSame(
			['identificatie' => 'B-1', 'verantwoordelijkeOrganisatie' => '000000000'],
			$rules->rulesBesluitenUpdate(body: [], existingObject: self::STORED)['enrichedBody']
		);
		$this->assertSame([], $rules->rulesBesluitenPatch(body: [], existingObject: self::STORED)['enrichedBody']);
		$this->assertSame([], $rules->rulesBesluitenUpdate(body: [], existingObject: null)['enrichedBody']);
	}//end testOnlyUpdateCarriesTheStoredFieldsForward()

	/**
	 * A stored besluittype URL is compared by its uuid, so another host with the same uuid passes.
	 *
	 * @return void
	 */
	public function testBesluittypeIsComparedByUuid(): void {
		$rules = $this->ruleGroup(class: BrcImmutabilityRules::class, objects: null);
		$stored = ['decisionType' => 'https://old.example.com/besluittypen/' . self::TYPE];

		$this->assertTrue(
			$rules->rulesBesluitenPatch(body: ['besluittype' => 'https://new.example.com/bt/' . self::TYPE], existingObject: $stored)['valid']
		);
		$this->assertFalse(
			$rules->rulesBesluitenPatch(body: ['besluittype' => 'https://new.example.com/bt/' . self::OTHER], existingObject: $stored)['valid']
		);
	}//end testBesluittypeIsComparedByUuid()
}//end class
