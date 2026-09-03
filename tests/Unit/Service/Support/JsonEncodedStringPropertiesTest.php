<?php

/**
 * Tests for the JSON-encoded string property re-encoder.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Support;

use OCA\Dossiq\Service\Settings\RegisterFragmentMerger;
use OCA\Dossiq\Service\Support\JsonEncodedStringProperties;
use PHPUnit\Framework\TestCase;

/**
 * The map is a SWEEP over the shipped register, not a curated list.
 *
 * The defect this guards against is not "routeSnapshot was missed". It is that
 * dossiq's register declares fifteen properties as `type: string` while
 * storing JSON in them, OpenRegister decodes every one of them on read, and
 * the app's standard `array_merge($loaded, $changes)` update idiom then writes
 * an array back into a string. Fixing the one property that was reported would
 * leave fourteen loaded guns, so this test recomputes the whole map from the
 * shipped register configuration and fails on any difference — a new
 * JSON-encoded property cannot ship without landing in the class.
 *
 * @covers \OCA\Dossiq\Service\Support\JsonEncodedStringProperties
 * @uses \OCA\Dossiq\Service\Settings\RegisterFragmentMerger
 */
class JsonEncodedStringPropertiesTest extends TestCase {

	/**
	 * Every `type: string` property the shipped register describes as
	 * JSON-encoded, keyed by schema slug, read from the effective register
	 * configuration (the monolith plus the ADR-037 fragments).
	 *
	 * @return array<string, array<int, string>> The expected map.
	 */
	private function shippedMap(): array {
		$base = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../../lib/Settings/dossiq_register.json'),
			true
		);
		self::assertIsArray($base, 'The shipped register configuration must parse.');

		[$effective] = (new RegisterFragmentMerger())->merge(
			base: $base,
			fragmentDir: __DIR__ . '/../../../../lib/Settings/register.d'
		);

		$map = [];
		foreach ((($effective['components'] ?? [])['schemas'] ?? []) as $slug => $schema) {
			foreach (($schema['properties'] ?? []) as $property => $definition) {
				if (is_array($definition) === false || ($definition['type'] ?? '') !== 'string') {
					continue;
				}

				if (preg_match('/json-encoded/i', (string)($definition['description'] ?? '')) !== 1) {
					continue;
				}

				$map[(string)$slug][] = (string)$property;
			}
		}

		foreach ($map as $slug => $properties) {
			sort($properties);
			$map[$slug] = $properties;
		}

		ksort($map);

		return $map;
	}//end shippedMap()

	/**
	 * The class's map is exactly what the shipped register declares.
	 *
	 * @return void
	 */
	public function testTheMapCoversEveryJsonEncodedStringPropertyInTheShippedRegister(): void {
		$expected = $this->shippedMap();

		$actual = JsonEncodedStringProperties::PROPERTIES;
		ksort($actual);

		self::assertNotSame([], $expected, 'The shipped register must declare JSON-encoded string properties, or this sweep is vacuous.');
		self::assertSame(
			$expected,
			$actual,
			'JsonEncodedStringProperties::PROPERTIES has drifted from the shipped register. '
			. 'Every `type: string` property described as JSON-encoded is read back DECODED by OpenRegister, '
			. 'so any update that merges a loaded object and writes it back must re-encode it — add it here.'
		);
	}//end testTheMapCoversEveryJsonEncodedStringPropertyInTheShippedRegister()

	/**
	 * A decoded value is re-encoded; the update still wins.
	 *
	 * @return void
	 */
	public function testMergeForWriteReencodesADecodedSnapshot(): void {
		$steps = [['order' => 1, 'actor' => 'alice'], ['order' => 2, 'actor' => 'bob']];

		$payload = (new JsonEncodedStringProperties())->mergeForWrite(
			stored: ['status' => 'in_parafering', 'currentStep' => 1, 'routeSnapshot' => $steps],
			updates: ['status' => 'geaccordeerd', 'currentStep' => 0],
			schemaSlug: 'proposal',
		);

		self::assertIsString($payload['routeSnapshot'], 'The schema declares routeSnapshot a string, so that is what the write must carry.');
		self::assertSame($steps, json_decode($payload['routeSnapshot'], true), 'Re-encoding must not lose the snapshot.');
		self::assertSame('geaccordeerd', $payload['status']);
		self::assertSame(0, $payload['currentStep']);
	}//end testMergeForWriteReencodesADecodedSnapshot()

	/**
	 * A value that is already a string is left exactly as it is.
	 *
	 * @return void
	 */
	public function testAnAlreadyEncodedValueIsUntouched(): void {
		$payload = (new JsonEncodedStringProperties())->mergeForWrite(
			stored: ['routeSnapshot' => '[{"order":1}]', 'statusHistory' => 'not json at all'],
			updates: [],
			schemaSlug: 'proposal',
		);

		self::assertSame('[{"order":1}]', $payload['routeSnapshot']);
		self::assertSame('not json at all', $payload['statusHistory'], 'Malformed stored text is evidence, not something to rewrite.');
	}//end testAnAlreadyEncodedValueIsUntouched()

	/**
	 * A schema the map does not cover passes through untouched.
	 *
	 * @return void
	 */
	public function testAnUnknownSchemaIsAPassThrough(): void {
		$payload = (new JsonEncodedStringProperties())->mergeForWrite(
			stored: ['routeSnapshot' => ['a']],
			updates: ['x' => 1],
			schemaSlug: 'somethingElse',
		);

		self::assertSame(['a'], $payload['routeSnapshot']);
		self::assertSame(1, $payload['x']);
	}//end testAnUnknownSchemaIsAPassThrough()

}//end class
