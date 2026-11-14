<?php

/**
 * Dossiq Register Fragment Merger Test
 *
 * Covers how register.d fragments are read and merged: a missing or empty
 * directory, fragments that are not JSON objects, sorted file order, list
 * concatenation versus map recursion, scalar overwrites and the fragment hash.
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
 *
 * @spec openspec/specs/admin-settings/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Settings;

use OCA\Dossiq\Service\Settings\RegisterFragmentMerger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Reading and deep-merging register fragments.
 */
#[CoversClass(RegisterFragmentMerger::class)]
final class RegisterFragmentMergerTest extends TestCase {
	/** @var string The temporary fragment directory. */
	private string $dir = '';

	/**
	 * Create an empty fragment directory.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/dossiq-fragments-merge-' . bin2hex(random_bytes(4));
		mkdir($this->dir);
	}

	/**
	 * Remove the fragment directory.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ((glob($this->dir . '/*') ?: []) as $file) {
			unlink($file);
		}

		rmdir($this->dir);
	}

	/**
	 * Write a fragment file.
	 *
	 * @param string $name    The file name.
	 * @param string $content The raw content.
	 *
	 * @return void
	 */
	private function write(string $name, string $content): void {
		file_put_contents($this->dir . '/' . $name, $content);
	}

	/**
	 * A directory that does not exist leaves the base untouched with no hash.
	 *
	 * @return void
	 */
	public function testAMissingDirectoryReturnsTheBaseUnchanged(): void {
		$base = ['info' => ['version' => '1.0.0']];

		$this->assertSame([$base, ''], (new RegisterFragmentMerger())->merge($base, $this->dir . '/absent'));
	}

	/**
	 * A directory without JSON files leaves the base untouched with no hash.
	 *
	 * @return void
	 */
	public function testADirectoryWithoutJsonFilesReturnsTheBaseUnchanged(): void {
		$this->write('README.md', '{"info": {"title": "ignored"}}');
		$base = ['info' => ['title' => 'Dossiq']];

		$this->assertSame([$base, ''], (new RegisterFragmentMerger())->merge($base, $this->dir));
	}

	/**
	 * Fragments that are invalid JSON or not a JSON object are skipped and do not count towards the hash.
	 *
	 * @return void
	 */
	public function testFragmentsThatAreNotJsonObjectsAreSkipped(): void {
		$this->write('a.json', '{not json');
		$this->write('b.json', '"just a string"');
		$base = ['paths' => ['/cases' => []]];

		$this->assertSame([$base, ''], (new RegisterFragmentMerger())->merge($base, $this->dir));
	}

	/**
	 * Maps merge key by key, lists concatenate and scalars from later files overwrite.
	 *
	 * @return void
	 */
	public function testMapsRecurseListsConcatenateAndScalarsOverwrite(): void {
		$this->write('20-second.json', '{"info": {"title": "Second"}, "tags": ["b"], "components": {"schemas": {"task": {"type": "object"}}}}');
		$this->write('10-first.json', '{"info": {"title": "First", "x-owner": "dossiq"}, "tags": ["a"], "components": {"schemas": {"case": {"type": "object"}}}}');
		$base = [
			'info'       => ['title' => 'Base', 'version' => '1.0.0'],
			'tags'       => ['base'],
			'components' => ['schemas' => ['status' => ['type' => 'object']]],
		];

		[$merged, $hash] = (new RegisterFragmentMerger())->merge($base, $this->dir);

		$this->assertSame(
			[
				'info'       => ['title' => 'Second', 'version' => '1.0.0', 'x-owner' => 'dossiq'],
				'tags'       => ['base', 'a', 'b'],
				'components' => [
					'schemas' => [
						'status' => ['type' => 'object'],
						'case'   => ['type' => 'object'],
						'task'   => ['type' => 'object'],
					],
				],
			],
			$merged
		);
		$this->assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $hash);
	}

	/**
	 * A fragment array replaces a scalar, and a map replaces nothing it does not name.
	 *
	 * @return void
	 */
	public function testAnArrayReplacesAScalarInTheBase(): void {
		$this->write('a.json', '{"x-extra": {"enabled": true}, "info": {"description": ["line"]}}');

		[$merged] = (new RegisterFragmentMerger())->merge(['x-extra' => 'off', 'info' => ['description' => 'text']], $this->dir);

		$this->assertSame(['x-extra' => ['enabled' => true], 'info' => ['description' => ['line']]], $merged);
	}

	/**
	 * A version that is not a dotted number is overwritten like any other scalar.
	 *
	 * @return void
	 */
	public function testANonNumericVersionIsOverwritten(): void {
		$this->write('a.json', '{"info": {"version": "0.9.0"}}');

		[$merged] = (new RegisterFragmentMerger())->merge(['info' => ['version' => 'v2-beta']], $this->dir);

		$this->assertSame('0.9.0', $merged['info']['version']);
	}

	/**
	 * The hash names the fragment set: unchanged content hashes the same, changed content does not.
	 *
	 * @return void
	 */
	public function testTheHashFollowsTheFragmentContent(): void {
		$merger = new RegisterFragmentMerger();
		$this->write('a.json', '{"info": {"title": "One"}}');
		$this->write('bad.json', '{broken');

		[, $first]  = $merger->merge([], $this->dir);
		[, $second] = $merger->merge([], $this->dir);
		$this->assertSame($first, $second);
		$this->assertSame(substr(hash('sha256', "a.json:{\"info\": {\"title\": \"One\"}}\n"), 0, 12), $first);

		$this->write('a.json', '{"info": {"title": "Two"}}');
		[, $third] = $merger->merge([], $this->dir);
		$this->assertNotSame($first, $third);
	}
}
