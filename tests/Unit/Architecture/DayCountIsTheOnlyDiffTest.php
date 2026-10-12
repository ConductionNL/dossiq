<?php

/**
 * Structural guard: days between two dates are counted in one place.
 *
 * `Service\Time\DayCount` is the one class under `lib/` that may call
 * `DateTimeInterface::diff()`. Every other file that still does is named in
 * `day-count.allowlist.json` with the change that takes it and a reason a
 * reader can check, so a new site has to be classified by its author rather
 * than discovered by the next sweep. The list can only shrink: an entry whose
 * file no longer calls `diff()` fails as stale.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Architecture
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/termijnbewaking-op-engine-timers/specs/termijnbewaking-op-engine-timers/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Architecture;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Scans lib/ for date-difference arithmetic outside DayCount.
 */
class DayCountIsTheOnlyDiffTest extends TestCase {
	/**
	 * The one file that counts.
	 *
	 * @var string
	 */
	private const OWNER = 'lib/Service/Time/DayCount.php';

	/**
	 * The spellings PHP offers for the difference between two dates.
	 *
	 * @var array<int, string>
	 */
	private const SPELLINGS = ['->diff(', 'date_diff('];

	/**
	 * The app root.
	 *
	 * @return string
	 */
	private function root(): string {
		return dirname(__DIR__, 3);
	}//end root()

	/**
	 * The allowlist entries.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function entries(): array {
		$raw = file_get_contents(__DIR__ . '/day-count.allowlist.json');
		self::assertIsString($raw, 'day-count.allowlist.json is missing.');

		return json_decode($raw, true, 512, JSON_THROW_ON_ERROR)['entries'];
	}//end entries()

	/**
	 * Whether a source calls a date difference.
	 *
	 * @param string $source PHP source.
	 *
	 * @return bool
	 */
	private static function counts(string $source): bool {
		// Comments are not calls: a docblock may explain `->diff(` freely.
		$code = '';
		foreach (token_get_all($source) as $token) {
			if (is_array($token) === true && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) === true) {
				continue;
			}

			$code .= (is_array($token) === true) ? $token[1] : $token;
		}

		$code = (string)preg_replace('/\s+/', '', $code);
		foreach (self::SPELLINGS as $spelling) {
			if (str_contains($code, $spelling) === true) {
				return true;
			}
		}

		return false;
	}//end counts()

	/**
	 * Every file under lib/ that calls a date difference, relative to the root.
	 *
	 * @return array<int, string>
	 */
	private function sites(): array {
		$root = $this->root();
		$files = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($root . '/lib', FilesystemIterator::SKIP_DOTS)
		);

		$sites = [];
		foreach ($files as $file) {
			if ($file->getExtension() !== 'php') {
				continue;
			}

			if (self::counts(source: (string)file_get_contents($file->getPathname())) === true) {
				$sites[] = substr($file->getPathname(), (strlen($root) + 1));
			}
		}

		sort($sites);

		return $sites;
	}//end sites()

	/**
	 * A file that counts days itself is DayCount, or is on the list.
	 *
	 * @return void
	 */
	public function testOnlyDayCountCallsDiffUnlessAllowlisted(): void {
		$allowed = array_column($this->entries(), 'file');
		$unexplained = array_values(array_diff($this->sites(), $allowed, [self::OWNER]));

		self::assertSame(
			[],
			$unexplained,
			"These files count the days between two dates themselves:\n" . implode("\n", $unexplained)
			. "\n\nAsk OCA\\Dossiq\\Service\\Termijn\\DayCount (between() for a span, signed() for days left "
			. 'or days overdue), or WorkingDayRoll::daysBetween() for working days.'
		);
	}//end testOnlyDayCountCallsDiffUnlessAllowlisted()

	/**
	 * The owner really is where the counting happens.
	 *
	 * Without this the guard passes on a tree where DayCount was emptied and
	 * the arithmetic moved somewhere the scan does not look.
	 *
	 * @return void
	 */
	public function testDayCountIsWhereTheCountingIs(): void {
		self::assertContains(self::OWNER, $this->sites());
	}//end testDayCountIsWhereTheCountingIs()

	/**
	 * Every entry names an open change and gives a reason.
	 *
	 * @return void
	 */
	public function testEveryEntryNamesAChangeAndAReason(): void {
		foreach ($this->entries() as $entry) {
			self::assertNotEmpty($entry['file'] ?? '', 'An allowlist entry names no file.');
			self::assertTrue(
				is_dir($this->root() . '/openspec/changes/' . ($entry['change'] ?? '')),
				$entry['file'] . ' names no open change under openspec/changes/. An entry without an owner is not an entry.'
			);
			self::assertGreaterThan(
				40,
				strlen(trim((string)($entry['reason'] ?? ''))),
				$entry['file'] . ' is allowlisted with no reason a reader could check.'
			);
		}
	}//end testEveryEntryNamesAChangeAndAReason()

	/**
	 * An entry whose file no longer counts is stale, so the list only shrinks.
	 *
	 * @return void
	 */
	public function testNoEntryIsStale(): void {
		$sites = $this->sites();
		$files = array_column($this->entries(), 'file');

		self::assertSame($files, array_values(array_unique($files)), 'A file is allowlisted twice.');
		foreach ($files as $file) {
			self::assertContains($file, $sites, $file . ' is allowlisted but no longer calls diff(). Remove the entry.');
		}
	}//end testNoEntryIsStale()

	/**
	 * The scan sees a call however it is laid out, and ignores a comment.
	 *
	 * @return void
	 */
	public function testTheScanSeesACallAndIgnoresAComment(): void {
		self::assertTrue(self::counts(source: '<?php $days = (int)$from->diff($to)->days;'));
		self::assertTrue(self::counts(source: "<?php \$days = \$from\n\t->diff (\$to)->days;"));
		self::assertTrue(self::counts(source: '<?php $days = date_diff($from, $to)->days;'));
		self::assertFalse(self::counts(source: "<?php\n/** Never call `->diff(` here. */\n// \$a->diff(\$b)\n\$x = 1;"));
		self::assertFalse(self::counts(source: '<?php $changed = array_diff($a, $b); $set->difference($other);'));
	}//end testTheScanSeesACallAndIgnoresAComment()
}//end class
