<?php

/**
 * Dossiq Register Fragment Merger Test
 *
 * Pins how register.d fragments fold onto the base register: lists are
 * appended, objects merged key by key, scalars replaced, files applied in name
 * order, unreadable JSON skipped, and the content hash is empty when nothing
 * was applied.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Settings;

use OCA\Dossiq\Service\Settings\RegisterFragmentMerger;
use PHPUnit\Framework\TestCase;

/**
 * Fragment folding of the register JSON.
 *
 * @covers \OCA\Dossiq\Service\Settings\RegisterFragmentMerger
 */
final class RegisterFragmentMergerTest extends TestCase {
	/**
	 * The temporary fragment directory of the test.
	 *
	 * @var string
	 */
	private string $dir = '';

	/**
	 * A fresh, empty fragment directory.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/dossiq-fragments-' . bin2hex(random_bytes(4));
		mkdir($this->dir);
	}

	/**
	 * Remove the fragment directory.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach (glob($this->dir . '/*') ?: [] as $file) {
			unlink($file);
		}

		rmdir($this->dir);
	}

	/**
	 * A missing directory and an empty one both leave the base as it is, with no hash.
	 *
	 * @return void
	 */
	public function testWithoutFragmentsTheBaseIsReturnedUnchanged(): void {
		$base = ['components' => ['schemas' => ['case' => ['slug' => 'case']]]];
		$merger = new RegisterFragmentMerger();

		$this->assertSame([$base, ''], $merger->merge(base: $base, fragmentDir: $this->dir . '/missing'));
		$this->assertSame([$base, ''], $merger->merge(base: $base, fragmentDir: $this->dir));
	}

	/**
	 * Lists append, objects merge per key and scalars are replaced.
	 *
	 * @return void
	 */
	public function testAFragmentAppendsListsMergesObjectsAndReplacesScalars(): void {
		file_put_contents($this->dir . '/10-case.json', json_encode([
			'info' => ['version' => '2.0.0'],
			'components' => [
				'schemas' => [
					'case' => ['required' => ['status'], 'configuration' => ['x-openregister-lifecycle' => ['initial' => 'new']]],
					'complaint' => ['slug' => 'complaint'],
				],
			],
		]));
		$base = [
			'info' => ['version' => '1.0.0', 'title' => 'Dossiq'],
			'components' => [
				'schemas' => [
					'case' => ['slug' => 'case', 'required' => ['title'], 'configuration' => ['objectNameField' => 'title']],
				],
			],
		];

		[$merged, $hash] = (new RegisterFragmentMerger())->merge(base: $base, fragmentDir: $this->dir);

		$this->assertSame(['version' => '2.0.0', 'title' => 'Dossiq'], $merged['info']);
		$this->assertSame(['title', 'status'], $merged['components']['schemas']['case']['required']);
		$this->assertSame(
			['objectNameField' => 'title', 'x-openregister-lifecycle' => ['initial' => 'new']],
			$merged['components']['schemas']['case']['configuration']
		);
		$this->assertSame(['slug' => 'complaint'], $merged['components']['schemas']['complaint']);
		$this->assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $hash);
	}

	/**
	 * Fragments apply in file-name order, so a later file wins a scalar.
	 *
	 * @return void
	 */
	public function testFragmentsApplyInFileNameOrder(): void {
		file_put_contents($this->dir . '/20-late.json', '{"info":{"version":"3.0.0"}}');
		file_put_contents($this->dir . '/10-early.json', '{"info":{"version":"2.0.0"}}');

		[$merged] = (new RegisterFragmentMerger())->merge(base: ['info' => ['version' => '1.0.0']], fragmentDir: $this->dir);

		$this->assertSame('3.0.0', $merged['info']['version']);
	}

	/**
	 * A fragment that is not a JSON object is skipped; with nothing applied the hash is empty.
	 *
	 * @return void
	 */
	public function testInvalidFragmentsAreSkippedAndLeaveNoHash(): void {
		file_put_contents($this->dir . '/10-broken.json', '{"info":');
		file_put_contents($this->dir . '/20-scalar.json', '"just a string"');
		$base = ['info' => ['version' => '1.0.0']];

		$this->assertSame([$base, ''], (new RegisterFragmentMerger())->merge(base: $base, fragmentDir: $this->dir));
	}

	/**
	 * The hash follows the fragment content: a changed fragment changes it.
	 *
	 * @return void
	 */
	public function testTheHashChangesWithTheFragmentContent(): void {
		$merger = new RegisterFragmentMerger();
		file_put_contents($this->dir . '/10-case.json', '{"info":{"version":"2.0.0"}}');
		[, $first] = $merger->merge(base: [], fragmentDir: $this->dir);
		file_put_contents($this->dir . '/10-case.json', '{"info":{"version":"2.0.1"}}');
		[, $second] = $merger->merge(base: [], fragmentDir: $this->dir);

		$this->assertNotSame($first, $second);
		$this->assertSame($second, $merger->merge(base: ['other' => 1], fragmentDir: $this->dir)[1]);
	}
}
