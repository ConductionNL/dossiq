<?php

/**
 * ZGW Mapping Schema Type Tests
 *
 * A ZGW mapping that JSON-encodes a value writes a string. The property it
 * writes into has to be declared a string, or OpenRegister refuses the write.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/zgw-api-mapping/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\LoadDefaultZgwMappings;
use OCA\Dossiq\Service\Settings\SchemaSlugMap;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\ZgwMappingService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The ZGW mappings and the register schemas agree on which properties hold
 * JSON text.
 *
 * `referenceProcess`, `relatedCaseTypes` and `sourceDateArchiveProcedure` are
 * declared `"type": "string"` and hold JSON text, so their mappings encode.
 * `productsOrServices` is declared an array and its mapping encoded anyway,
 * which made `POST /api/zgw/catalogi/v1/zaaktypen` answer 400 "should be type
 * 'array or null' but is 'string'". The ZTC setUp creates a zaaktype first, so
 * that single 400 emptied the rest of both VNG contract collections.
 *
 * @covers \OCA\Dossiq\Repair\LoadDefaultZgwMappings
 */
class ZgwMappingSchemaTypeTest extends TestCase {

	/**
	 * Every default mapping, with `sourceSchema` holding the settings key it
	 * reads rather than a live schema id.
	 *
	 * Feeding the settings array a value equal to its own key is what lets this
	 * test name the schema a mapping targets without a database. The key maps
	 * back to a schema slug through SchemaSlugMap.
	 *
	 * @return array<string, array> Mapping key to its configuration
	 */
	private function mappingsKeyedBySettingsKey(): array {
		$keys = array_values(SchemaSlugMap::SLUG_TO_CONFIG_KEY);
		$settings = array_combine($keys, $keys);

		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getSettings')->willReturn($settings);

		$repair = new LoadDefaultZgwMappings(
			$this->createMock(ZgwMappingService::class),
			$settingsService,
			$this->createMock(LoggerInterface::class),
		);

		return $repair->getDefaultMappings('1');
	}//end mappingsKeyedBySettingsKey()

	/**
	 * Every schema the register declares, by slug.
	 *
	 * Reads the base register plus every `register.d/*.json` fragment, the same
	 * two sources SettingsService::loadConfiguration() merges.
	 *
	 * @return array<string, array> Schema slug to its JSON Schema definition
	 */
	private function registerSchemas(): array {
		$root = dirname(__DIR__, 3) . '/lib/Settings';
		$files = array_merge(
			[$root . '/dossiq_register.json'],
			glob($root . '/register.d/*.json') ?: []
		);

		$schemas = [];
		foreach ($files as $file) {
			$decoded = json_decode((string)file_get_contents($file), true);
			foreach ((($decoded['components'] ?? [])['schemas'] ?? []) as $slug => $definition) {
				$schemas[$slug] = $definition;
			}
		}

		return $schemas;
	}//end registerSchemas()

	/**
	 * The register and the fragments parse and carry schemas at all.
	 *
	 * Without this, a typo in the glob would make the real test below pass by
	 * having nothing to check.
	 *
	 * @return void
	 */
	public function testTheRegisterCarriesSchemas(): void {
		$schemas = $this->registerSchemas();

		$this->assertGreaterThan(100, count($schemas), 'The register decoded far fewer schemas than it ships.');
		$this->assertArrayHasKey('caseType', $schemas);
		$this->assertArrayHasKey('catalog', $schemas, 'The ZGW catalogussen endpoint has no schema to write into.');
	}//end testTheRegisterCarriesSchemas()

	/**
	 * A property a mapping JSON-encodes is declared a string.
	 *
	 * @return void
	 */
	public function testJsonEncodedPropertiesAreDeclaredStrings(): void {
		$slugForKey = array_flip(SchemaSlugMap::SLUG_TO_CONFIG_KEY);
		$schemas = $this->registerSchemas();
		$mismatched = [];
		$checked = 0;

		foreach ($this->mappingsKeyedBySettingsKey() as $mappingKey => $config) {
			$slug = $slugForKey[(string)($config['sourceSchema'] ?? '')] ?? null;
			if ($slug === null || isset($schemas[$slug]) === false) {
				continue;
			}

			$properties = $schemas[$slug]['properties'] ?? [];

			// reverseMapping is ZGW to OpenRegister, so its KEY is the property
			// the encoded string lands in.
			foreach (($config['reverseMapping'] ?? []) as $property => $template) {
				if (is_string($template) === false || str_contains($template, 'json_encode') === false) {
					continue;
				}

				$checked++;
				$declared = ($properties[$property]['type'] ?? null);
				if ($declared !== 'string') {
					$mismatched[] = sprintf(
						'%s mapping encodes %s.%s, which the register declares as %s',
						$mappingKey,
						$slug,
						$property,
						var_export($declared, true)
					);
				}
			}
		}

		$this->assertGreaterThan(
			0,
			$checked,
			'No json_encode templates were examined at all, so this test proved nothing.'
		);

		$this->assertSame(
			[],
			$mismatched,
			"These ZGW mappings write JSON text into a property that is not a string,\n"
			. "so OpenRegister refuses the write with a type error:\n  "
			. implode("\n  ", $mismatched)
		);
	}//end testJsonEncodedPropertiesAreDeclaredStrings()

	/**
	 * The property that was wrong, named explicitly.
	 *
	 * The sweep above would also pass if somebody deleted the mapping.
	 *
	 * @return void
	 */
	public function testProductsOrServicesStaysAnArrayOnBothSides(): void {
		$schemas = $this->registerSchemas();
		$property = $schemas['caseType']['properties']['productsOrServices'] ?? [];

		$this->assertSame('array', $property['type'] ?? null);

		$mappings = $this->mappingsKeyedBySettingsKey();
		$this->assertStringNotContainsString(
			'json_encode',
			(string)($mappings['caseType']['reverseMapping']['productsOrServices'] ?? ''),
			'productsOrServices is an array property and must not be encoded on the way in.'
		);
		$this->assertStringNotContainsString(
			'json_encode',
			(string)($mappings['caseType']['propertyMapping']['productenOfDiensten'] ?? ''),
			'productsOrServices is an array property and must not be encoded on the way out.'
		);
		$this->assertArrayNotHasKey(
			'productenOfDiensten',
			$mappings['caseType']['cast'] ?? [],
			'Nothing encodes productenOfDiensten any more, so there is nothing to decode.'
		);
	}//end testProductsOrServicesStaysAnArrayOnBothSides()
}//end class
