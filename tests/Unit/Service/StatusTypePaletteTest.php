<?php

/**
 * Every status dossiq ships takes the colour its boards draw.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/case-types/spec.md#requirement-a-shipped-status-takes-the-colour-its-boards-draw
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\StatusTypePalette;
use PHPUnit\Framework\TestCase;

/**
 * The palette, the shipped rows and the recolour rule.
 */
class StatusTypePaletteTest extends TestCase {

	/**
	 * The repository root.
	 *
	 * @return string The path.
	 */
	private function root(): string {
		return dirname(__DIR__, 3);
	}//end root()

	/**
	 * Every status row the app ships, from the register, its fragments, the
	 * demo data, the packages, the templates and the flow seed.
	 *
	 * @return array<int, array{file: string, row: array<string, mixed>}> The rows.
	 */
	private function shippedStatuses(): array {
		$settings = $this->root() . '/lib/Settings';
		$files = array_merge(
			[$settings . '/dossiq_register.json', $settings . '/case_flow_seed_data.json'],
			glob($settings . '/register.d/*.json'),
			glob($settings . '/demo.d/*.json'),
			glob($settings . '/packages/*/case-type.json'),
			glob($settings . '/templates/*.json')
		);
		$rows = [];
		foreach ($files as $file) {
			$this->collect(node: json_decode((string)file_get_contents($file), true), file: basename($file), rows: $rows);
		}

		return $rows;
	}//end shippedStatuses()

	/**
	 * Walk a parsed file and collect its status rows.
	 *
	 * @param mixed                                                    $node The node.
	 * @param string                                                   $file The file name.
	 * @param array<int, array{file: string, row: array<string, mixed>}> $rows The rows so far.
	 *
	 * @return void
	 */
	private function collect(mixed $node, string $file, array &$rows): void {
		if (is_array($node) === false) {
			return;
		}

		$self = ($node['@self'] ?? null);
		if (is_array($self) === true && ($self['schema'] ?? '') === 'statusType' && isset($node['name']) === true) {
			$rows[] = ['file' => $file, 'row' => $node];
		}

		if (isset($node['statusTypes']) === true && is_array($node['statusTypes']) === true) {
			foreach ($node['statusTypes'] as $status) {
				if (is_array($status) === true && isset($status['name']) === true) {
					$rows[] = ['file' => $file, 'row' => $status];
				}
			}
		}

		foreach ($node as $child) {
			$this->collect(node: $child, file: $file, rows: $rows);
		}
	}//end collect()

	/**
	 * Every shipped status names the palette's colour for its name.
	 *
	 * @return void
	 */
	public function testEveryShippedStatusHasItsBoardColour(): void {
		$rows = $this->shippedStatuses();
		$this->assertGreaterThan(150, count($rows), 'too few shipped statuses were read for this to mean anything');

		foreach ($rows as $entry) {
			$name = $entry['row']['name'];
			// A translatable name may ship language-keyed; the Dutch value is its source.
			if (is_array($name) === true) {
				$name = (string)($name['nl'] ?? '');
			}

			$this->assertArrayHasKey($name, StatusTypePalette::COLOURS, $entry['file'] . ': ' . $name . ' has no board colour');
			$this->assertSame(StatusTypePalette::COLOURS[$name], ($entry['row']['colour'] ?? null), $entry['file'] . ': ' . $name);
		}
	}//end testEveryShippedStatusHasItsBoardColour()

	/**
	 * Every palette colour is one the status schema allows.
	 *
	 * @return void
	 */
	public function testThePaletteStaysInTheSchemaHueList(): void {
		$register = json_decode((string)file_get_contents($this->root() . '/lib/Settings/dossiq_register.json'), true);
		$hues = $register['components']['schemas']['statusType']['properties']['colour']['enum'];
		foreach (StatusTypePalette::COLOURS as $name => $colour) {
			$this->assertContains($colour, $hues, $name);
		}
	}//end testThePaletteStaysInTheSchemaHueList()

	/**
	 * The boards' majority colours hold.
	 *
	 * @return void
	 */
	public function testTheBoardsColours(): void {
		$expected = [
			'Ontvangen' => 'blue',
			'In behandeling' => 'purple',
			'Beoordelen documenten' => 'purple',
			'Lakken / Anonimiseren' => 'orange',
			'Besluit' => 'teal',
			'Publicatie' => 'green',
			'Afgehandeld' => 'green',
		];
		foreach ($expected as $name => $colour) {
			$this->assertSame($colour, StatusTypePalette::COLOURS[$name], $name);
		}
	}//end testTheBoardsColours()

	/**
	 * A row without a colour, with grey, or with its shipped colour takes the
	 * board colour; a colour chosen here and an unknown status stay.
	 *
	 * @return void
	 */
	public function testTheRecolourRuleKeepsAChosenColour(): void {
		$palette = new StatusTypePalette();

		$this->assertSame('purple', $palette->recolour(row: ['name' => 'In behandeling']));
		$this->assertSame('purple', $palette->recolour(row: ['name' => 'In behandeling', 'colour' => 'grey']));
		$this->assertSame('purple', $palette->recolour(row: ['name' => 'In behandeling', 'colour' => 'orange', '@self' => ['slug' => 'melding-in-behandeling']]));
		$this->assertSame('teal', $palette->recolour(row: ['name' => 'Besluit', 'colour' => 'purple', '@self' => ['slug' => 'woo-verzoek-besluit']]));

		$this->assertNull($palette->recolour(row: ['name' => 'In behandeling', 'colour' => 'orange', '@self' => ['slug' => 'admin-made']]));
		$this->assertNull($palette->recolour(row: ['name' => 'In behandeling', 'colour' => 'red', '@self' => ['slug' => 'melding-in-behandeling']]));
		$this->assertNull($palette->recolour(row: ['name' => 'In behandeling', 'colour' => 'purple']));
		$this->assertNull($palette->recolour(row: ['name' => 'A status of our own']));
	}//end testTheRecolourRuleKeepsAChosenColour()
}//end class
