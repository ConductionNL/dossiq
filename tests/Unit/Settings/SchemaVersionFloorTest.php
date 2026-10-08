<?php

/**
 * A schema change the importer cannot see must move the schema's version.
 *
 * 🔴 THE FAILURE THIS RATCHETS IS SILENT BY CONSTRUCTION. OpenRegister's
 * `ImportHandler::importSchema` skips a schema whose version is not newer,
 * UNLESS `schemaContentDiffers()` says the content moved. That helper compares
 * exactly `properties`, `required` and `authorization`. Anything else —
 * `configuration.linkedTypes`, `configuration.mailObjectTemplate`, the
 * `x-openregister-*` blocks — is invisible to it, so a change there with the
 * version left alone logs one debug line and returns the stored schema. The app
 * keeps working. The feature is simply not on the instance, and nothing
 * anywhere says so.
 *
 * MEASURED, NOT ASSUMED. Walking `parity/round2` from where it was cut
 * (0d6be921) found three schemas in exactly that state, all from
 * leaf-integrations (#2927): `inspectionChecklistRun` and `complaint` in
 * `dossiq_register.json`, and `fieldInspection` in the offline-inspection
 * fragment. Each had gained a `configuration` block or a `linkedTypes` entry
 * with its `version` untouched. All three are bumped in the same commit as this
 * test.
 *
 * 🔴 IT DELIBERATELY DOES NOT DEMAND A BUMP FOR EVERY EDIT. A change inside the
 * three rescued keys reaches the instance whatever the version says, so
 * requiring a bump for one would be asking for a number nothing reads — a
 * treadmill that gets suppressed rather than followed. The digest is taken over
 * the remainder only, which is the exact set the importer is blind to.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use OCA\Dossiq\Tests\Support\SchemaVersionDigest;
use PHPUnit\Framework\TestCase;

/**
 * The version floor, ratcheted against a recorded digest per schema.
 *
 * @coversNothing
 */
class SchemaVersionFloorTest extends TestCase {

	/**
	 * The repository root.
	 *
	 * @return string The path.
	 */
	private function root(): string {
		return dirname(__DIR__, 3);
	}//end root()

	/**
	 * The digests as recorded the last time somebody bumped deliberately.
	 *
	 * @return array<string, array{version: string, digest: string}> The rows.
	 */
	private function recorded(): array {
		$path = $this->root() . '/' . SchemaVersionDigest::DIGEST_FILE;
		$this->assertFileExists(
			$path,
			'the recorded digests are the whole gate; without them nothing below checks anything'
		);

		$rows = json_decode((string)file_get_contents($path), true);
		$this->assertIsArray($rows);

		return $rows;
	}//end recorded()

	/**
	 * Every shipped schema whose importer-invisible content moved has a new version.
	 *
	 * @return void
	 */
	public function testAnInvisibleChangeMovedItsVersion(): void {
		$recorded = $this->recorded();
		$current = SchemaVersionDigest::collect(root: $this->root());

		$this->assertGreaterThan(
			100,
			count($current),
			'too few schemas were read for this to mean anything; a gate over an empty set '
			. 'reports exactly the same green as one over the whole register'
		);

		$stale = [];
		foreach ($current as $key => $row) {
			$was = ($recorded[$key] ?? null);
			if ($was === null) {
				// A brand new schema has nothing to be stale against.
				continue;
			}

			if ($row['digest'] === $was['digest']) {
				continue;
			}

			if ($row['version'] === $was['version']) {
				$stale[] = sprintf('%s (still %s)', $key, $row['version']);
			}
		}

		$this->assertSame(
			[],
			$stale,
			"These schemas changed outside `properties`, `required` and `authorization`, which is "
			. "the only content OpenRegister's importer compares, and their `version` did not move. "
			. "The import will skip them on every instance that already has this version, silently. "
			. "Move each `version`, then run `php tools/schema-version-digests.php` to record it:\n  "
			. implode("\n  ", $stale)
		);
	}//end testAnInvisibleChangeMovedItsVersion()

	/**
	 * A version that went BACKWARDS is caught too.
	 *
	 * A ratchet that only fails on the way up is half a ratchet: a lowered
	 * version is skipped by the importer just as surely as an equal one, and it
	 * is the shape a bad merge resolution produces.
	 *
	 * @return void
	 */
	public function testNoVersionWentBackwards(): void {
		$recorded = $this->recorded();
		$current = SchemaVersionDigest::collect(root: $this->root());

		$lowered = [];
		foreach ($current as $key => $row) {
			$was = ($recorded[$key] ?? null);
			if ($was === null || $was['version'] === '' || $row['version'] === '') {
				continue;
			}

			if (version_compare($row['version'], $was['version'], '<') === true) {
				$lowered[] = sprintf('%s (%s -> %s)', $key, $was['version'], $row['version']);
			}
		}

		$this->assertSame([], $lowered, 'a schema version must never go backwards: ' . implode(', ', $lowered));
	}//end testNoVersionWentBackwards()

	/**
	 * The recorded file covers every schema that ships.
	 *
	 * Without this a schema could be dropped from the digest file and lose its
	 * gate while every other assertion stayed green, which is how a ratchet
	 * quietly stops ratcheting.
	 *
	 * @return void
	 */
	public function testTheRecordCoversEveryShippedSchema(): void {
		$recorded = $this->recorded();
		$current = SchemaVersionDigest::collect(root: $this->root());

		$missing = array_values(array_diff(array_keys($current), array_keys($recorded)));
		$orphaned = array_values(array_diff(array_keys($recorded), array_keys($current)));

		$this->assertSame(
			[],
			$missing,
			'these schemas ship with no recorded digest, so nothing gates them; run '
			. '`php tools/schema-version-digests.php`: ' . implode(', ', $missing)
		);
		$this->assertSame(
			[],
			$orphaned,
			'these digests name a schema that no longer ships; run '
			. '`php tools/schema-version-digests.php`: ' . implode(', ', $orphaned)
		);
	}//end testTheRecordCoversEveryShippedSchema()

	/**
	 * The digest is blind to exactly what the importer is blind to.
	 *
	 * The two must agree or the gate is measuring something adjacent: too wide
	 * and it demands bumps nothing reads, too narrow and it misses the silent
	 * skip it exists for.
	 *
	 * @return void
	 */
	public function testTheDigestIgnoresWhatTheImporterCompares(): void {
		$schema = [
			'version' => '1.0.0',
			'properties' => ['a' => ['type' => 'string']],
			'required' => ['a'],
			'authorization' => ['read' => ['admin']],
			'configuration' => ['linkedTypes' => ['mail']],
		];

		$base = SchemaVersionDigest::digestOf(schema: $schema);

		// Each of the three rescued keys reaches the instance on content alone,
		// so none of them may move the digest.
		foreach (SchemaVersionDigest::RESCUED_BY_CONTENT as $key) {
			$changed = $schema;
			$changed[$key] = ['completely' => 'different'];
			$this->assertSame(
				$base,
				SchemaVersionDigest::digestOf(schema: $changed),
				sprintf('"%s" is compared by the importer, so it must not demand a version bump', $key)
			);
		}

		// The version itself is what the gate is about, not part of what it hashes.
		$renumbered = $schema;
		$renumbered['version'] = '9.9.9';
		$this->assertSame($base, SchemaVersionDigest::digestOf(schema: $renumbered));

		// 🔴 AND THE CONTROL. `configuration` is the key the whole gate exists
		// for; if this did not move the digest, every assertion above would pass
		// against a gate that gates nothing.
		$reconfigured = $schema;
		$reconfigured['configuration']['linkedTypes'][] = 'talk';
		$this->assertNotSame(
			$base,
			SchemaVersionDigest::digestOf(schema: $reconfigured),
			'a configuration change MUST move the digest, or this gate is decoration'
		);
	}//end testTheDigestIgnoresWhatTheImporterCompares()
}//end class
