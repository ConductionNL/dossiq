<?php

/**
 * Case type menu entry tests
 *
 * One stored case type row read as the menu shows it.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\CaseType
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

namespace OCA\Dossiq\Tests\Unit\Service\CaseType;

use OCA\Dossiq\Service\CaseType\CaseTypeMenuEntry;
use OCA\Dossiq\Service\Support\TranslatedText;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * The menu entry of a case type row.
 *
 * @covers \OCA\Dossiq\Service\CaseType\CaseTypeMenuEntry
 * @uses \OCA\Dossiq\Service\CaseType\CaseTypeHandling
 * @uses \OCA\Dossiq\Service\Support\LanguageMapText
 * @uses \OCA\Dossiq\Service\Support\TranslatedText
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class CaseTypeMenuEntryTest extends TestCase {

	/**
	 * The entry builder, reading titles in Dutch.
	 *
	 * @return CaseTypeMenuEntry
	 */
	private function entries(): CaseTypeMenuEntry {
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('getLanguageCode')->willReturn('nl');

		return new CaseTypeMenuEntry(text: new TranslatedText(l10n: $l10n));
	}//end entries()

	/**
	 * An entry carries the uuid, the title read for the reader, the identifier,
	 * the trimmed icon and the handling teams; a title that reads empty is the uuid.
	 *
	 * @return void
	 */
	public function testAnEntry(): void {
		$entry = $this->entries()->entry(
			row: [
				'title'      => ['nl' => 'Kapvergunning', 'en' => 'Tree felling'],
				'identifier' => 'KAP',
				'icon'       => ' mdi-tree ',
				'handling'   => ['defaultGroup' => 'groen', 'teams' => ['vergunningen']],
			],
			uuid: 'u-1'
		);

		$this->assertSame(expected: 'u-1', actual: $entry['id']);
		$this->assertSame(expected: 'Kapvergunning', actual: $entry['title']);
		$this->assertSame(expected: 'KAP', actual: $entry['identifier']);
		$this->assertSame(expected: 'mdi-tree', actual: $entry['icon']);
		$this->assertContains(needle: 'vergunningen', haystack: $entry['teams']);

		$bare = $this->entries()->entry(row: ['icon' => 42], uuid: 'u-2');
		$this->assertSame(expected: ['u-2', 'u-2', '', ''], actual: [$bare['id'], $bare['title'], $bare['identifier'], $bare['icon']]);
	}//end testAnEntry()

	/**
	 * The public row drops the identifier and teams, and the icon when empty.
	 *
	 * @return void
	 */
	public function testThePublicRow(): void {
		$entries = $this->entries();

		$this->assertSame(
			expected: ['id' => 'a', 'title' => 'A', 'icon' => 'mdi-a'],
			actual: $entries->publicRow(row: ['id' => 'a', 'title' => 'A', 'icon' => 'mdi-a', 'identifier' => 'X', 'teams' => ['t']])
		);
		$this->assertSame(
			expected: ['id' => 'b', 'title' => 'B'],
			actual: $entries->publicRow(row: ['id' => 'b', 'title' => 'B', 'icon' => ''])
		);
	}//end testThePublicRow()

	/**
	 * An entry reads the description for the reader, trimmed, and the public
	 * row carries it only when there is one.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/saved-filters-manage-and-defaults
	 */
	public function testTheDescriptionIsReadAndCarriedWhenPresent(): void {
		$entries = $this->entries();

		$entry = $entries->entry(
			row: ['title' => 'Kapvergunning', 'description' => ['nl' => ' Een boom kappen ', 'en' => 'Felling a tree']],
			uuid: 'u-1'
		);
		$this->assertSame(expected: 'Een boom kappen', actual: $entry['description']);
		$this->assertSame(expected: '', actual: $entries->entry(row: ['title' => 'Kap'], uuid: 'u-2')['description']);

		$this->assertSame(
			expected: ['id' => 'a', 'title' => 'A', 'description' => 'Een boom kappen'],
			actual: $entries->publicRow(row: ['id' => 'a', 'title' => 'A', 'icon' => '', 'description' => 'Een boom kappen'])
		);
		$this->assertSame(
			expected: ['id' => 'b', 'title' => 'B'],
			actual: $entries->publicRow(row: ['id' => 'b', 'title' => 'B', 'icon' => '', 'description' => ''])
		);
	}//end testTheDescriptionIsReadAndCarriedWhenPresent()

	/**
	 * The uuid is the first usable of `uuid`, `id` and `@self.id`; an integer is text.
	 *
	 * @return void
	 */
	public function testTheUuidOfARow(): void {
		$entries = $this->entries();

		$this->assertSame(expected: 'u', actual: $entries->uuidOf(object: ['uuid' => 'u', 'id' => 'i', '@self' => ['id' => 's']]));
		$this->assertSame(expected: 'i', actual: $entries->uuidOf(object: ['uuid' => '', 'id' => 'i', '@self' => ['id' => 's']]));
		$this->assertSame(expected: 's', actual: $entries->uuidOf(object: ['uuid' => null, 'id' => '', '@self' => ['id' => 's']]));
		$this->assertSame(expected: '12', actual: $entries->uuidOf(object: ['id' => 12]));
		$this->assertNull(actual: $entries->uuidOf(object: ['@self' => 's', 'id' => 1.5]));
		$this->assertNull(actual: $entries->uuidOf(object: []));
	}//end testTheUuidOfARow()
}//end class
