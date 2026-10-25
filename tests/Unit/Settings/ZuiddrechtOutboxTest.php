<?php

/**
 * Example data for DqInspectie: Pieter's outbox. Three changes wait to be
 * synchronised, two pending and one in conflict, each named in words by
 * its label ("Molenweg 4, checklist").
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
 * The sync queue rows the Synchronise frame lists.
 */
class ZuiddrechtOutboxTest extends TestCase {

	/**
	 * The set's sync queue rows.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function outbox(): array {
		$set = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);

		return array_values(array_filter($set['objects'], static fn (array $o): bool => $o['schema'] === 'syncQueue'));
	}//end outbox()

	/**
	 * Two pending changes and one conflict, each with a label.
	 *
	 * @return void
	 */
	public function testThreeLabelledChangesWait(): void {
		$rows   = $this->outbox();
		$labels = array_column(array_column($rows, 'data'), 'label');
		$this->assertSame(expected: ['Molenweg 4, checklist', 'Kerkplein 7, foto', 'Molenweg 4, bevinding'], actual: $labels);

		$states = array_count_values(array_column(array_column($rows, 'data'), 'status'));
		$this->assertSame(expected: ['pending' => 2, 'conflict' => 1], actual: $states);
	}//end testThreeLabelledChangesWait()

	/**
	 * The schema declares the label the rows carry.
	 *
	 * @return void
	 */
	public function testTheSchemaDeclaresTheLabel(): void {
		$register = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/register.d/40-mobiel-inspectie-offline.json'), true);
		$schema   = $register['components']['schemas']['syncQueue'];
		$this->assertSame(expected: 'string', actual: $schema['properties']['label']['type']);
		$this->assertNotContains(needle: 'label', haystack: $schema['required']);
		$this->assertSame(expected: 'string', actual: $schema['properties']['summary']['type']);
		$this->assertNotContains(needle: 'summary', haystack: $schema['required']);
		$this->assertSame(expected: '1.2.0', actual: $schema['version']);
	}//end testTheSchemaDeclaresTheLabel()

	/**
	 * The pending rows say what they hold, as the board's outbox cards do.
	 *
	 * @return void
	 */
	public function testThePendingChangesSayWhatTheyHold(): void {
		$summaries = array_column(array_column($this->outbox(), 'data'), 'summary', 'label');
		$this->assertSame(expected: ['Molenweg 4, checklist' => "18 antwoorden, 4 foto's", 'Kerkplein 7, foto' => 'Vraag 7'], actual: $summaries);
	}//end testThePendingChangesSayWhatTheyHold()
}//end class
