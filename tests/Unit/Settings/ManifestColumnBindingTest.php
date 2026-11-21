<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Settings
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Every manifest column and include key names a property the schema actually has.
 *
 * A column key that matches no property renders an em-dash in every row and
 * says nothing else. There is no console warning, no failed request and no
 * empty state: the list looks populated, and one column is simply blank
 * forever. The acceptance proof reported it as "the Voorstellen Subject column
 * shows a dash", and the cause was that the column asked for `onderwerp` while
 * the property had been renamed to `subject`.
 *
 * That rename is where the whole class comes from. `lib/Repair/RenameDutchColumns.php`
 * carries the Dutch to English map and moves the stored DATA; the manifest is
 * a separate file it never touches, so every key the rename moved had to be
 * followed by hand and six were missed across five pages. A seventh mismatch,
 * `updatedAt` on WorkflowDefinitions, is older: the timestamp lives on
 * `@self.updated` and never was a schema property.
 *
 * The sweep counts what it inspected before it asserts anything, so a manifest
 * that stopped parsing, or a schema map that came back empty, fails loudly
 * rather than reading as clean.
 */
class ManifestColumnBindingTest extends TestCase {

	/**
	 * Repository root.
	 *
	 * @var string
	 */
	private const ROOT = __DIR__ . '/../../..';

	/**
	 * The register this app ships, and the one a computed column defaults to.
	 *
	 * @var string
	 */
	private const OWN_REGISTER = 'dossiq';

	/**
	 * Every computed column the last sweep met.
	 *
	 * @var array<int, array{where: string, op: string, field: string, register: string, schema: string}>
	 */
	private array $aggregates = [];

	/**
	 * Keys that are legitimately not schema properties.
	 *
	 * `@self.*` is OpenRegister's metadata envelope (created, updated, owner);
	 * `id` is the object id. Both resolve at render time and neither appears
	 * in a schema's `properties` map.
	 *
	 * @param string $key A column or include key.
	 *
	 * @return boolean True when the key resolves outside the schema.
	 */
	private function isEnvelopeKey(string $key): bool {
		return str_starts_with($key, '@') === true
			|| str_starts_with($key, '_') === true
			|| $key === 'id';
	}

	/**
	 * Every property name declared by every shipped schema, keyed by slug.
	 *
	 * The base register plus the `register.d/` fragments, merged the way the
	 * importer merges them: the base wins, a fragment only adds.
	 *
	 * @return array<string, array<int, string>> Schema slug => property names.
	 */
	private function shippedSchemaProperties(): array {
		$files = [self::ROOT . '/lib/Settings/dossiq_register.json'];
		foreach ((array)glob(self::ROOT . '/lib/Settings/register.d/*.json') as $file) {
			$files[] = (string)$file;
		}

		$schemas = [];
		foreach ($files as $file) {
			$data = json_decode((string)file_get_contents((string)$file), true);
			if (is_array($data) === false) {
				continue;
			}

			$declared = (array)(((array)($data['components'] ?? []))['schemas'] ?? []);
			foreach ($declared as $slug => $schema) {
				$slug = (string)$slug;
				$properties = array_keys((array)(((array)$schema)['properties'] ?? []));

				// UNION, not first-file-wins. A fragment on a schema the base
				// already declares adds properties to it, which is the whole
				// point of `register.d/`; skipping the schema outright made
				// every fragment-added property read as one the schema does
				// not declare, and the failure named the manifest rather than
				// this reader.
				$schemas[$slug] = array_values(
					array_unique(array_merge(($schemas[$slug] ?? []), $properties))
				);
			}
		}

		return $schemas;
	}

	/**
	 * Every (where, schema, key) triple the manifest binds a column to.
	 *
	 * Covers an index page's `config.columns`, a widget's `content.columns`
	 * and a data widget's `content.include` — the three places a manifest
	 * names a property to render.
	 *
	 * @return array<int, array{where: string, schema: string, key: string}> The bindings.
	 */
	private function manifestBindings(): array {
		$manifest = json_decode((string)file_get_contents(self::ROOT . '/src/manifest.json'), true);
		$this->assertIsArray($manifest, 'src/manifest.json did not parse');

		$bindings = [];
		foreach ((array)($manifest['pages'] ?? []) as $index => $page) {
			$config = (array)(((array)$page)['config'] ?? []);
			$pageSchema = (string)($config['schema'] ?? '');
			$label = 'pages[' . $index . '] ' . (string)(((array)$page)['id'] ?? '?');

			$this->collect(
				bindings: $bindings,
				where: $label . '.config.columns',
				schema: $pageSchema,
				keys: (array)($config['columns'] ?? []),
			);

			foreach ((array)($config['widgets'] ?? []) as $widget) {
				$content = (array)(((array)$widget)['content'] ?? []);
				$source = (array)($content['dataSource'] ?? []);
				$schema = (string)($content['schema'] ?? ($source['schema'] ?? $pageSchema));
				$widgetLabel = $label . '/' . (string)(((array)$widget)['id'] ?? '?');

				// A `metadata` widget's `include` names METADATA keys, not schema
				// properties. It renders CnObjectMetadataWidget, which reads the
				// `@self` envelope — `version`, `locked`, and the keys of the
				// resolved `_retention` decision — none of which a schema
				// declares, and none of which it should.
				//
				// Checking those against the schema is not a stricter version of
				// this guard, it is a different question with a guaranteed wrong
				// answer: every metadata key would be reported as undeclared, so
				// the only way to keep the suite green would be to stop using the
				// widget. The envelope allowlist below covers `@self.*`, but these
				// keys are addressed bare.
				if ((string)(((array)$widget)['type'] ?? '') === 'metadata') {
					continue;
				}

				// `readOnlyRows` (nextcloud-vue) adds rows read from an endpoint
				// beside the schema's own; a key its `where` filters on is a value
				// those rows carry (the blueprint's `origin`), and an own row shows
				// the column's `emptyValue`. Such a column binds the endpoint's
				// rows, not the schema.
				$endpointKeys = array_keys((array)(((array)($content['readOnlyRows'] ?? []))['where'] ?? []));
				$this->collect(
					bindings: $bindings,
					where: $widgetLabel . '.content.columns',
					schema: $schema,
					keys: array_values(
						array_filter(
							(array)($content['columns'] ?? []),
							static fn ($column): bool => in_array((is_array($column) === true ? ($column['key'] ?? null) : $column), $endpointKeys, true) === false
						)
					),
				);
				$this->collect(
					bindings: $bindings,
					where: $widgetLabel . '.content.include',
					schema: $schema,
					keys: (array)($content['include'] ?? []),
				);
			}
		}

		return $bindings;
	}

	/**
	 * Normalise one `columns` / `include` array into binding triples.
	 *
	 * An entry is either a bare string key or an object carrying `key`.
	 *
	 * @param array<int, array{where: string, schema: string, key: string}> $bindings Accumulator, by reference.
	 * @param string                                                       $where    Human-readable manifest path.
	 * @param string                                                       $schema   Schema slug the keys resolve against.
	 * @param array<int|string, mixed>                                     $keys     The raw entries.
	 *
	 * @return void
	 */
	private function collect(array &$bindings, string $where, string $schema, array $keys): void {
		if ($schema === '') {
			return;
		}

		foreach ($keys as $entry) {
			$key = $entry;
			if (is_array($entry) === true) {
				$key = ($entry['key'] ?? null);
			}

			if (is_string($key) === false || $key === '') {
				continue;
			}

			// A COMPUTED column. `aggregate` makes CnDataTable count the rows of
			// ANOTHER schema per row (`loadAggregates`), and the cell reads that
			// count instead of a property of the row, so the column key is only
			// a cache slot and names nothing in the page's schema. What it does
			// bind is the other schema: every `where` field is sent to
			// OpenRegister as a filter on it, and every `@self.<path>` value is
			// read off the row. Those are checked instead of the key, so a count
			// over a field that does not exist fails here rather than reading 0
			// in every row.
			if (is_array($entry) === true && is_array($entry['aggregate'] ?? null) === true) {
				$this->collectAggregate(
					bindings: $bindings,
					where: $where . '[' . $key . '].aggregate',
					rowSchema: $schema,
					aggregate: $entry['aggregate'],
				);
				continue;
			}

			$bindings[] = ['where' => $where, 'schema' => $schema, 'key' => $key];
		}
	}

	/**
	 * Turn one computed column into the bindings it really makes.
	 *
	 * @param array<int, array{where: string, schema: string, key: string}> $bindings  Accumulator, by reference.
	 * @param string                                                       $where     Human-readable manifest path.
	 * @param string                                                       $rowSchema Schema slug of the row the column sits on.
	 * @param array<string, mixed>                                         $aggregate The column's `aggregate` block.
	 *
	 * @return void
	 */
	private function collectAggregate(array &$bindings, string $where, string $rowSchema, array $aggregate): void {
		// `endpoint` (nextcloud-vue aggregate-from-endpoint): the app's own route answers a value per row key,
		// so no schema is counted; the row field that names the key must resolve.
		if (is_string($aggregate['endpoint'] ?? null) === true) {
			$this->aggregates[] = ['where' => $where, 'endpoint' => $aggregate['endpoint']];
			$bindings[]         = ['where' => $where . '.key', 'schema' => $rowSchema, 'key' => (string)($aggregate['key'] ?? '')];
			return;
		}

		$counted = (string)($aggregate['schema'] ?? '');
		$this->aggregates[] = [
			'where'    => $where,
			'op'       => (string)($aggregate['op'] ?? ''),
			'field'    => (string)($aggregate['field'] ?? ''),
			'register' => (string)($aggregate['register'] ?? self::OWN_REGISTER),
			'schema'   => $counted,
		];

		// `op: "max"` reads one field of the counted schema: that field must resolve too.
		if (($aggregate['op'] ?? '') === 'max' && (string)($aggregate['field'] ?? '') !== '') {
			$bindings[] = ['where' => $where . '.field', 'schema' => $counted, 'key' => (string)$aggregate['field']];
		}

		foreach ((array)($aggregate['where'] ?? []) as $field => $value) {
			$bindings[] = ['where' => $where . '.where', 'schema' => $counted, 'key' => (string)$field];

			if (is_string($value) === true && str_starts_with($value, '@self.') === true) {
				$bindings[] = [
					'where'  => $where . '.where.' . (string)$field,
					'schema' => $rowSchema,
					'key'    => substr($value, strlen('@self.')),
				];
			}
		}
	}

	/**
	 * Every computed column asks for an aggregate the table runs, over a schema that ships.
	 *
	 * CnDataTable resolves `op: "count"` and, since nextcloud-vue 2.98.0,
	 * `op: "max"` with a `field` (the newest value of that field among the
	 * related rows). Any other `op`, or a `max` without its field, is
	 * filtered out before a request is made and the cell stays a dash. A
	 * count over a schema this register does not ship fails per row and
	 * degrades to the same dash. Both are the silent blank column this file
	 * exists to catch, reached through the computed door.
	 *
	 * @return void
	 */
	public function testEveryComputedColumnCountsASchemaThatShips(): void {
		$schemas = $this->shippedSchemaProperties();
		$this->aggregates = [];
		$this->manifestBindings();

		$this->assertGreaterThan(
			0,
			count($this->aggregates),
			'The sweep found no computed column, while the Organisations list ships one'
		);

		$offenders = [];
		$routes = file_get_contents(filename: self::ROOT . '/appinfo/routes.php');
		foreach ($this->aggregates as $aggregate) {
			if (isset($aggregate['endpoint']) === true) {
				$url = (string)preg_replace(pattern: '#^/apps/dossiq#', replacement: '', subject: $aggregate['endpoint']);
				if (str_contains(haystack: $routes, needle: "'url' => '" . $url . "'") === false) {
					$offenders[] = sprintf('%s asks endpoint "%s", which no route in appinfo/routes.php answers', $aggregate['where'], $aggregate['endpoint']);
				}

				continue;
			}

			if (in_array($aggregate['op'], ['count', 'max'], true) === false) {
				$offenders[] = sprintf('%s has op "%s"; the table runs only "count" and "max"', $aggregate['where'], $aggregate['op']);
			}

			if ($aggregate['op'] === 'max' && $aggregate['field'] === '') {
				$offenders[] = sprintf('%s has op "max" without a field; the table needs the field to read', $aggregate['where']);
			}

			if ($aggregate['register'] === self::OWN_REGISTER && isset($schemas[$aggregate['schema']]) === false) {
				$offenders[] = sprintf(
					'%s counts schema "%s", which this register does not ship',
					$aggregate['where'],
					$aggregate['schema']
				);
			}
		}

		$this->assertSame(
			[],
			$offenders,
			"A computed column that cannot be counted renders a dash in every row, silently:\n"
			. implode("\n", $offenders)
		);
	}

	/**
	 * No manifest column or include key is unresolvable against its schema.
	 *
	 * @return void
	 */
	public function testEveryManifestColumnKeyResolvesToASchemaProperty(): void {
		$schemas = $this->shippedSchemaProperties();
		$bindings = $this->manifestBindings();

		$this->assertGreaterThan(
			0,
			count($schemas),
			'The sweep loaded no schemas at all, so it could not have found a mismatch'
		);
		$this->assertGreaterThan(
			0,
			count($bindings),
			'The sweep found no column bindings at all, so it could not have found a mismatch'
		);

		$offenders = [];
		$checked = 0;
		foreach ($bindings as $binding) {
			if ($this->isEnvelopeKey($binding['key']) === true) {
				continue;
			}

			$properties = ($schemas[$binding['schema']] ?? null);
			if ($properties === null) {
				// A page over another app's schema. Not this repo's contract.
				continue;
			}

			$checked++;
			$root = explode('.', $binding['key'])[0];
			if (in_array($root, $properties, true) === true) {
				continue;
			}

			$offenders[] = sprintf(
				'%s asks for "%s", which schema "%s" does not declare',
				$binding['where'],
				$binding['key'],
				$binding['schema']
			);
		}

		$this->assertGreaterThan(
			0,
			$checked,
			'Every binding was skipped, so an all-clear says nothing about the manifest'
		);
		$this->assertSame(
			[],
			$offenders,
			"A column bound to a property the schema does not declare renders a dash in every row, silently:\n"
			. implode("\n", $offenders)
		);
	}
}
