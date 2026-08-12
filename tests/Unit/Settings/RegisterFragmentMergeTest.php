<?php

/**
 * Register Fragment Merge Unit Tests (ADR-037)
 *
 * Validates the modular register-fragment deep-merge logic on
 * SettingsService, exercised through reflection because the merge
 * helpers are private static methods.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Settings;

use OCA\Procest\Service\Settings\RegisterFragmentMerger;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Unit tests for the ADR-037 register fragment deep-merge (deepMergeConfig)
 * and the directory fragment loader (mergeRegisterFragments).
 */
class RegisterFragmentMergeTest extends TestCase {
	/**
	 * Invoke a private static method on SettingsService via reflection.
	 *
	 * @param string $method The private method name on RegisterFragmentMerger.
	 * @param array $args Positional arguments to pass.
	 *
	 * @return mixed The method's return value.
	 */
	private function invokePrivate(string $method, array $args) {
		$reflection = new ReflectionMethod(RegisterFragmentMerger::class, $method);
		$reflection->setAccessible(true);

		return $reflection->invokeArgs(new RegisterFragmentMerger(), $args);
	}//end invokePrivate()

	/**
	 * Merge fragments through the public entry point.
	 *
	 * @param array $base The base configuration.
	 * @param string $dir The fragment directory.
	 *
	 * @return array{0: array<string,mixed>, 1: string} The merged config and hash.
	 */
	private function merge(array $base, string $dir): array {
		return (new RegisterFragmentMerger())->merge(base: $base, fragmentDir: $dir);
	}//end merge()

	/**
	 * deepMergeConfig merges nested associative maps key-by-key.
	 *
	 * @return void
	 */
	public function testDeepMergeMergesNestedMaps(): void {
		$base = [
			'components' => [
				'schemas' => [
					'Existing' => ['title' => 'Existing'],
				],
			],
		];
		$override = [
			'components' => [
				'schemas' => [
					'Added' => ['title' => 'Added'],
				],
			],
		];

		$result = $this->invokePrivate('deepMerge', [$base, $override]);

		$this->assertArrayHasKey('Existing', $result['components']['schemas']);
		$this->assertArrayHasKey('Added', $result['components']['schemas']);
		$this->assertSame('Existing', $result['components']['schemas']['Existing']['title']);
		$this->assertSame('Added', $result['components']['schemas']['Added']['title']);

	}//end testDeepMergeMergesNestedMaps()

	/**
	 * deepMergeConfig lets a scalar override replace a base scalar.
	 *
	 * @return void
	 */
	public function testDeepMergeOverridesScalar(): void {
		$base = ['info' => ['version' => '1.0.0', 'title' => 'Procest']];
		$override = ['info' => ['version' => '2.0.0']];

		$result = $this->invokePrivate('deepMerge', [$base, $override]);

		$this->assertSame('2.0.0', $result['info']['version']);
		$this->assertSame('Procest', $result['info']['title']);

	}//end testDeepMergeOverridesScalar()

	/**
	 * deepMergeConfig concatenates list arrays (ADR-037 fragment append).
	 *
	 * @return void
	 */
	public function testDeepMergeConcatenatesLists(): void {
		$base = ['required' => ['a', 'b']];
		$override = ['required' => ['c']];

		$result = $this->invokePrivate('deepMerge', [$base, $override]);

		$this->assertSame(['a', 'b', 'c'], $result['required']);

	}//end testDeepMergeConcatenatesLists()

	/**
	 * deepMergeConfig adds keys that exist only in the override.
	 *
	 * @return void
	 */
	public function testDeepMergeAddsNewKeys(): void {
		$base = ['a' => 1];
		$override = ['b' => 2];

		$result = $this->invokePrivate('deepMerge', [$base, $override]);

		$this->assertSame(['a' => 1, 'b' => 2], $result);

	}//end testDeepMergeAddsNewKeys()

	/**
	 * mergeRegisterFragments returns the base unchanged with an empty hash
	 * when the fragment directory is missing.
	 *
	 * @return void
	 */
	public function testMergeFragmentsNoDirectory(): void {
		$base = ['info' => ['version' => '1.0.0']];

		[$merged, $hash] = $this->merge($base, '/nonexistent/register.d');

		$this->assertSame($base, $merged);
		$this->assertSame('', $hash);

	}//end testMergeFragmentsNoDirectory()

	/**
	 * mergeRegisterFragments deep-merges real fragment files in sorted
	 * order and returns a non-empty hash fingerprinting the fragment set.
	 *
	 * @return void
	 */
	public function testMergeFragmentsMergesFilesInOrder(): void {
		$dir = sys_get_temp_dir() . '/procest-frag-test-' . uniqid();
		mkdir($dir);

		file_put_contents(
			$dir . '/10-first.json',
			json_encode(['components' => ['schemas' => ['First' => ['title' => 'First']]]])
		);
		file_put_contents(
			$dir . '/20-second.json',
			json_encode(['components' => ['schemas' => ['Second' => ['title' => 'Second']]]])
		);

		$base = ['components' => ['schemas' => ['Base' => ['title' => 'Base']]]];

		[$merged, $hash] = $this->merge($base, $dir);

		// Cleanup.
		unlink($dir . '/10-first.json');
		unlink($dir . '/20-second.json');
		rmdir($dir);

		$schemas = $merged['components']['schemas'];
		$this->assertArrayHasKey('Base', $schemas);
		$this->assertArrayHasKey('First', $schemas);
		$this->assertArrayHasKey('Second', $schemas);
		$this->assertNotSame('', $hash);
		$this->assertSame(12, strlen($hash));

	}//end testMergeFragmentsMergesFilesInOrder()

	/**
	 * mergeRegisterFragments returns an empty hash when only non-JSON files
	 * (e.g. README.md) are present.
	 *
	 * @return void
	 */
	public function testMergeFragmentsIgnoresNonJson(): void {
		$dir = sys_get_temp_dir() . '/procest-frag-readme-' . uniqid();
		mkdir($dir);
		file_put_contents($dir . '/README.md', '# not a fragment');

		$base = ['info' => ['version' => '1.0.0']];

		[$merged, $hash] = $this->merge($base, $dir);

		unlink($dir . '/README.md');
		rmdir($dir);

		$this->assertSame($base, $merged);
		$this->assertSame('', $hash);

	}//end testMergeFragmentsIgnoresNonJson()
}//end class
