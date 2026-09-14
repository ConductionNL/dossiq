<?php

/**
 * Structural guard: dossiq stores no per-user read state of its own.
 *
 * The read state belongs to OpenRegister for the same reason a grant does:
 * every leaf app needs one and none should hold it. A second store in dossiq
 * would not error, it would DISAGREE: the list's lens comes out of
 * OpenRegister's query and a dossiq-side marker would drift from it, so a row
 * would read unread in one place and read in another with nothing to say which
 * was right.
 *
 * This is a scan and not a claim. It fails when dossiq grows a migration, a
 * mapper or an entity for a read state, which is how such a store would have
 * to arrive.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Architecture
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
 *
 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Scans lib/ for a read state dossiq would be keeping itself.
 *
 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md
 */
class NoLocalReadStateTest extends TestCase {

	/**
	 * The library root under test.
	 *
	 * @var string
	 */
	private const LIB_DIR = __DIR__.'/../../../lib';

	/**
	 * What an author should reach for instead, named in every failure.
	 *
	 * @var string
	 */
	private const ADVICE = 'Call OpenRegister\'s read-state endpoint '
		.'(/apps/openregister/api/objects/{register}/{schema}/{id}/read-state) '
		.'instead. See openspec/changes/unread-state-on-the-case, design D-1.';

	/**
	 * No dossiq file creates a table whose name says read state.
	 *
	 * The scan is over the WHOLE of lib/, not over a migrations directory:
	 * dossiq has none, because it owns no schema at all, and a guard pointed
	 * at a directory that does not exist is a test that cannot fail.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md
	 */
	public function testNothingCreatesAReadStateTable(): void {
		$offenders = [];
		foreach ($this->phpFiles(self::LIB_DIR) as $file) {
			$body = (string)file_get_contents($file);
			if (preg_match('/createTable\s*\(\s*[\'"][^\'"]*(read|seen|unread)/i', $body) === 1) {
				$offenders[] = $this->shortName(file: $file);
			}
		}

		$this->assertSame(
			[],
			$offenders,
			'dossiq must not create a read-state table. '.self::ADVICE
		);
	}//end testNothingCreatesAReadStateTable()

	/**
	 * The scanner finds what it is looking for when it is there.
	 *
	 * Without this the two scans above would pass on a broken pattern, a wrong
	 * root, or an empty file list, and a guard that cannot fail reports the
	 * same green as one that passed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md
	 */
	public function testTheScannerCanFindSomething(): void {
		$files = $this->phpFiles(self::LIB_DIR);
		$this->assertGreaterThan(200, count($files), 'the scan root must be dossiq\'s lib/');

		$hits = 0;
		foreach ($files as $file) {
			if (str_contains((string)file_get_contents($file), 'createTable') === true) {
				$hits++;
			}
		}

		$this->assertGreaterThan(
			0,
			$hits,
			'the scan must be able to see a createTable call, or its silence proves nothing'
		);
	}//end testTheScannerCanFindSomething()

	/**
	 * No dossiq class is a read-state entity, mapper or store.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md
	 */
	public function testNoClassHoldsAReadState(): void {
		$offenders = [];
		foreach ($this->phpFiles(self::LIB_DIR) as $file) {
			$name = basename($file, '.php');
			if (preg_match('/(ReadState|UnreadState|SeenState)(Mapper|Store|Entity|Repository)?$/', $name) === 1) {
				$offenders[] = $name;
			}
		}

		$this->assertSame(
			[],
			$offenders,
			'dossiq must not hold a read-state entity, mapper or store. '.self::ADVICE
		);
	}//end testNoClassHoldsAReadState()

	/**
	 * No per-user preference is used as a read marker either.
	 *
	 * A read state hidden in `oc_preferences` is still a read state, and it is
	 * the cheapest way for one to arrive without anybody calling it that.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md
	 */
	public function testNoUserPreferenceIsUsedAsAReadMarker(): void {
		$offenders = [];
		foreach ($this->phpFiles(self::LIB_DIR) as $file) {
			$body = (string)file_get_contents($file);
			if (preg_match('/setUserValue\s*\([^)]*(read|seen|unread)/i', $body) === 1) {
				$offenders[] = $this->shortName(file: $file);
			}
		}

		$this->assertSame(
			[],
			$offenders,
			'dossiq must not keep a read marker in user preferences. '.self::ADVICE
		);
	}//end testNoUserPreferenceIsUsedAsAReadMarker()

	/**
	 * The one dossiq class that knows about unread reads a DECLARATION and
	 * writes nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md
	 */
	public function testTheTriggerServiceOnlyReadsADeclaration(): void {
		$body = (string)file_get_contents(self::LIB_DIR.'/Service/UnreadTriggerService.php');

		foreach (['IDBConnection', 'QBMapper', 'IConfig', 'setUserValue', 'saveObject'] as $writer) {
			$this->assertStringNotContainsString(
				$writer,
				$body,
				'UnreadTriggerService is a declaration reader and must not store anything. '.self::ADVICE
			);
		}
	}//end testTheTriggerServiceOnlyReadsADeclaration()

	/**
	 * The path of a scanned file, relative to lib/.
	 *
	 * @param string $file The absolute path.
	 *
	 * @return string The readable name.
	 */
	private function shortName(string $file): string {
		$root = (realpath(self::LIB_DIR) ?: self::LIB_DIR);

		return ltrim(str_replace($root, '', $file), '/');
	}//end shortName()

	/**
	 * Every PHP file under a directory, or none when it does not exist.
	 *
	 * @param string $dir The directory to walk.
	 *
	 * @return array<int, string> Absolute paths.
	 */
	private function phpFiles(string $dir): array {
		if (is_dir($dir) === false) {
			return [];
		}

		$files = [];
		$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
		foreach ($iterator as $entry) {
			if ($entry->isFile() === true && $entry->getExtension() === 'php') {
				$files[] = $entry->getPathname();
			}
		}

		sort($files);

		return $files;
	}//end phpFiles()
}//end class
