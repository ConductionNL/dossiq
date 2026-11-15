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
 * @spec openspec/specs/admin-settings/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use OCA\Dossiq\Service\Settings\RegisterFragmentMerger;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\SettingsService
 * @uses \OCA\Dossiq\Service\Settings\RegisterFragmentMerger
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
	 * Every property key is one OpenRegister's import accepts.
	 *
	 * OpenRegister refuses a property key outside its vocabulary, and a
	 * refusal rejects the WHOLE schema: the import logs `PARTIAL IMPORT`, the
	 * register keeps the schema it already had, and the app's own import
	 * still reads as done. Measured 2026-10-11 on a live upgrade, where two
	 * such values cost the case schema this round's changes. The accepted
	 * vocabulary is the snapshot in `tests/schemas/openregister-accepted-vocabulary.json`,
	 * read from OpenRegister's own published endpoints.
	 *
	 * @return void
	 */
	public function testEveryPropertyKeyIsOneOpenRegisterAccepts(): void {
		$vocabulary = $this->acceptedVocabulary();
		$offenders = [];
		foreach ($this->walkedProperties() as $path => $property) {
			foreach (array_keys($property) as $key) {
				if ($this->isAcceptedKey(key: (string)$key, vocabulary: $vocabulary) === false) {
					$offenders[] = $path . ' ' . $key;
				}
			}
		}

		$this->assertSame(
			[],
			$offenders,
			'OpenRegister refuses these property keys, and one refusal rejects the whole schema at import: '
			. implode(', ', $offenders)
		);
	}//end testEveryPropertyKeyIsOneOpenRegisterAccepts()

	/**
	 * Every closed-list modifier carries a value OpenRegister accepts.
	 *
	 * `inputControl` is the control a LIST surface renders to filter on a
	 * field, not a form widget. `textarea` shipped on
	 * `aanvullingsverzoek.applicantAnswer` and OpenRegister refused the whole
	 * schema. A long text gets its textarea on a form from `maxLength` alone.
	 *
	 * @return void
	 */
	public function testEveryClosedListModifierCarriesAValueOpenRegisterAccepts(): void {
		$allowed = $this->acceptedVocabulary()['allowedValues'];
		$this->assertArrayHasKey('inputControl', $allowed);
		$this->assertArrayHasKey('matchType', $allowed);

		$offenders = [];
		$seen = 0;
		foreach ($this->walkedProperties() as $path => $property) {
			foreach ($allowed as $key => $values) {
				if (array_key_exists($key, $property) === false) {
					continue;
				}

				$seen++;
				$value = $property[$key];
				if (is_string($value) === false || in_array(strtolower(trim($value)), $values, true) === false) {
					$offenders[] = $path . ' ' . $key . '=' . json_encode($value);
				}
			}
		}

		// A walk that reached nothing would pass every time.
		$this->assertGreaterThan(100, $seen, 'The walk reached almost no inputControl or matchType declarations');
		$this->assertSame(
			[],
			$offenders,
			'OpenRegister refuses these values, and one refusal rejects the whole schema at import: '
			. implode(', ', $offenders)
		);
	}//end testEveryClosedListModifierCarriesAValueOpenRegisterAccepts()

	/**
	 * Every role an extending-form map names is one OpenRegister accepts.
	 *
	 * `hideOnCreate` is a nextcloud-vue 2.79.0 form role. OpenRegister refused
	 * it as an unknown key until it learned form roles, and the refusal
	 * rejected the case schema. The pin below holds both halves: the case
	 * declaration maps it, and OpenRegister accepts it.
	 *
	 * @return void
	 */
	public function testEveryExtendsFormRoleIsOneOpenRegisterAccepts(): void {
		$vocabulary = $this->acceptedVocabulary();
		$annotation = $vocabulary['extendsForm']['annotation'];
		$accepted = array_merge(
			array_keys($vocabulary['extendsForm']['sourceAliases']),
			$vocabulary['extendsForm']['formRoles']
		);

		$declarations = [];
		foreach ($this->schemas() as $name => $schema) {
			$this->collectDeclarations(node: $schema, annotation: $annotation, path: $name, found: $declarations);
		}

		$this->assertArrayHasKey('case/properties/caseType', $declarations, 'The case form declaration was not found');

		$offenders = [];
		foreach ($declarations as $path => $declaration) {
			foreach (array_keys(($declaration['map'] ?? [])) as $role) {
				$role = (string)$role;
				if ($this->isAcceptedKey(key: $role, vocabulary: $vocabulary) === false
					&& in_array($role, $accepted, true) === false
				) {
					$offenders[] = $path . ' ' . $role;
				}
			}
		}

		$this->assertArrayHasKey('hideOnCreate', $declarations['case/properties/caseType']['map']);
		$this->assertContains('hideOnCreate', $vocabulary['extendsForm']['formRoles']);
		$this->assertSame(
			[],
			$offenders,
			'OpenRegister refuses these extending-form roles, which rejects the whole schema at import: '
			. implode(', ', $offenders)
		);
	}//end testEveryExtendsFormRoleIsOneOpenRegisterAccepts()

	/**
	 * Whether OpenRegister accepts a property key.
	 *
	 * @param string               $key        The key.
	 * @param array<string, mixed> $vocabulary The accepted vocabulary.
	 *
	 * @return bool
	 */
	private function isAcceptedKey(string $key, array $vocabulary): bool {
		if (in_array($key, $vocabulary['keys'], true) === true) {
			return true;
		}

		if (str_starts_with($key, $vocabulary['vendorExtensionPrefix']) === true) {
			return true;
		}

		$localisation = $vocabulary['localisation'];
		$separator = $localisation['separator'];
		$parts = explode($separator, $key, 2);

		return count($parts) === 2
			&& in_array($parts[0], $localisation['keys'], true) === true
			&& preg_match($localisation['languageTagPattern'], $parts[1]) === 1;
	}//end isAcceptedKey()

	/**
	 * Every property of every merged schema, nested ones included.
	 *
	 * @return array<string, array<string, mixed>> Path to property.
	 */
	private function walkedProperties(): array {
		$found = [];
		foreach ($this->schemas() as $name => $schema) {
			$this->walkProperties(properties: ($schema['properties'] ?? []), path: $name, found: $found);
		}

		$this->assertNotEmpty($found, 'The walk found no properties at all');

		return $found;
	}//end walkedProperties()

	/**
	 * Walk a properties map, descending into object and array-item properties.
	 *
	 * @param mixed                               $properties The properties map.
	 * @param string                              $path       Where it sits.
	 * @param array<string, array<string, mixed>> $found      Collected properties.
	 *
	 * @return void
	 */
	private function walkProperties(mixed $properties, string $path, array &$found): void {
		if (is_array($properties) === false) {
			return;
		}

		foreach ($properties as $name => $property) {
			if (is_array($property) === false) {
				continue;
			}

			$here = $path . '/' . (string)$name;
			$found[$here] = $property;
			$this->walkProperties(properties: ($property['properties'] ?? []), path: $here, found: $found);
			$items = ($property['items'] ?? null);
			if (is_array($items) === true) {
				$this->walkProperties(properties: ($items['properties'] ?? []), path: $here . '[]', found: $found);
			}
		}
	}//end walkProperties()

	/**
	 * Collect every extending-form declaration under a node.
	 *
	 * @param mixed                               $node       The node.
	 * @param string                              $annotation The annotation key.
	 * @param string                              $path       Where the node sits.
	 * @param array<string, array<string, mixed>> $found      Collected declarations.
	 *
	 * @return void
	 */
	private function collectDeclarations(mixed $node, string $annotation, string $path, array &$found): void {
		if (is_array($node) === false) {
			return;
		}

		foreach ($node as $key => $child) {
			if ((string)$key === $annotation && is_array($child) === true) {
				$found[$path] = $child;
				continue;
			}

			$this->collectDeclarations(node: $child, annotation: $annotation, path: $path . '/' . (string)$key, found: $found);
		}
	}//end collectDeclarations()

	/**
	 * The snapshot of what OpenRegister's import accepts.
	 *
	 * @return array<string, mixed>
	 */
	private function acceptedVocabulary(): array {
		$path = __DIR__ . '/../../schemas/openregister-accepted-vocabulary.json';
		$decoded = json_decode((string)file_get_contents($path), true);
		$this->assertIsArray($decoded, 'tests/schemas/openregister-accepted-vocabulary.json must be readable JSON');
		$this->assertNotEmpty($decoded['keys'] ?? [], 'The vocabulary snapshot lists no keys');

		return $decoded;
	}//end acceptedVocabulary()

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
