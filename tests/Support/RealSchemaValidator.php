<?php

/**
 * Validates a payload against the schema dossiq really ships, as OpenRegister does.
 *
 * 🔑 WHY IT EXISTS. The Woo journey shipped writes that passed every unit test
 * and failed on the first real save: a decision type written as a name where
 * the schema declares a uuid, seed rows whose references were slugs where the
 * schema declares uuids. The tests checked that a key was DECLARED, never that
 * its VALUE fits the declaration. This takes the merged register (base plus
 * every fragment, the importer's view) and validates with opis/json-schema,
 * the library OpenRegister's own validator is built on, including formats.
 *
 * What it drops before validating, and why: `$ref` on a property names another
 * OpenRegister schema by slug (a relation), not a JSON-Schema reference, so it
 * would send the validator looking for a document that does not exist. The
 * property's own `type` and `format` stay, and they are what OpenRegister
 * rejects on.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Support
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Support;

use OCA\Dossiq\Service\Settings\RegisterFragmentMerger;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * The merged register's schemas, and a validator over them.
 */
class RealSchemaValidator {

	/**
	 * Schema slug to schema, as the importer merges them.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $schemas = [];

	/**
	 * The merged register's seed objects.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $objects = [];

	/**
	 * Load the merged register.
	 */
	public function __construct() {
		$dir = dirname(__DIR__, 2) . '/lib/Settings';
		$base = json_decode((string)file_get_contents($dir . '/dossiq_register.json'), true);
		[$merged] = (new RegisterFragmentMerger())->merge(base: $base, fragmentDir: $dir . '/register.d');
		$this->schemas = $merged['components']['schemas'];
		$this->objects = ($merged['components']['objects'] ?? []);
	}//end __construct()

	/**
	 * Why a payload does not fit its schema, or [] when it does.
	 *
	 * @param string               $slug     The schema slug.
	 * @param array<string, mixed> $payload  The object as it would be saved.
	 * @param bool                 $creating Whether `required` applies (a create) or not (a patch).
	 *
	 * @return array<int, string> One line per error.
	 */
	public function errors(string $slug, array $payload, bool $creating = true): array {
		$schema = $this->jsonSchema(schema: $this->schemas[$slug], creating: $creating);
		unset($payload['@self'], $payload['id'], $payload['uuid']);

		$validator = new Validator();
		$validator->setMaxErrors(20);
		$result = $validator->validate(json_decode((string)json_encode($payload)), json_decode((string)json_encode($schema)));
		if ($result->isValid() === true) {
			return [];
		}

		$lines = [];
		foreach ((new ErrorFormatter())->format($result->error(), true) as $path => $messages) {
			foreach ((array)$messages as $message) {
				$lines[] = $slug . ' ' . $path . ': ' . $message;
			}
		}

		return $lines;
	}//end errors()

	/**
	 * The OpenRegister schema as plain JSON Schema.
	 *
	 * @param array<string, mixed> $schema   The OpenRegister schema.
	 * @param bool                 $creating Whether to keep `required`.
	 *
	 * @return array<string, mixed>
	 */
	private function jsonSchema(array $schema, bool $creating): array {
		$out = ['type' => 'object', 'properties' => []];
		$required = array_values((array)($schema['required'] ?? []));
		foreach (($schema['properties'] ?? []) as $name => $property) {
			$out['properties'][$name] = $this->widenOptional(
				property: $this->property(property: (array)$property),
				required: in_array($name, $required, true)
			);
		}

		if ($creating === true && empty($schema['required']) === false) {
			$out['required'] = array_values($schema['required']);
		}

		return $out;
	}//end jsonSchema()

	/**
	 * A top-level property that is not required also accepts null, as OpenRegister widens it.
	 *
	 * Mirrors ValidateObject (openregister development, the loop "For non-required
	 * fields, allow null values by modifying the type"): an enum without null
	 * stays strict. Without this a writer that clears an optional field with
	 * null reads as refused here and is accepted by the real store.
	 *
	 * @param array<string, mixed> $property The property.
	 * @param bool                 $required Whether the schema requires it.
	 *
	 * @return array<string, mixed>
	 */
	private function widenOptional(array $property, bool $required): array {
		if ($required === true || isset($property['type']) === false) {
			return $property;
		}

		if (isset($property['enum']) === true && in_array(null, (array)$property['enum'], true) === false) {
			return $property;
		}

		$types = (array)$property['type'];
		if (in_array('null', $types, true) === false) {
			$types[] = 'null';
		}

		$property['type'] = $types;

		return $property;
	}//end widenOptional()

	/**
	 * One property, keeping only the keywords a JSON-Schema validator judges on.
	 *
	 * @param array<string, mixed> $property The property.
	 *
	 * @return array<string, mixed>
	 */
	private function property(array $property): array {
		$out = [];
		foreach (['type', 'format', 'enum', 'minimum', 'maximum', 'maxLength', 'pattern'] as $key) {
			if (array_key_exists($key, $property) === true) {
				$out[$key] = $property[$key];
			}
		}

		if (isset($property['items']) === true && is_array($property['items']) === true) {
			$out['items'] = $this->property(property: $property['items']);
		}

		if (isset($property['properties']) === true && is_array($property['properties']) === true) {
			$out['properties'] = [];
			foreach ($property['properties'] as $name => $nested) {
				$out['properties'][$name] = $this->property(property: (array)$nested);
			}
		}

		return $out;
	}//end property()
}//end class
