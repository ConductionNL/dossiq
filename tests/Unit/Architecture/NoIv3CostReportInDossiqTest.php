<?php

/**
 * Structural guard: dossiq classifies case types by Iv3 taakveld and reports nothing.
 *
 * ADR-107 makes Shillinq the only Iv3 reporter and the only owner of the
 * taakveld catalogue. Dossiq keeps one thing, the classification
 * `caseType.iv3TaskField`, and exposes it through its register. A taakveld
 * list, an Iv3 route or a cost amount on a case here would be a second answer
 * to a question Shillinq already answers, and the two would drift silently.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Architecture
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
 * @spec openspec/changes/case-costs-reported-per-iv3-taakveld/specs/iv3-taakveld-2023-refinement/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * No Iv3 route, taakveld list or case cost field in dossiq; the field and the card point at Shillinq.
 *
 * @coversNothing
 */
class NoIv3CostReportInDossiqTest extends TestCase {
	/**
	 * The repository root.
	 *
	 * @var string
	 */
	private const ROOT = __DIR__.'/../../..';

	/**
	 * Decode a JSON file of the repository.
	 *
	 * @param string $relative Path from the repository root.
	 *
	 * @return array<string, mixed> The decoded document.
	 */
	private function json(string $relative): array {
		$raw = file_get_contents(filename: self::ROOT.'/'.$relative);
		$this->assertIsString(actual: $raw, message: $relative.' is readable');
		$decoded = json_decode(json: $raw, associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);
		$this->assertIsArray(actual: $decoded);
		return $decoded;
	}//end json()

	/**
	 * A page of the manifest by id.
	 *
	 * @param string $id The page id.
	 *
	 * @return array<string, mixed> The page.
	 */
	private function page(string $id): array {
		foreach ($this->json(relative: 'src/manifest.json')['pages'] as $page) {
			if (($page['id'] ?? null) === $id) {
				return $page;
			}
		}

		$this->fail(message: 'The manifest has no page '.$id);
	}//end page()

	/**
	 * No route serves an Iv3 report or a taakveld list.
	 *
	 * @spec openspec/changes/case-costs-reported-per-iv3-taakveld/specs/iv3-taakveld-2023-refinement/spec.md#requirement-dossiq-exposes-classification-and-hours-never-a-sum-of-money-req-iv3-class-02
	 *
	 * @return void
	 */
	public function testNoRouteServesIv3OrATaakveldList(): void {
		$routes = require self::ROOT.'/appinfo/routes.php';
		$this->assertIsArray(actual: $routes);

		$offending = [];
		foreach ($routes as $group) {
			foreach ((array) $group as $route) {
				$text = (string) ($route['name'] ?? '').' '.(string) ($route['url'] ?? '');
				if (preg_match(pattern: '/iv3|taakveld|taskfield/i', subject: $text) === 1) {
					$offending[] = trim(string: $text);
				}
			}
		}

		$this->assertSame(expected: [], actual: $offending, message: 'Iv3 reporting is Shillinq\'s (ADR-107)');
	}//end testNoRouteServesIv3OrATaakveldList()

	/**
	 * No class or settings file under lib/ holds a taakveld list or an Iv3 report.
	 *
	 * @spec openspec/changes/case-costs-reported-per-iv3-taakveld/specs/iv3-taakveld-2023-refinement/spec.md#requirement-dossiq-exposes-classification-and-hours-never-a-sum-of-money-req-iv3-class-02
	 *
	 * @return void
	 */
	public function testNoTaakveldListOrIv3ClassUnderLib(): void {
		$offending = [];
		$files     = new \RecursiveIteratorIterator(
			iterator: new \RecursiveDirectoryIterator(directory: self::ROOT.'/lib', flags: \FilesystemIterator::SKIP_DOTS)
		);
		foreach ($files as $file) {
			if (preg_match(pattern: '/iv3|taakveld/i', subject: $file->getFilename()) === 1) {
				$offending[] = $file->getFilename();
			}
		}

		$this->assertSame(expected: [], actual: $offending, message: 'the taakveld catalogue is Shillinq\'s BbvTaakveld/Taakveld');
	}//end testNoTaakveldListOrIv3ClassUnderLib()

	/**
	 * The case and case type hold no cost amount; the classification is a plain code.
	 *
	 * @spec openspec/changes/case-costs-reported-per-iv3-taakveld/specs/iv3-taakveld-2023-refinement/spec.md#requirement-dossiq-exposes-classification-and-hours-never-a-sum-of-money-req-iv3-class-02
	 *
	 * @return void
	 */
	public function testTheRegisterHoldsTheCodeAndNoCost(): void {
		foreach (['lib/Settings/dossiq_register.json', 'lib/Settings/dossiq_mock_register.json'] as $register) {
			$schemas = $this->json(relative: $register)['components']['schemas'];

			foreach (['case', 'caseType'] as $schema) {
				$costLike = preg_grep(pattern: '/cost|amount/i', array: array_keys(array: $schemas[$schema]['properties']));
				$this->assertSame(expected: [], actual: array_values(array: $costLike), message: $register.' '.$schema.' carries no money');
			}

			$field = $schemas['caseType']['properties']['iv3TaskField'];
			$this->assertSame(expected: 'string', actual: $field['type'], message: $register.': the code is stored as given');
			$this->assertArrayNotHasKey(key: 'enum', array: $field, message: $register.': no taakveld list in dossiq');
		}
	}//end testTheRegisterHoldsTheCodeAndNoCost()

	/**
	 * The case type field picks from Shillinq's catalogue and falls back to a typed code.
	 *
	 * @spec openspec/changes/case-costs-reported-per-iv3-taakveld/specs/iv3-taakveld-2023-refinement/spec.md#requirement-a-case-type-carries-its-iv3-taakveld-as-a-code-from-shillinqs-catalogue-req-iv3-class-01
	 *
	 * @return void
	 */
	public function testTheCaseTypeFieldReadsShillinqsCatalogue(): void {
		$override = $this->page(id: 'CaseTypeDetail')['config']['fieldOverrides']['iv3TaskField'] ?? null;
		$this->assertIsArray(actual: $override, message: 'CaseTypeDetail overrides iv3TaskField');

		$from = $override['optionsFrom'];
		$this->assertSame(expected: 'shillinq', actual: $from['register']);
		$this->assertSame(expected: 'Taakveld', actual: $from['schema']);
		$this->assertSame(expected: 'code', actual: $from['valueField'], message: 'the code is what is stored');
		$this->assertSame(expected: 'shillinq', actual: $from['requiresApp']);
		$this->assertSame(expected: 'Install Shillinq to choose from the BBV list.', actual: $from['missingText']);

		$nl = $this->json(relative: 'l10n/nl.json')['translations'];
		$this->assertSame(expected: 'Installeer Shillinq om uit de BBV-lijst te kiezen.', actual: $nl[$from['missingText']] ?? null);
	}//end testTheCaseTypeFieldReadsShillinqsCatalogue()

	/**
	 * The Reports page links out to Shillinq for costs and serves no cost report itself.
	 *
	 * @spec openspec/changes/case-costs-reported-per-iv3-taakveld/specs/iv3-taakveld-2023-refinement/spec.md#requirement-the-reports-page-links-to-shillinq-for-costs-per-taakveld-req-iv3-class-03
	 *
	 * @return void
	 */
	public function testTheReportsPageLinksToShillinqForCosts(): void {
		$cards = $this->page(id: 'Reports')['config']['cards'];
		$costs = array_values(
			array: array_filter(array: $cards, callback: static fn (array $card): bool => ($card['category'] ?? null) === 'costs')
		);

		$this->assertCount(expectedCount: 1, haystack: $costs, message: 'one costs card: the link to Shillinq');
		$this->assertSame(expected: 'TaskFieldCosts', actual: $costs[0]['id']);
		$this->assertArrayNotHasKey(key: 'route', array: $costs[0], message: 'no dossiq cost report behind the card');
		$this->assertStringStartsWith(prefix: '/index.php/apps/shillinq/', string: $costs[0]['href']);
		$this->assertSame(expected: 'shillinq', actual: $costs[0]['requiresApp']);
		$this->assertSame(expected: 'This report is in Shillinq. Shillinq is not installed.', actual: $costs[0]['missingText']);
	}//end testTheReportsPageLinksToShillinqForCosts()
}//end class
