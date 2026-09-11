<?php

/**
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The demo data set must not carry objects for a schema the app already seeds
 * as a catalogue.
 *
 * `lib/Settings/register.d/96-integrations.json` seeds the twelve
 * `dossiqIntegration` rows, each with a stable slug (`integration-brp`). The
 * mock descriptor is generated from the app's own schemas by
 * `hydra-gates/scripts/lib/generate_mock_register.py`, which has no notion of
 * "this schema is a catalogue, do not invent rows for it", so it produced three
 * more with machine slugs (`dossiqintegration-dossiqintegration-3-3`).
 *
 * `ImportHandler` upserts a seed object on (register, schema, slug), so the two
 * spellings never collapse: an instance with demo data installed carried TWO BRP
 * rows and TWO ZGW rows. That broke `integrations-page.spec.ts` twice over, and
 * both failures read as product defects rather than as duplicate data:
 *
 *   - `offers no settings link where there is no section to open` died on a
 *     Playwright strict-mode violation, two rows matching /BRP/i.
 *   - `claims nothing it has not checked` read `status: error` for StUF, from a
 *     demo row asserting "Circuit open on Gemeente Zuid" — a connection failure
 *     nobody had measured, which is the exact claim that test exists to forbid.
 *
 * This guards the removal. It fails loudly if the generator is re-run and puts
 * them back, which a comment in the JSON could not do.
 *
 * @spec exclude No canonical spec covers the demo data set's contents. This
 *  guards a defect in what the generator emits, not a stated requirement.
 */

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The mock descriptor ships no rows for a schema the app seeds itself.
 */
class MockRegisterHasNoSeededCatalogueTest extends TestCase {
	/**
	 * Schemas whose rows come from `register.d`, so the demo set must not
	 * invent its own.
	 *
	 * @var array<int, string>
	 */
	private const CATALOGUE_SCHEMAS = ['dossiqIntegration'];

	/**
	 * Read the shipped mock descriptor's seed objects.
	 *
	 * @return array<int, array<string, mixed>> The objects.
	 */
	private function mockObjects(): array {
		$path = __DIR__ . '/../../../lib/Settings/dossiq_mock_register.json';
		$this->assertFileExists($path, 'the mock descriptor should ship');

		$decoded = json_decode((string)file_get_contents($path), true);
		$this->assertIsArray($decoded, 'the mock descriptor should be valid JSON');

		$objects = ($decoded['components']['objects'] ?? []);
		$this->assertIsArray($objects);

		// A descriptor that shipped no objects at all would pass the assertion
		// below by having nothing to assert on.
		$this->assertNotEmpty($objects, 'the mock descriptor should ship seed objects');

		return $objects;
	}

	/**
	 * The demo set invents no row for a schema the app already seeds.
	 *
	 * @return void
	 */
	public function testTheDemoSetShipsNoRowForASeededCatalogue(): void {
		$offenders = [];
		foreach ($this->mockObjects() as $object) {
			$schema = (string)($object['@self']['schema'] ?? '');
			if (in_array($schema, self::CATALOGUE_SCHEMAS, true) === true) {
				$offenders[] = $schema . '/' . (string)($object['@self']['slug'] ?? '?');
			}
		}

		$this->assertSame(
			[],
			$offenders,
			'the demo set carries rows for a schema register.d already seeds, so an '
			. 'instance with demo data installed shows each of them twice: '
			. implode(', ', $offenders)
		);
	}
}
