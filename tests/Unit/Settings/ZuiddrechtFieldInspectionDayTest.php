<?php

/**
 * Example data for DqInspectie: Pieter's day in the field. Today's
 * inspections name their checklist, and his Brandveiligheid run on the
 * Kerkplein case is under way with the first seven of 24 questions
 * answered, so the Checklist frame opens at "Vraag 7 van 24".
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Settings
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

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Pieter's inspections of today and the checklist he is working through.
 */
class ZuiddrechtFieldInspectionDayTest extends TestCase {

	private const TEMPLATE_KEY = 'inspectie-checklist-brandveiligheid';

	private const RUN_KEY = 'inspectie-brandveiligheid-pieter';

	/**
	 * The set's objects by key.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function objects(): array {
		$set = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);

		return array_column($set['objects'], null, 'key');
	}//end objects()

	/**
	 * The item ids of a template's sections, in order.
	 *
	 * @param array<int, array<string, mixed>> $sections The sections.
	 *
	 * @return list<string>
	 */
	private function itemIds(array $sections): array {
		$ids = [];
		foreach ($sections as $section) {
			foreach ($section['items'] as $item) {
				$ids[] = (string)$item['id'];
			}
		}

		return $ids;
	}//end itemIds()

	/**
	 * A Brandveiligheid checklist of 24 questions, version 3, with the two the board draws.
	 *
	 * @return void
	 */
	public function testTheFireSafetyChecklistHas24Questions(): void {
		$template = $this->objects()[self::TEMPLATE_KEY];
		$this->assertSame(expected: 'inspectionChecklistTemplate', actual: $template['schema']);
		$this->assertSame(expected: 'Brandveiligheid', actual: $template['data']['name']);
		$this->assertSame(expected: 3, actual: $template['data']['version']);

		$items = array_merge(...array_column($template['data']['sections'], 'items'));
		$this->assertCount(expectedCount: 24, haystack: $items);
		$this->assertCount(expectedCount: 24, haystack: array_unique($this->itemIds(sections: $template['data']['sections'])));
		$this->assertSame(expected: 'Zijn de vluchtwegen vrij?', actual: $items[6]['label']);
		$this->assertSame(expected: 'Werken de noodverlichting en de bordjes?', actual: $items[7]['label']);
	}//end testTheFireSafetyChecklistHas24Questions()

	/**
	 * Pieter's run on the Kerkplein case is in execution, on a snapshot of that
	 * checklist, with questions 1 to 7 answered and question 8 open.
	 *
	 * @return void
	 */
	public function testPietersRunStopsAfterQuestionSeven(): void {
		$objects  = $this->objects();
		$run      = $objects[self::RUN_KEY]['data'];
		$template = $objects[self::TEMPLATE_KEY]['data'];

		$this->assertSame(expected: '{{cast:pieter.uid}}', actual: $run['inspector']);
		$this->assertSame(expected: 'in_execution', actual: $run['status']);
		$this->assertSame(expected: '{{object:parkeren-henk.id}}', actual: $run['case']);
		$this->assertSame(expected: '{{object:'.self::TEMPLATE_KEY.'.id}}', actual: $run['template']);
		$this->assertSame(expected: $template['sections'], actual: $run['templateSnapshot']['sections']);

		$ids = $this->itemIds(sections: $run['templateSnapshot']['sections']);
		$this->assertSame(expected: array_slice($ids, 0, 7), actual: array_column($run['responses'], 'itemId'));
		$this->assertSame(expected: 'nee', actual: $run['responses'][6]['value']);
	}//end testPietersRunStopsAfterQuestionSeven()

	/**
	 * Today's inspections name the checklist they run.
	 *
	 * @return void
	 */
	public function testTodaysInspectionsNameTheirChecklist(): void {
		$objects = $this->objects();
		$this->assertSame(expected: '{{lookup:template}}', actual: $objects['inspectie-vandaag-molenweg']['data']['checklistTemplateRef']);
		$lookup = $objects['inspectie-vandaag-molenweg']['lookups']['template'];
		$this->assertSame(expected: 'bouwtoezicht-fase-3-oplevering', actual: $lookup['where']['seedKey']);
		$kerkplein = $objects['inspectie-vandaag-kerkplein']['data'];
		$this->assertSame(expected: '{{object:'.self::TEMPLATE_KEY.'.id}}', actual: $kerkplein['checklistTemplateRef']);
		$this->assertSame(expected: 'in_progress', actual: $kerkplein['status']);
	}//end testTodaysInspectionsNameTheirChecklist()

	/**
	 * Today's inspections carry the location the day list names them by.
	 *
	 * @return void
	 */
	public function testTodaysInspectionsNameTheirLocation(): void {
		$set  = json_decode(json: (string)file_get_contents(filename: __DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), associative: true);
		$rows = array_filter(array: $set['objects'], callback: static fn (array $o): bool => $o['schema'] === 'fieldInspection');
		$locations = array_column(array: array_column(array: $rows, column_key: 'data'), column_key: 'location');
		$this->assertSame(expected: ['Molenweg 4', 'Kerkplein 7', 'Dorpsstraat 39', 'Havenstraat, speeltuin'], actual: $locations);
	}//end testTodaysInspectionsNameTheirLocation()
}//end class
