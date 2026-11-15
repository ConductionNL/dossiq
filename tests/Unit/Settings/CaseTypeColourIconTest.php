<?php

/**
 * A case type carries the colour and the icon the boards draw it in.
 *
 * The colour takes the same NL Design System hue list as a status colour, and
 * every case type dossiq ships names one; its icon is one the app registers, so
 * the menu and the pills never name an icon that renders nothing.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Settings
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
 * @spec openspec/specs/case-types/spec.md#requirement-a-case-type-carries-a-colour-and-an-icon
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use OCA\Dossiq\Service\CaseTypeViews;
use OCA\Dossiq\Service\Settings\RegisterFragmentMerger;
use PHPUnit\Framework\TestCase;

/**
 * The case type colour and icon, in the schema, the shipped rows and the menu.
 */
class CaseTypeColourIconTest extends TestCase {

	/**
	 * The repository root.
	 *
	 * @return string The path.
	 */
	private function root(): string {
		return dirname(__DIR__, 3);
	}//end root()

	/**
	 * The register as an instance imports it: the base with every fragment.
	 *
	 * @return array<string, mixed> The merged register.
	 */
	private function register(): array {
		$base = json_decode((string)file_get_contents($this->root() . '/lib/Settings/dossiq_register.json'), true);
		[$merged] = (new RegisterFragmentMerger())->merge(base: $base, fragmentDir: $this->root() . '/lib/Settings/register.d');

		return $merged;
	}//end register()

	/**
	 * Every case type row the app ships, by file and slug.
	 *
	 * @return array<string, array<string, mixed>> The rows.
	 */
	private function shippedCaseTypes(): array {
		$files = array_merge(
			[$this->root() . '/lib/Settings/dossiq_register.json'],
			glob($this->root() . '/lib/Settings/register.d/*.json'),
			glob($this->root() . '/lib/Settings/demo.d/*.json')
		);
		$rows = [];
		foreach ($files as $file) {
			$this->collect(node: json_decode((string)file_get_contents($file), true), file: basename($file), rows: $rows);
		}

		return $rows;
	}//end shippedCaseTypes()

	/**
	 * Walk a parsed file and collect the case type rows in it.
	 *
	 * @param mixed                               $node The node.
	 * @param string                              $file The file name.
	 * @param array<string, array<string, mixed>> $rows The rows found so far.
	 *
	 * @return void
	 */
	private function collect(mixed $node, string $file, array &$rows): void {
		if (is_array($node) === false) {
			return;
		}

		$self = ($node['@self'] ?? null);
		if (is_array($self) === true && ($self['schema'] ?? '') === 'caseType' && isset($node['title']) === true) {
			$rows[$file . '::' . ($self['slug'] ?? $node['title'])] = $node;
		}

		foreach ($node as $child) {
			$this->collect(node: $child, file: $file, rows: $rows);
		}
	}//end collect()

	/**
	 * The icon names the app registers in src/icons.js.
	 *
	 * @return array<int, string> The names.
	 */
	private function registeredIcons(): array {
		preg_match_all('/^\t([A-Z][A-Za-z0-9]+),$/m', (string)file_get_contents($this->root() . '/src/icons.js'), $matches);
		$this->assertNotSame([], $matches[1], 'no registered icon could be read, so nothing below is a real check');

		return $matches[1];
	}//end registeredIcons()

	/**
	 * The schema declares an optional colour over the status hue list and an optional icon.
	 *
	 * @return void
	 */
	public function testTheSchemaDeclaresAnOptionalColourAndIcon(): void {
		$schemas = $this->register()['components']['schemas'];
		$caseType = $schemas['caseType'];

		$this->assertSame($schemas['statusType']['properties']['colour']['enum'], $caseType['properties']['colour']['enum']);
		$this->assertSame('string', $caseType['properties']['icon']['type']);
		$this->assertNotContains('colour', ($caseType['required'] ?? []));
		$this->assertNotContains('icon', ($caseType['required'] ?? []));
		$this->assertContains('teal', $caseType['properties']['colour']['enum']);
		$this->assertTrue(version_compare($caseType['version'], '1.25.0', '>'), 'caseType must move past 1.25.0 to be re-imported');
	}//end testTheSchemaDeclaresAnOptionalColourAndIcon()

	/**
	 * The register version moved past the one before this change.
	 *
	 * @return void
	 */
	public function testTheRegisterVersionMoved(): void {
		$version = $this->register()['info']['version'];
		$this->assertTrue(version_compare($version, '0.20.58', '>'), sprintf('the register version must move past 0.20.58, got %s', $version));
	}//end testTheRegisterVersionMoved()

	/**
	 * Every shipped case type names a colour from the list and a registered icon.
	 *
	 * @return void
	 */
	public function testEveryShippedCaseTypeHasAColourAndARegisteredIcon(): void {
		$hues = $this->register()['components']['schemas']['caseType']['properties']['colour']['enum'];
		$icons = $this->registeredIcons();
		$rows = $this->shippedCaseTypes();
		$this->assertGreaterThan(20, count($rows), 'too few shipped case types were read for this to mean anything');

		foreach ($rows as $key => $row) {
			$this->assertContains(($row['colour'] ?? null), $hues, $key . ' names no colour from the list');
			$this->assertContains(($row['icon'] ?? null), $icons, $key . ' names no registered icon');
		}
	}//end testEveryShippedCaseTypeHasAColourAndARegisteredIcon()

	/**
	 * The boards' majority colours and drawn icons hold for the case types they show.
	 *
	 * @return void
	 */
	public function testTheBoardsColoursAndIcons(): void {
		$rows = $this->shippedCaseTypes();

		$this->assertSame(['red', 'FileDocumentOutline'], [$rows['81-woo-verzoek.json::woo-verzoek']['colour'], $rows['81-woo-verzoek.json::woo-verzoek']['icon']]);
		$this->assertSame(['purple', 'Gavel'], [$rows['84-woo-bezwaar-case-type.json::bezwaar']['colour'], $rows['84-woo-bezwaar-case-type.json::bezwaar']['icon']]);
		$this->assertSame('teal', $rows['dossiq_register.json::omgevingsvergunning']['colour']);
		$this->assertSame('orange', $rows['dossiq_register.json::melding-openbare-ruimte']['colour']);
		$this->assertSame('MessageAlertOutline', $rows['dossiq_register.json::klacht-behandeling']['icon']);
	}//end testTheBoardsColoursAndIcons()

	/**
	 * A pinned case type's view and menu entry carry its icon; one without keeps none.
	 *
	 * @return void
	 */
	public function testTheMenuEntryCarriesTheIcon(): void {
		$views = new CaseTypeViews();
		$caseTypes = [
			['id' => 'ct-bezwaar', 'title' => 'Bezwaar', 'icon' => 'Gavel'],
			['id' => 'ct-plain', 'title' => 'Plain'],
		];

		$this->assertSame('Gavel', $views->views(caseTypes: $caseTypes)[0]['icon']);
		$this->assertArrayNotHasKey('icon', $views->views(caseTypes: $caseTypes)[1]);

		$entries = $views->menuEntries(caseTypes: $caseTypes);
		$this->assertSame(['id' => 'default:case-type-ct-bezwaar', 'page' => 'Cases', 'name' => 'Bezwaar', 'icon' => 'Gavel'], $entries[0]);
		$this->assertArrayNotHasKey('icon', $entries[1]);
	}//end testTheMenuEntryCarriesTheIcon()
}//end class
