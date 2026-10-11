<?php

/**
 * Mapper tests: every older checklist shape onto the one template, and back.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Inspection
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
 *
 * @spec openspec/changes/inspection-checklists-onto-task/specs/inspection-checklists/spec.md#requirement-inspection-checklist-schema
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Inspection;

use OCA\Dossiq\Service\Inspection\InspectionTemplateMapper;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see InspectionTemplateMapper}.
 *
 * @covers \OCA\Dossiq\Service\Inspection\InspectionTemplateMapper
 */
final class InspectionTemplateMapperTest extends TestCase {

	/**
	 * The mapper under test.
	 *
	 * @var InspectionTemplateMapper
	 */
	private InspectionTemplateMapper $mapper;

	/**
	 * Build the mapper.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->mapper = new InspectionTemplateMapper();
	}//end setUp()

	/**
	 * Stack A: one section, items keyed by label, archived becomes retired.
	 *
	 * @return void
	 */
	public function testStackAFoldsIntoOneSectionKeyedByLabel(): void {
		$template = $this->mapper->fromStackA(
			checklist: [
				'@self' => ['id' => 'a-1'],
				'name' => 'Fundering',
				'caseType' => 'ct-1',
				'version' => 3,
				'status' => 'archived',
				'items' => [
					['label' => 'Wapening conform', 'type' => 'yes_no_na', 'required' => true, 'photoRequired' => true],
					['label' => 'Opmerking', 'type' => 'meerkeuze', 'options' => ['x', 'y']],
				],
			]
		);

		$this->assertSame('inspectieChecklist/a-1', $template['legacyRef']);
		$this->assertSame('retired', $template['status']);
		$this->assertFalse($template['active']);
		$this->assertSame(3, $template['version']);
		$this->assertSame('ct-1', $template['caseType']);
		$items = $template['sections'][0]['items'];
		$this->assertSame('Wapening conform', $items[0]['id']);
		$this->assertSame('if_no', $items[0]['photoRequired']);
		$this->assertTrue($items[0]['required']);
		$this->assertSame(['x', 'y'], $items[1]['choices']);
		$this->assertSame('nooit', $items[1]['photoRequired']);
	}//end testStackAFoldsIntoOneSectionKeyedByLabel()

	/**
	 * Stack C: item rows resolved by uuid, types mapped, weight and parent kept.
	 *
	 * @return void
	 */
	public function testStackCResolvesItemRowsAndKeepsWeightAndParent(): void {
		$converted = $this->mapper->fromStackC(
			checklist: ['id' => 'c-1', 'name' => 'Brandveiligheid', 'caseTypeRef' => 'ct-2', 'active' => true, 'items' => ['i-1', 'i-2']],
			rows: [
				'i-1' => ['question' => 'Nooduitgang vrij?', 'type' => 'boolean', 'required' => true, 'weight' => 2],
				'i-2' => ['question' => 'Soort blusser', 'type' => 'enum', 'parent' => 'i-1'],
			]
		);

		$template = $converted['template'];
		$this->assertSame([], $converted['unresolved']);
		$this->assertSame('inspectionChecklist/c-1', $template['legacyRef']);
		$this->assertSame('active', $template['status']);
		$this->assertSame('ct-2', $template['caseType']);
		$items = $template['sections'][0]['items'];
		$this->assertSame(['i-1', 'yes_no_na', 2.0], [$items[0]['id'], $items[0]['responseType'], $items[0]['weight']]);
		$this->assertSame(['i-2', 'meerkeuze', 'i-1'], [$items[1]['id'], $items[1]['responseType'], $items[1]['parent']]);
	}//end testStackCResolvesItemRowsAndKeepsWeightAndParent()

	/**
	 * An item row that cannot be read is named, not folded.
	 *
	 * @return void
	 */
	public function testAnUnreadableItemRowIsNamed(): void {
		$converted = $this->mapper->fromStackC(checklist: ['id' => 'c-1', 'items' => ['i-1', 'gone']], rows: ['i-1' => ['question' => 'Q']]);

		$this->assertSame(['gone'], $converted['unresolved']);
		$this->assertCount(1, $converted['template']['sections'][0]['items']);
	}//end testAnUnreadableItemRowIsNamed()

	/**
	 * The editor's flat view round-trips through a template.
	 *
	 * @return void
	 */
	public function testTheEditorsFlatViewRoundTrips(): void {
		$template = $this->mapper->fromFlat(
			flat: ['name' => 'Oplevering', 'caseTypeRef' => 'ct-3', 'items' => [['question' => 'Afwerking ok?', 'type' => 'boolean', 'required' => true, 'photoRequired' => true]]]
		);
		$this->assertArrayNotHasKey('legacyRef', $template);
		$this->assertSame('active', $template['status']);

		$template['@self'] = ['id' => 't-1'];
		$flat = $this->mapper->toFlat(template: $template);

		$this->assertSame('t-1', $flat['id']);
		$this->assertSame('ct-3', $flat['caseTypeRef']);
		$this->assertTrue($flat['active']);
		$this->assertSame('Afwerking ok?', $flat['items'][0]['question']);
		$this->assertSame('yes_no_na', $flat['items'][0]['type']);
		$this->assertTrue($flat['items'][0]['photoRequired']);
		$this->assertSame('item-1', $flat['items'][0]['id']);
	}//end testTheEditorsFlatViewRoundTrips()

	/**
	 * An unknown older type becomes text rather than a value the schema refuses.
	 *
	 * @return void
	 */
	public function testAnUnknownTypeBecomesText(): void {
		$this->assertSame('text', $this->mapper->responseType(type: 'signature'));
		$this->assertSame('photo', $this->mapper->responseType(type: 'foto'));
	}//end testAnUnknownTypeBecomesText()
}//end class
