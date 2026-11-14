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
 * @spec openspec/specs/woo-refusal-grounds/spec.md#requirement-a-release-time-snapshot-ships-for-the-redaction-fallback-req-wrg-008
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
	 * The disposable copy of the tool's inputs, removed in tearDown().
	 *
	 * @var string|null
	 */
	private ?string $copy = null;

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
	 * ## Why it tampers with a copy
	 *
	 * This used to overwrite the tracked `woo-refusal-grounds.snapshot.json` in
	 * place and restore it in a `finally`, which made the test need write access
	 * to a file the app ships. Two things follow from that, and both have cost
	 * real time:
	 *
	 *  - It fails wherever the suite runs as a user other than the checkout's
	 *    owner. Measured 2026-10-15 in the dossiq dev container, where phpunit
	 *    runs as `www-data` against a host-owned bind mount: `file_put_contents()`
	 *    was refused, the check then read an untampered snapshot and exited 0, and
	 *    all the suite reported was `Failed asserting that 0 is identical to 1`,
	 *    a message that sends somebody to look for drift in a snapshot that is
	 *    correct.
	 *  - A run killed between the write and the restore leaves the SHIPPED
	 *    snapshot carrying a label nobody wrote, because a `finally` does not
	 *    survive SIGKILL.
	 *
	 * The tool resolves both its paths from its own location, so a disposable
	 * copy of the three files it reads puts the tamper somewhere losing it costs
	 * nothing, and the assertions stay exactly what they were.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-10-30-refusal-grounds-check-test-needs-no-writable-tree/proposal.md
	 */
	public function testTheCheckNamesAGroundThatDiffers(): void {
		$root = dirname(__DIR__, 3);
		$shipped = $root . '/lib/Settings/woo-refusal-grounds.snapshot.json';
		$before = (string)file_get_contents($shipped);

		$copy = $this->disposableCopy(root: $root);
		$tool = 'php ' . escapeshellarg($copy . '/tools/refusal-grounds-snapshot.php') . ' --check 2>&1';
		$snapshotPath = $copy . '/lib/Settings/woo-refusal-grounds.snapshot.json';

		// The control. Without it an unreadable copy produces the same exit 1 as
		// a ground that differs, and the red would be this test's own failure
		// dressed as its subject's.
		exec($tool, $clean, $cleanExit);
		$this->assertSame(0, $cleanExit, 'the untampered copy must check out: ' . implode("\n", $clean));

		$snapshot = json_decode((string)file_get_contents($snapshotPath), true);
		$snapshot['grounds'][5]['label'] = 'Een ander label';
		$code = $snapshot['grounds'][5]['code'];
		$this->assertNotFalse(file_put_contents($snapshotPath, json_encode($snapshot)));

		exec($tool, $lines, $exit);

		$this->assertSame(1, $exit);
		$this->assertStringContainsString($code, implode("\n", $lines));
		$this->assertSame($before, (string)file_get_contents($shipped), 'the shipped snapshot must not be written to');
	}//end testTheCheckNamesAGroundThatDiffers()

	/**
	 * A disposable copy of the files the snapshot tool reads.
	 *
	 * The tool takes its root from `dirname(__DIR__)`, so the copy keeps the
	 * three paths it resolves and nothing else.
	 *
	 * @param string $root The app root.
	 *
	 * @return string Absolute path to the copy's root.
	 */
	private function disposableCopy(string $root): string {
		$copy = sys_get_temp_dir() . '/dossiq-refusal-grounds-' . bin2hex(random_bytes(6));
		$files = [
			'tools/refusal-grounds-snapshot.php',
			'lib/Settings/woo-refusal-grounds.snapshot.json',
			'lib/Settings/register.d/84-woo-refusal-grounds.json',
		];

		foreach ($files as $file) {
			$target = ($copy . '/' . $file);
			$directory = dirname($target);
			if (is_dir($directory) === false) {
				$this->assertTrue(mkdir($directory, 0700, true), $directory . ' must be creatable');
			}

			$this->assertTrue(copy($root . '/' . $file, $target), $file . ' must be copyable');
		}

		$this->copy = $copy;

		return $copy;
	}//end disposableCopy()

	/**
	 * Remove the disposable copy.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if ($this->copy !== null) {
			$files = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator($this->copy, \FilesystemIterator::SKIP_DOTS),
				\RecursiveIteratorIterator::CHILD_FIRST
			);
			foreach ($files as $file) {
				if ($file->isDir() === true) {
					rmdir($file->getPathname());
					continue;
				}

				unlink($file->getPathname());
			}

			rmdir($this->copy);
			$this->copy = null;
		}//end if

		parent::tearDown();
	}//end tearDown()
}//end class
