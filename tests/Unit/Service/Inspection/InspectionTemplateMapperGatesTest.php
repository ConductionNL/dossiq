<?php

/**
 * The photo gates, type aliases, statuses and flat view of an inspection checklist template.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Inspection
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

namespace OCA\Dossiq\Tests\Unit\Service\Inspection;

use OCA\Dossiq\Service\Inspection\InspectionTemplateMapper;
use PHPUnit\Framework\TestCase;

/**
 * Edges of the template mapping that the round-trip tests do not reach.
 *
 * @covers \OCA\Dossiq\Service\Inspection\InspectionTemplateMapper
 */
final class InspectionTemplateMapperGatesTest extends TestCase {

	private InspectionTemplateMapper $mapper;

	/**
	 * A fresh mapper.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->mapper = new InspectionTemplateMapper();
	}//end setUp()

	/**
	 * The one item of a stack A checklist with the given item.
	 *
	 * @param array<string, mixed> $item The item.
	 *
	 * @return array<string, mixed>
	 */
	private function stackAItem(array $item): array {
		return $this->mapper->fromStackA(checklist: ['id' => 'a', 'items' => [$item + ['label' => 'Q']]])['sections'][0]['items'][0];
	}//end stackAItem()

	/**
	 * Every spelling of a photo gate lands on one of the three gates; anything else is never.
	 *
	 * @return void
	 */
	public function testEveryPhotoGateSpellingLandsOnAKnownGate(): void {
		$expected = [
			'if_no' => [true, 'bij_nee', 'if_no'],
			'altijd' => ['altijd'],
			'nooit' => [false, 'nooit', 'always', '', 1, null],
		];

		foreach ($expected as $gate => $values) {
			foreach ($values as $value) {
				$this->assertSame($gate, $this->stackAItem(['photoRequired' => $value])['photoRequired'], var_export($value, true));
			}
		}

		$this->assertSame('altijd', $this->stackAItem(['fotoRequired' => 'altijd'])['photoRequired']);
		$this->assertSame('nooit', $this->stackAItem(['photoRequired' => false, 'fotoRequired' => 'altijd'])['photoRequired']);
	}//end testEveryPhotoGateSpellingLandsOnAKnownGate()

	/**
	 * Every older type name maps onto the response type the schema knows.
	 *
	 * @return void
	 */
	public function testOlderTypeNamesMapOntoSchemaTypes(): void {
		$expected = [
			'yes_no_na' => 'yes_no_na', 'ja_nee_nvt' => 'yes_no_na', 'boolean' => 'yes_no_na',
			'text' => 'text', 'tekst' => 'text', 'getal' => 'getal', 'meting' => 'meting',
			'photo' => 'photo', 'foto' => 'photo', 'meerkeuze' => 'meerkeuze', 'enum' => 'meerkeuze',
			'' => 'text', 'Boolean' => 'text',
		];

		foreach ($expected as $type => $responseType) {
			$this->assertSame($responseType, $this->mapper->responseType(type: (string)$type), (string)$type);
		}
	}//end testOlderTypeNamesMapOntoSchemaTypes()

	/**
	 * Choices become strings in order; blank help text is dropped; a non-numeric weight is dropped.
	 *
	 * @return void
	 */
	public function testItemExtrasAreNormalised(): void {
		$item = $this->stackAItem(['choices' => ['b' => 1, 'a' => 2.5], 'helpText' => '  ', 'parent' => 'p-1', 'weight' => 'heavy', 'order' => '4', 'required' => 'yes']);

		$this->assertSame(['id' => 'Q', 'order' => 4, 'label' => 'Q', 'responseType' => 'text', 'required' => false, 'photoRequired' => 'nooit', 'choices' => ['1', '2.5'], 'parent' => 'p-1'], $item);
		$this->assertArrayNotHasKey('choices', $this->stackAItem(['choices' => []]));
		$this->assertSame(0.5, $this->stackAItem(['weight' => '0.5'])['weight']);
		$this->assertSame('Uitleg', $this->stackAItem(['helpText' => 'Uitleg'])['helpText']);
	}//end testItemExtrasAreNormalised()

	/**
	 * A stack A checklist without a status is a draft; its version is at least one; no case type, no key.
	 *
	 * @return void
	 */
	public function testAStackAChecklistDefaultsToADraftOfVersionOne(): void {
		$template = $this->mapper->fromStackA(checklist: ['uuid' => 'u-9', 'name' => 'N', 'version' => 0, 'items' => 'not a list']);

		$this->assertSame(
			['name' => 'N', 'version' => 1, 'status' => 'draft', 'active' => false, 'sections' => [['order' => 1, 'name' => 'N', 'items' => []]], 'legacyRef' => 'inspectieChecklist/u-9'],
			$template
		);
		$this->assertSame('active', $this->mapper->fromStackA(checklist: ['status' => 'active'])['status']);
		$this->assertTrue($this->mapper->fromStackA(checklist: ['status' => 'active'])['active']);
	}//end testAStackAChecklistDefaultsToADraftOfVersionOne()

	/**
	 * An empty @self id falls back to the object's own id.
	 *
	 * @return void
	 */
	public function testAnEmptySelfIdFallsBackToTheObjectId(): void {
		$this->assertSame('inspectieChecklist/a-2', $this->mapper->fromStackA(checklist: ['@self' => ['id' => ''], 'id' => 'a-2'])['legacyRef']);
		$this->assertSame('', $this->mapper->toFlat(template: ['@self' => 'x'])['id']);
	}//end testAnEmptySelfIdFallsBackToTheObjectId()

	/**
	 * A flat view that says whether it is active keeps that; inactive is retired.
	 *
	 * @return void
	 */
	public function testAFlatViewThatNamesActiveKeepsIt(): void {
		$inactive = $this->mapper->fromFlat(flat: ['name' => 'N', 'active' => false, 'items' => []]);
		$active   = $this->mapper->fromFlat(flat: ['name' => 'N', 'active' => true, 'items' => []]);

		$this->assertSame(['retired', false], [$inactive['status'], $inactive['active']]);
		$this->assertSame(['active', true], [$active['status'], $active['active']]);
	}//end testAFlatViewThatNamesActiveKeepsIt()

	/**
	 * The flat view reads every section, says photoRequired only for a gate other than never, and defaults to a draft.
	 *
	 * @return void
	 */
	public function testTheFlatViewReadsEverySection(): void {
		$flat = $this->mapper->toFlat(
			template: [
				'id' => 't-2',
				'version' => '3',
				'sections' => [
					['items' => [['id' => 'a', 'label' => 'A', 'responseType' => 'getal', 'photoRequired' => 'nooit']]],
					['items' => [['id' => 'b', 'label' => 'B', 'photoRequired' => 'altijd'], 'stray']],
					'not a section',
				],
			]
		);

		$this->assertSame(['id' => 't-2', 'name' => '', 'caseTypeRef' => '', 'version' => 3, 'active' => false, 'status' => 'draft'], array_diff_key($flat, ['items' => true]));
		$this->assertSame(
			[
				['id' => 'a', 'label' => 'A', 'responseType' => 'getal', 'photoRequired' => false, 'question' => 'A', 'type' => 'getal'],
				['id' => 'b', 'label' => 'B', 'photoRequired' => true, 'question' => 'B', 'type' => ''],
			],
			$flat['items']
		);
	}//end testTheFlatViewReadsEverySection()
}//end class
