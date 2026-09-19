<?php

/**
 * Shipped declarations OpenRegister accepts.
 *
 * 🔴 THIS TEST EXISTS BECAUSE A DECLARATION FAULT ARRIVED AS AN HTTP 500 AND
 * TOOK THE WHOLE APP'S PROVISIONING WITH IT.
 *
 * Measured 2026-09-19 on a live instance carrying dossiq 0.4.42. One fragment
 * declared the schema-level `searchable` as an array of property names.
 * OpenRegister's `Schema::setSearchable()` takes a `bool`, so the import raised
 * a `TypeError`, `POST /apps/dossiq/api/settings/load` answered HTTP 500, and
 * the e2e seed fell back to the importer that CANNOT merge `register.d`. Every
 * schema this app declares in a fragment was then absent from the instance:
 * `brpPerson`, `informatieobject` and 80 more answered 404, and none of the
 * `*_schema` app-config keys was ever written, so minting a case share
 * answered 502.
 *
 * One mistyped value in one fragment, and the app had no register. The assert
 * below is on the merged configuration, which is exactly the array
 * `SettingsService::loadConfiguration()` hands to OpenRegister.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/specs/admin-settings/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use OCA\Dossiq\Service\Settings\RegisterFragmentMerger;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\SettingsService
 */
class ShippedDeclarationsOpenRegisterAcceptsTest extends TestCase {

	/**
	 * The effective configuration: the monolith with every ADR-037 fragment
	 * merged on top, which is what the import actually receives.
	 *
	 * @var array<string, mixed>
	 */
	private array $effective = [];

	/**
	 * Build the effective configuration the way SettingsService does.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$settingsDir = __DIR__ . '/../../../lib/Settings';
		$base = json_decode((string)file_get_contents($settingsDir . '/dossiq_register.json'), true);
		$this->assertIsArray($base, 'dossiq_register.json must be readable JSON');

		[$merged] = (new RegisterFragmentMerger())->merge(
			base: $base,
			fragmentDir: $settingsDir . '/register.d'
		);
		$this->effective = $merged;
	}//end setUp()

	/**
	 * Every schema-level `searchable` is a boolean.
	 *
	 * `Schema::setSearchable(bool $searchable)` is the SOLR indexing flag. A
	 * list of property names is not a narrower version of it: it is a
	 * `TypeError`, and a `TypeError` out of an import is an HTTP 500 rather
	 * than a reported failure. Per-property searchability is declared with
	 * `matchType` and `inputControl` on the property, which is what
	 * `lib/Settings/register.d/39-search-declarations.json` does.
	 *
	 * @return void
	 */
	public function testEverySchemaLevelSearchableIsABoolean(): void {
		$offenders = [];
		foreach ($this->schemas() as $name => $schema) {
			if (array_key_exists('searchable', $schema) === false) {
				continue;
			}

			if (is_bool($schema['searchable']) === false) {
				$offenders[$name] = gettype($schema['searchable']);
			}
		}

		$this->assertSame(
			[],
			$offenders,
			'A schema-level `searchable` that is not a boolean makes '
			. 'Schema::setSearchable() raise a TypeError, which answers the '
			. 'whole configuration load with HTTP 500. Declare per-property '
			. 'searchability with matchType/inputControl instead. Offenders: '
			. json_encode($offenders)
		);
	}//end testEverySchemaLevelSearchableIsABoolean()

	/**
	 * Every schema the merged configuration declares carries a slug.
	 *
	 * OpenRegister's import identifies a schema by its slug and SKIPS a
	 * fragment without one, with a warning nobody reads and a 200 on the
	 * import. A fragment that only ADDS properties to a schema the monolith
	 * already declares inherits that slug through the merge, so this assert
	 * runs on the merged set rather than on each file.
	 *
	 * @return void
	 */
	public function testEveryMergedSchemaCarriesASlug(): void {
		$slugless = [];
		foreach ($this->schemas() as $name => $schema) {
			$slug = ($schema['slug'] ?? '');
			if (is_string($slug) === false || trim($slug) === '') {
				$slugless[] = $name;
			}
		}

		$this->assertSame(
			[],
			$slugless,
			'A schema with no slug is skipped by OpenRegister\'s import while '
			. 'the import still answers "Import successful", so the schema is '
			. 'simply never created. Slugless: ' . implode(', ', $slugless)
		);
	}//end testEveryMergedSchemaCarriesASlug()

	/**
	 * The merged schema map.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function schemas(): array {
		$schemas = ($this->effective['components']['schemas'] ?? []);
		$this->assertIsArray($schemas);
		$this->assertNotEmpty($schemas, 'The merged configuration declares no schemas at all');

		$typed = [];
		foreach ($schemas as $name => $schema) {
			if (is_array($schema) === true) {
				$typed[(string)$name] = $schema;
			}
		}

		return $typed;
	}//end schemas()
}//end class
