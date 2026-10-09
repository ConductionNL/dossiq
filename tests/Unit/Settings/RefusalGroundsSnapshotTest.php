<?php

/**
 * The shipped refusal grounds snapshot is the seeded list.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * @spec openspec/changes/woo-refusal-grounds-list/specs/woo-refusal-grounds/spec.md#requirement-a-release-time-snapshot-ships-for-the-redaction-fallback-req-wrg-008
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use OCA\Dossiq\Woo\WooRefusalGrounds;
use PHPUnit\Framework\TestCase;

/**
 * REQ-WRG-008: one label that differs fails.
 *
 * @coversNothing
 */
class RefusalGroundsSnapshotTest extends TestCase {

	/**
	 * Every active seeded ground is in the snapshot with the same values, in the list() shape.
	 *
	 * @return void
	 */
	public function testTheSnapshotMatchesTheSeed(): void {
		$root = dirname(__DIR__, 3);
		$snapshot = json_decode((string)file_get_contents($root . '/lib/Settings/woo-refusal-grounds.snapshot.json'), true);
		$fragment = json_decode((string)file_get_contents($root . '/lib/Settings/register.d/84-woo-refusal-grounds.json'), true);

		$this->assertSame('dossiq', $snapshot['source']);
		$this->assertSame($fragment['components']['schemas']['wooRefusalGround']['version'], $snapshot['version']);

		$byCode = [];
		foreach ($snapshot['grounds'] as $ground) {
			$this->assertSame(WooRefusalGrounds::KEYS, array_keys($ground));
			$byCode[$ground['code']] = $ground;
		}

		$active = array_filter($fragment['components']['objects'], static fn (array $object): bool => ($object['status'] ?? 'active') === 'active');
		$this->assertCount(count($active), $byCode);
		foreach ($active as $object) {
			$ground = ($byCode[$object['code']] ?? null);
			$this->assertNotNull($ground, $object['code'] . ' is missing from the snapshot');
			$this->assertSame($object['label'], $ground['label'], $object['code']);
			$this->assertSame(($object['parent'] ?? null), $ground['parent'], $object['code']);
			$this->assertSame($object['legalSource'], $ground['legalSource'], $object['code']);
			$this->assertSame($object['citable'], $ground['citable'], $object['code']);
		}
	}//end testTheSnapshotMatchesTheSeed()

	/**
	 * The check script exits non-zero and names the ground when one label differs.
	 *
	 * @return void
	 */
	public function testTheCheckNamesAGroundThatDiffers(): void {
		$root = dirname(__DIR__, 3);
		$path = $root . '/lib/Settings/woo-refusal-grounds.snapshot.json';
		$original = (string)file_get_contents($path);
		$snapshot = json_decode($original, true);
		$snapshot['grounds'][5]['label'] = 'Een ander label';
		$code = $snapshot['grounds'][5]['code'];

		file_put_contents($path, json_encode($snapshot));
		try {
			exec('php ' . escapeshellarg($root . '/tools/refusal-grounds-snapshot.php') . ' --check 2>&1', $lines, $exit);
		} finally {
			file_put_contents($path, $original);
		}

		$this->assertSame(1, $exit);
		$this->assertStringContainsString($code, implode("\n", $lines));
	}//end testTheCheckNamesAGroundThatDiffers()
}//end class
