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
 * Twig cannot emit an array, so every list crosses the mapping as JSON text.
 * What differs is where it lands. `referenceProcess`, `relatedCaseTypes` and
 * `sourceDateArchiveProcedure` are declared `"type": "string"` and STORE that
 * text. `productsOrServices` is declared an array, so its text is a transport
 * step and a `reverseCast` of `jsonToArray` has to turn it back before the
 * write. That cast was missing, so `POST /api/zgw/catalogi/v1/zaaktypen`
 * answered 400 "should be type 'array or null' but is 'string'". The ZTC setUp
 * creates a zaaktype first, so that single 400 emptied the rest of both VNG
 * contract collections.
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
				if (isset($schemas[$slug]) === false) {
					$schemas[$slug] = $definition;
					continue;
				}

				// MERGE, do not replace. `loadConfiguration()` deep-merges the
				// fragments onto the base, and two fragments really do extend
				// the same schema (dso-omgevingsloket.json adds to `case`).
				// Replacing instead made every base property of `case` and
				// `customerContact` look undeclared.
				$schemas[$slug]['properties'] = array_merge(
					($schemas[$slug]['properties'] ?? []),
					($definition['properties'] ?? [])
				);
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
		$this->assertArrayHasKey('zgwCatalogus', $schemas, 'The ZGW catalogussen endpoint has no schema to write into.');
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
				if ($declared === 'string') {
					continue;
				}

				// An array property is allowed to be encoded for transport, as
				// long as something casts it back before the write.
				if ($declared === 'array'
					&& (($config['reverseCast'] ?? [])[$property] ?? '') === 'jsonToArray'
				) {
					continue;
				}

				$mismatched[] = sprintf(
					'%s mapping encodes %s.%s, which the register declares as %s, and no reverseCast turns it back',
					$mappingKey,
					$slug,
					$property,
					var_export($declared, true)
				);
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
	public function testProductsOrServicesIsCastBackToAnArrayOnBothSides(): void {
		$schemas = $this->registerSchemas();
		$property = $schemas['caseType']['properties']['productsOrServices'] ?? [];

		$this->assertSame('array', $property['type'] ?? null);
		$this->assertSame(
			['type' => 'string'],
			$property['items'] ?? null,
			"An entry is a Pipelinq product uuid or the product URL a ZGW client sent. "
			. "A 'format' here refuses one of the two."
		);

		$mappings = $this->mappingsKeyedBySettingsKey();

		$this->assertSame(
			'jsonToArray',
			($mappings['caseType']['reverseCast']['productsOrServices'] ?? null),
			'The inbound template encodes, so something has to decode before the write.'
		);
		$this->assertSame(
			'jsonToArray',
			($mappings['caseType']['cast']['productenOfDiensten'] ?? null),
			'The outbound template encodes, so ZGW would otherwise receive a string.'
		);
	}//end testProductsOrServicesIsCastBackToAnArrayOnBothSides()
	/**
	 * Every inbound mapping writes into a property the schema declares.
	 *
	 * `reverseMapping` runs ZGW to OpenRegister, so its KEYS are register
	 * property names. A key the schema does not declare is a value that reaches
	 * OpenRegister under a name nothing reads, silently: no error, no stored
	 * value, and a GET that returns the field empty forever.
	 *
	 * @return void
	 */
	public function testEveryInboundMappingWritesAPropertyTheSchemaDeclares(): void {
		$slugForKey = array_flip(SchemaSlugMap::SLUG_TO_CONFIG_KEY);
		$schemas = $this->registerSchemas();
		$unknown = [];
		$checked = 0;

		foreach ($this->mappingsKeyedBySettingsKey() as $mappingKey => $config) {
			$slug = ($slugForKey[(string)($config['sourceSchema'] ?? '')] ?? null);
			if ($slug === null || isset($schemas[$slug]) === false) {
				continue;
			}

			$properties = ($schemas[$slug]['properties'] ?? []);
			foreach (array_keys(($config['reverseMapping'] ?? [])) as $property) {
				$checked++;
				if (isset($properties[$property]) === false) {
					$unknown[] = sprintf('%s writes %s.%s, which the schema does not declare', $mappingKey, $slug, $property);
				}
			}
		}

		$this->assertGreaterThan(0, $checked, 'No inbound mapping keys were examined at all.');
		$this->assertSame(
			[],
			$unknown,
			"These ZGW inbound mappings write a property no schema declares, so the value is dropped\n"
			. "without an error:\n  " . implode("\n  ", $unknown)
		);
	}//end testEveryInboundMappingWritesAPropertyTheSchemaDeclares()
	/**
	 * Every outbound template reads a property the schema declares.
	 *
	 * `propertyMapping` runs OpenRegister to ZGW, so its KEYS are ZGW field
	 * names and its TEMPLATES read register properties. Both halves were
	 * inverted on `zaaktype-informatieobjecttypen`: the keys said
	 * `sequenceNumber` and `direction`, which ZGW does not define, and the
	 * templates read `{{ volgnummer }}` and `{{ richting }}`, which the
	 * register does not store. The response carried two fields nobody asked
	 * for, both empty, and nothing said so.
	 *
	 * Variables beginning with an underscore are the mapping's own context
	 * (`_baseUrl`, `_uuid`, `_valueMappings`) and are not schema properties.
	 *
	 * @return void
	 */
	public function testEveryOutboundTemplateReadsAPropertyTheSchemaDeclares(): void {
		$slugForKey = array_flip(SchemaSlugMap::SLUG_TO_CONFIG_KEY);
		$schemas = $this->registerSchemas();
		$unknown = [];
		$checked = 0;

		foreach ($this->mappingsKeyedBySettingsKey() as $mappingKey => $config) {
			$slug = ($slugForKey[(string)($config['sourceSchema'] ?? '')] ?? null);
			if ($slug === null || isset($schemas[$slug]) === false) {
				continue;
			}

			$properties = ($schemas[$slug]['properties'] ?? []);
			foreach (($config['propertyMapping'] ?? []) as $zgwField => $template) {
				if (is_string($template) === false) {
					continue;
				}

				preg_match_all('/\{\{\s*([A-Za-z_][A-Za-z0-9_]*)/', $template, $matches);
				foreach ($matches[1] as $variable) {
					if (str_starts_with($variable, '_') === true) {
						continue;
					}

					$checked++;
					if (isset($properties[$variable]) === false) {
						$unknown[] = sprintf(
							'%s reads {{ %s }} for the ZGW field %s, and %s does not declare it',
							$mappingKey,
							$variable,
							$zgwField,
							$slug
						);
					}
				}
			}
		}

		$this->assertGreaterThan(0, $checked, 'No outbound templates were examined at all.');
		$this->assertSame(
			[],
			$unknown,
			"These ZGW outbound templates read a property no schema declares, so the field is\n"
			. "returned empty:\n  " . implode("\n  ", $unknown)
		);
	}//end testEveryOutboundTemplateReadsAPropertyTheSchemaDeclares()
	/**
	 * Enum-constrained properties whose vocabulary is identical on both sides.
	 *
	 * ZGW's `vertrouwelijkheidaanduiding`, `archiefnominatie`,
	 * `indicatieInternOfExtern` and `afleidingswijze` are stored under the
	 * same words the standard uses, so there is nothing to translate. Every
	 * other enum needs a `valueMapping` and a `zgw_enum_reverse` call.
	 *
	 * @var string[]
	 */
	private const SAME_VOCABULARY_ON_BOTH_SIDES = [
		'zaak.confidentiality',
		'zaak.archiveNomination',
		'caseType.confidentiality',
		'caseType.internalOrExternal',
		'resultaattype.archivalAction',
		'informatieobjecttype.confidentiality',
		'enkelvoudiginformatieobject.confidentiality',
	];

	/**
	 * Every enum a ZGW client writes is either translated or the same word.
	 *
	 * A HALF-TRANSLATED enum is the worst of the three states, and four of
	 * these were in it. `document.status` declared
	 * `in_bewerking, for_determination, final, archived`, so exactly one of
	 * ZGW's four statuses could be stored and the other three answered 400
	 * "should be one of". `zaak.archiveStatus`, `zaak.paymentIndication` and
	 * `objectinformatieobject.objectType` were the same shape.
	 *
	 * Nothing reported it, because a rejected value is a 400 the caller reads
	 * as their own mistake.
	 *
	 * @return void
	 */
	public function testEveryWrittenEnumIsTranslatedOrDeliberatelyIdentical(): void {
		$slugForKey = array_flip(SchemaSlugMap::SLUG_TO_CONFIG_KEY);
		$schemas = $this->registerSchemas();
		$untranslated = [];
		$checked = 0;

		foreach ($this->mappingsKeyedBySettingsKey() as $mappingKey => $config) {
			$slug = ($slugForKey[(string)($config['sourceSchema'] ?? '')] ?? null);
			if ($slug === null || isset($schemas[$slug]) === false) {
				continue;
			}

			$properties = ($schemas[$slug]['properties'] ?? []);
			foreach (($config['reverseMapping'] ?? []) as $property => $template) {
				if (isset($properties[$property]['enum']) === false) {
					continue;
				}

				$checked++;
				$name = $mappingKey . '.' . $property;
				if (in_array($name, self::SAME_VOCABULARY_ON_BOTH_SIDES, true) === true) {
					continue;
				}

				$translated = (str_contains((string)$template, 'zgw_enum_reverse') === true
					&& isset($config['valueMapping'][$property]) === true);
				if ($translated === false) {
					$untranslated[] = sprintf(
						'%s enum [%s] passes through untranslated',
						$name,
						implode(', ', $properties[$property]['enum'])
					);
				}
			}
		}

		$this->assertGreaterThan(0, $checked, 'No enum-constrained properties were examined at all.');
		$this->assertSame(
			[],
			$untranslated,
			"These ZGW inbound mappings write an enum without translating it, so a value the\n"
			. "standard defines answers 400 unless the register happens to spell it the same way:\n  "
			. implode("\n  ", $untranslated)
		);
	}//end testEveryWrittenEnumIsTranslatedOrDeliberatelyIdentical()

	/**
	 * Each translation table maps register values to ZGW values, in that order.
	 *
	 * The `objectinformatieobject` table mapped `zaak` to `zaak` and `decision`
	 * to `decision`, while the register stores `case` and `decision`. Keys that
	 * are not register values make the table a no-op that looks populated.
	 *
	 * @return void
	 */
	public function testEveryTranslationTableIsKeyedByRegisterValues(): void {
		$slugForKey = array_flip(SchemaSlugMap::SLUG_TO_CONFIG_KEY);
		$schemas = $this->registerSchemas();
		$wrong = [];

		foreach ($this->mappingsKeyedBySettingsKey() as $mappingKey => $config) {
			$slug = ($slugForKey[(string)($config['sourceSchema'] ?? '')] ?? null);
			if ($slug === null || isset($schemas[$slug]) === false) {
				continue;
			}

			$properties = ($schemas[$slug]['properties'] ?? []);
			foreach (($config['valueMapping'] ?? []) as $property => $table) {
				$enum = ($properties[$property]['enum'] ?? null);
				if ($enum === null) {
					continue;
				}

				foreach (array_keys($table) as $registerValue) {
					if (in_array($registerValue, $enum, true) === false) {
						$wrong[] = sprintf(
							'%s.%s translates %s, which %s does not allow',
							$mappingKey,
							$property,
							var_export($registerValue, true),
							$slug
						);
					}
				}
			}
		}

		$this->assertSame(
			[],
			$wrong,
			"These translation tables are keyed by something the register cannot store, so they\n"
			. "never match:\n  " . implode("\n  ", $wrong)
		);
	}//end testEveryTranslationTableIsKeyedByRegisterValues()
	/**
	 * A translated enum is translated in BOTH directions.
	 *
	 * `valueMapping` is consulted by `zgw_enum` on the way out and
	 * `zgw_enum_reverse` on the way in. A table wired into only one direction
	 * accepts ZGW's word and then hands it back raw, or the reverse, and the
	 * round trip stops being a round trip.
	 *
	 * THIS ONE IS A FORWARD GUARD, not a regression proof: it passes against
	 * the tree before these fixes, because three of the four tables there were
	 * wired into NEITHER direction, which is symmetric and so invisible to it.
	 * testEveryWrittenEnumIsTranslatedOrDeliberatelyIdentical is what catches
	 * that state. This catches the half-done version of the fix.
	 *
	 * @return void
	 */
	public function testATranslatedEnumIsTranslatedInBothDirections(): void {
		$oneSided = [];

		foreach ($this->mappingsKeyedBySettingsKey() as $mappingKey => $config) {
			foreach (array_keys(($config['valueMapping'] ?? [])) as $property) {
				$inbound = (string)(($config['reverseMapping'] ?? [])[$property] ?? '');
				$outboundCalls = false;
				foreach (($config['propertyMapping'] ?? []) as $template) {
					if (is_string($template) === true
						&& str_contains($template, 'zgw_enum("' . $property . '"') === true
					) {
						$outboundCalls = true;
						break;
					}
				}

				$inboundCalls = str_contains($inbound, 'zgw_enum_reverse("' . $property . '"');
				if ($inboundCalls === $outboundCalls) {
					continue;
				}

				$oneSided[] = sprintf(
					'%s.%s has a translation table used on the %s side only',
					$mappingKey,
					$property,
					($inboundCalls === true ? 'inbound' : 'outbound')
				);
			}
		}

		$this->assertSame(
			[],
			$oneSided,
			"These translation tables are wired into one direction only, so the round trip is\n"
			. "lossy:\n  " . implode("\n  ", $oneSided)
		);
	}//end testATranslatedEnumIsTranslatedInBothDirections()
	/**
	 * Every enum template survives an absent value.
	 *
	 * `zgwEnum()` and `zgwEnumReverse()` declare `string $value`, so Twig
	 * handing them an undefined variable is a TypeError, and OpenRegister
	 * reports it as "an exception has been thrown during the rendering of a
	 * template" against the whole mapping. Measured: adding the filters without
	 * a guard made every EnkelvoudigInformatieObject create 400, because
	 * `status` is optional and most bodies omit it.
	 *
	 * `| default("")` reproduces exactly what a bare `{{ status }}` used to
	 * render for an absent value, so nothing else changes.
	 *
	 * @return void
	 */
	public function testEveryEnumTemplateGuardsAnAbsentValue(): void {
		$unguarded = [];
		$checked = 0;

		foreach ($this->mappingsKeyedBySettingsKey() as $mappingKey => $config) {
			foreach (['propertyMapping', 'reverseMapping'] as $side) {
				foreach (($config[$side] ?? []) as $field => $template) {
					if (is_string($template) === false || str_contains($template, 'zgw_enum') === false) {
						continue;
					}

					$checked++;
					if (str_contains($template, 'default("")') === false) {
						$unguarded[] = sprintf('%s %s.%s: %s', $mappingKey, $side, $field, $template);
					}
				}
			}
		}

		$this->assertGreaterThan(0, $checked, 'No enum templates were examined at all.');
		$this->assertSame(
			[],
			$unguarded,
			"These enum templates hand an undefined value to a string parameter, which throws and\n"
			. "fails the whole mapping:\n  " . implode("\n  ", $unguarded)
		);
	}//end testEveryEnumTemplateGuardsAnAbsentValue()
}//end class
