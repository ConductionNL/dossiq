<?php

/**
 * Conformance of the seeded demo dossier and the shipped document templates.
 *
 * The seed is what a fresh install and every clean-env run puts on screen, so
 * a Documents tab with nothing in it looks exactly like a Documents tab that
 * does not work. These assertions pin the rows the tab and the e2e both read:
 * two documents of different types on ONE case, one tagged, one incoming and
 * one outgoing, each reachable through its zaakinformatieobject.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Settings
 *
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/document-zaakdossier/spec.md
 * @spec openspec/specs/template-library/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The demo dossier rows and the document templates beside them.
 *
 * @covers \OCA\Dossiq\Service\TemplateLibraryService
 */
class DemoDossierSeedTest extends TestCase {

	/**
	 * The demo seed fragment.
	 */
	private const SEED = __DIR__ . '/../../../lib/Settings/register.d/46-demo-cases-english.json';

	/**
	 * The template library directory.
	 */
	private const TEMPLATES = __DIR__ . '/../../../lib/Settings/templates';

	/**
	 * The case the demo dossier hangs on.
	 */
	private const CASE_SLUG = 'case-bp-tree-dorpsstraat';

	/**
	 * The seeded objects.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $objects = [];

	/**
	 * Decode the seed.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$decoded = json_decode((string)file_get_contents(self::SEED), true);
		self::assertIsArray($decoded, 'The demo seed must be valid JSON');

		$this->objects = $decoded['components']['objects'];
	}//end setUp()

	/**
	 * The seeded objects of one schema, keyed by slug.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return array<string, array<string, mixed>> The objects.
	 */
	private function ofSchema(string $schema): array {
		$rows = [];
		foreach ($this->objects as $object) {
			if (($object['@self']['schema'] ?? '') === $schema) {
				$rows[$object['@self']['slug']] = $object;
			}
		}

		return $rows;
	}//end ofSchema()

	/**
	 * Two documents of different types sit on one demo case.
	 *
	 * @return void
	 */
	public function testTwoDocumentsOfDifferentTypesAreSeeded(): void {
		$documents = $this->ofSchema('informatieobject');

		self::assertCount(2, $documents);

		$types = array_column($documents, 'informatieobjecttype');
		self::assertCount(2, array_unique($types), 'The two documents must differ in type');

		$catalogue = $this->ofSchema('informatieobjecttype');
		foreach ($types as $type) {
			self::assertArrayHasKey(
				$type,
				$catalogue,
				'Every seeded document type must resolve to a seeded catalogue row'
			);
		}
	}//end testTwoDocumentsOfDifferentTypesAreSeeded()

	/**
	 * Both documents are linked to the same case through the join.
	 *
	 * @return void
	 */
	public function testBothDocumentsAreLinkedToTheDemoCase(): void {
		$joins = $this->ofSchema('zaakinformatieobject');
		$documents = $this->ofSchema('informatieobject');

		self::assertCount(2, $joins);

		foreach ($joins as $slug => $join) {
			self::assertSame(self::CASE_SLUG, $join['case'], $slug . ' must hang on the demo case');
			self::assertArrayHasKey(
				$join['informatieobject'],
				$documents,
				$slug . ' must reference a seeded informatieobject'
			);
			self::assertNotSame('', (string)($join['registrationDate'] ?? ''));
		}

		self::assertArrayHasKey(
			self::CASE_SLUG,
			$this->ofSchema('case'),
			'The case the dossier hangs on must itself be seeded'
		);
	}//end testBothDocumentsAreLinkedToTheDemoCase()

	/**
	 * One document carries the keyword the filter is demonstrated with.
	 *
	 * @return void
	 */
	public function testOneDocumentIsTaggedBezwaar(): void {
		$documents = $this->ofSchema('informatieobject');

		$tagged = array_filter(
			$documents,
			static fn (array $document): bool => in_array('bezwaar', ($document['keywords'] ?? []), true)
		);

		self::assertCount(1, $tagged, 'Exactly one seeded document carries the bezwaar keyword');
		self::assertCount(
			1,
			array_filter(
				$documents,
				static fn (array $document): bool => ($document['keywords'] ?? []) === []
					|| array_key_exists('keywords', $document) === false
			),
			'The other one carries none, so the filter has something to hide'
		);
	}//end testOneDocumentIsTaggedBezwaar()

	/**
	 * The Direction column has both directions to show.
	 *
	 * @return void
	 */
	public function testTheSeededDocumentsRunBothWays(): void {
		$directions = array_column($this->ofSchema('informatieobject'), 'direction');

		sort($directions);
		self::assertSame(['incoming', 'outgoing'], $directions);
	}//end testTheSeededDocumentsRunBothWays()

	/**
	 * The library ships the two document templates the picker offers.
	 *
	 * @return void
	 */
	public function testTheLibraryShipsTheTwoDocumentTemplates(): void {
		$templates = [];
		foreach ((array)glob(self::TEMPLATES . '/*.json') as $file) {
			$decoded = json_decode((string)file_get_contents((string)$file), true);
			self::assertIsArray($decoded, basename((string)$file) . ' must be valid JSON');
			// The same fallback TemplateLibraryService uses: a shipped bundle
			// may name itself only by its filename.
			$id = (string)($decoded['id'] ?? pathinfo((string)$file, PATHINFO_FILENAME));
			$templates[$id] = $decoded;
		}

		foreach (['ontvangstbevestiging', 'verdagingsbrief'] as $id) {
			self::assertArrayHasKey($id, $templates);
			self::assertNotSame('', trim((string)$templates[$id]['title']));
			self::assertNotSame(
				'',
				trim((string)($templates[$id]['body'] ?? '')),
				$id . ' must carry a body to render'
			);
			self::assertNotSame(
				'',
				trim((string)($templates[$id]['documentType'] ?? '')),
				$id . ' must name a document type, or the generated letter cannot be filed'
			);
		}
	}//end testTheLibraryShipsTheTwoDocumentTemplates()

	/**
	 * A document template's type resolves against the seeded catalogue.
	 *
	 * The generation service looks the type up by its `description`, so a
	 * template naming a type nothing answers to would file its letter against
	 * a dangling reference and the Type column would read the raw name.
	 *
	 * @return void
	 */
	public function testADocumentTemplatesTypeResolvesAgainstTheSeededCatalogue(): void {
		$decoded = json_decode(
			(string)file_get_contents(self::TEMPLATES . '/ontvangstbevestiging.json'),
			true
		);
		$descriptions = array_column($this->ofSchema('informatieobjecttype'), 'description');

		self::assertContains($decoded['documentType'], $descriptions);
	}//end testADocumentTemplatesTypeResolvesAgainstTheSeededCatalogue()
}//end class
