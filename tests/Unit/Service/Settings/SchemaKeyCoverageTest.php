<?php

/**
 * Schema key coverage sweep.
 *
 * `SchemaKeyReconciler` walks `SchemaSlugMap::SLUG_TO_CONFIG_KEY` and writes
 * one appconfig key per entry. A schema key that app code resolves but the
 * map does not carry is never written, so `getConfigValue()` answers '' and
 * the service that asked throws `..._not_configured` on its first call. That
 * failure is invisible until a user reaches the feature: nothing warns at
 * boot, and the unit tests pass because they stub the resolver.
 *
 * It had already happened to the whole beschikking lifecycle. This sweep
 * pins the remaining gaps so an eighth cannot be added quietly.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/specs/beschikking-generatie/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Settings;

use OCA\Dossiq\Service\Settings\SchemaSlugMap;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Settings\SchemaSlugMap
 */
class SchemaKeyCoverageTest extends TestCase {
	/**
	 * Matches an appconfig key read through the settings resolver, named or
	 * positional.
	 */
	private const RESOLVER_CALL_PATTERN = "/getConfigValue\\(\\s*(?:key:\\s*)?'([A-Za-z0-9_]+)'/";

	/**
	 * Schema keys that app code resolves and the reconciler does not write.
	 *
	 * Each one leaves the service that reads it dead. They are recorded, not
	 * forgiven: this list only ever shrinks. Adding an entry means shipping a
	 * feature that cannot run, so the fix is the slug map, never this array.
	 *
	 * @var array<string, string>
	 */
	private const KNOWN_UNRECONCILED = [
		'berichtenbox_message_schema' => 'Berichtenbox message log. Unmapped since the berichtenbox-integration change.',
		'besluit_schema' => 'The ZGW Besluit projection. BesluitMaterialisationService falls back to the slug `decision`, so it resolves by accident rather than by configuration.',
		'case_decision_schema' => 'Case-level decision link. Unmapped.',
		'dso_samenwerkverzoek_schema' => 'DSO samenwerkingsverzoek. Unmapped since the dso-omgevingsloket register landed.',
		'email_message_schema' => 'Inbound and outbound email records. Unmapped.',
		'field_evidence_schema' => 'Mobile inspection evidence. Unmapped since the mobiel-inspectie-offline register landed.',
		'woo_assessment_schema' => 'Woo assessment record. Unmapped.',
	];

	/**
	 * 🔴 EVERY SCHEMA KEY THE CODE RESOLVES IS EITHER RECONCILED OR RECORDED.
	 *
	 * Map a new schema slug and this stays green. Ship a service that reads a
	 * key nothing writes and this goes red naming the key, before a user
	 * finds the dead feature.
	 *
	 * @return void
	 */
	public function testEverySchemaKeyIsReconciledOrRecorded(): void {
		$resolved = $this->resolvedSchemaKeys();

		self::assertNotSame(
			[],
			$resolved,
			'The sweep found no schema keys at all: the detector is broken, not the tree clean.'
		);

		$mapped = array_values(SchemaSlugMap::SLUG_TO_CONFIG_KEY);
		$offenders = [];

		foreach ($resolved as $key) {
			if (in_array($key, $mapped, true) === true) {
				continue;
			}

			if (array_key_exists($key, self::KNOWN_UNRECONCILED) === true) {
				continue;
			}

			$offenders[] = $key;
		}

		self::assertSame(
			[],
			$offenders,
			"These schema keys are resolved by app code but written by nothing, so every service that reads "
			. "one is dead at runtime. Add the schema slug to SchemaSlugMap::SLUG_TO_CONFIG_KEY:\n - "
			. implode("\n - ", $offenders)
		);
	}//end testEverySchemaKeyIsReconciledOrRecorded()

	/**
	 * 🔴 THE GAP LIST ONLY SHRINKS.
	 *
	 * An entry that is now mapped must leave the list, so the list keeps
	 * describing the tree rather than the tree of a year ago.
	 *
	 * @return void
	 */
	public function testTheRecordedGapListHasNoStaleEntries(): void {
		$mapped = array_values(SchemaSlugMap::SLUG_TO_CONFIG_KEY);
		$stale = array_values(array_intersect(array_keys(self::KNOWN_UNRECONCILED), $mapped));

		self::assertSame(
			[],
			$stale,
			"These keys are now reconciled and must be removed from KNOWN_UNRECONCILED:\n - "
			. implode("\n - ", $stale)
		);
	}//end testTheRecordedGapListHasNoStaleEntries()

	/**
	 * 🔴 THE BESCHIKKING LIFECYCLE RESOLVES ITS FOUR SCHEMAS.
	 *
	 * The named case this sweep was written for. All four were imported into
	 * OpenRegister and none was mapped, so the whole Awb besluit lifecycle
	 * threw on its first save.
	 *
	 * @return void
	 */
	public function testTheBeschikkingLifecycleSchemasAreMapped(): void {
		$mapped = SchemaSlugMap::SLUG_TO_CONFIG_KEY;

		self::assertSame('beschikking_schema', $mapped['beschikking'] ?? null);
		self::assertSame('state_machine_log_schema', $mapped['stateMachineLog'] ?? null);
		self::assertSame('bezwaar_trigger_schema', $mapped['bezwaarTrigger'] ?? null);
		self::assertSame('mandaat_regeling_schema', $mapped['mandateArrangement'] ?? null);
	}//end testTheBeschikkingLifecycleSchemasAreMapped()

	/**
	 * Every distinct `*_schema` appconfig key resolved anywhere under lib/.
	 *
	 * @return array<int, string> Sorted, unique key names.
	 */
	private function resolvedSchemaKeys(): array {
		$root = dirname(__DIR__, 4);
		$keys = [];

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($root . '/lib', \FilesystemIterator::SKIP_DOTS)
		);

		foreach ($iterator as $info) {
			if ($info->isFile() === false || $info->getExtension() !== 'php') {
				continue;
			}

			$source = file_get_contents($info->getPathname());
			self::assertIsString($source, 'Could not read ' . $info->getPathname());

			$matches = [];
			preg_match_all(self::RESOLVER_CALL_PATTERN, $source, $matches);
			foreach ($matches[1] as $key) {
				if (str_ends_with($key, '_schema') === false) {
					continue;
				}

				$keys[$key] = true;
			}
		}

		$names = array_keys($keys);
		sort($names);

		return $names;
	}//end resolvedSchemaKeys()
}//end class
