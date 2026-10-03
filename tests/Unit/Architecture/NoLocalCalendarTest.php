<?php

/**
 * Structural guard: dossiq keeps no calendar of its own.
 *
 * Which days an organisation does not work is one fact, and ADR-022 puts it in
 * the engine. A second list here is not a convenience: it is a second answer to
 * the same question, and the two diverge the first time an administrator adds
 * a closure in one of them. That divergence is invisible, because both lists
 * produce plausible dates and neither reports the other.
 *
 * It matters more here than in most places because the dates are statutory.
 * The Algemene termijnenwet decides whether a citizen has until Friday or until
 * Monday, and a term computed against a stale local list is wrong in a way
 * nobody on the page can see.
 *
 * 🔑 IT LOOKS FOR THE LIST, NOT FOR THE WORDS. `Kcc\SlaCalculator` names
 * Koningsdag and Bevrijdingsdag in a docblock and holds nothing: it delegates
 * every question to `WorkingDayCalculator`. A scanner matching holiday names
 * would open a defect against a file that is already doing the right thing,
 * which is the mistake `DeclaredDisplayFlagHasReaderTest` records in this same
 * directory. So the evidence is a date literal in code, or the Easter
 * computation, and nothing else.
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
 * @spec openspec/changes/terms-on-the-engine-calendar/specs/termijnbewaking-schemas/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * No file under lib/ holds a holiday list, except the one on its way out.
 *
 * @coversNothing
 */
class NoLocalCalendarTest extends TestCase {
	/**
	 * The repository root.
	 *
	 * @var string
	 */
	private const ROOT = __DIR__.'/../../..';

	/**
	 * The allowlist of files that still hold one.
	 *
	 * @var string
	 */
	private const ALLOWLIST = __DIR__.'/no-local-calendar.allowlist.json';

	/**
	 * What a holiday list looks like in code.
	 *
	 * Fixed feast days as month-day literals, and the Easter computation that
	 * derives the moving ones. Both are the list itself rather than a mention
	 * of it, which is the distinction this whole test turns on.
	 *
	 * @var array<int, string>
	 */
	private const EVIDENCE = [
		"'01-01'",
		"'04-27'",
		"'05-05'",
		"'12-25'",
		"'12-26'",
		'easter_days',
		'easter_date',
		'function easterSunday',
	];

	/**
	 * Every PHP file under lib/.
	 *
	 * @return array<int, string> Absolute paths.
	 */
	private function libFiles(): array {
		$files = [];
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator(self::ROOT.'/lib', \FilesystemIterator::SKIP_DOTS)
		);

		foreach ($iterator as $file) {
			if (strtolower($file->getExtension()) === 'php') {
				$files[] = $file->getPathname();
			}
		}

		sort($files);

		return $files;
	}

	/**
	 * The files holding a list, as paths relative to the repository root.
	 *
	 * @return array<int, string> Relative paths, sorted.
	 */
	private function holders(): array {
		$holders = [];
		foreach ($this->libFiles() as $path) {
			$source = (string)file_get_contents($path);
			foreach (self::EVIDENCE as $marker) {
				if (str_contains($source, $marker) === true) {
					$holders[] = ltrim(str_replace((string)realpath(self::ROOT), '', (string)realpath($path)), '/');
					break;
				}
			}
		}

		sort($holders);

		return $holders;
	}

	/**
	 * The allowlist, as written.
	 *
	 * @return array<string, mixed> The decoded file.
	 */
	private function allowlist(): array {
		$decoded = json_decode((string)file_get_contents(self::ALLOWLIST), true);
		$this->assertIsArray($decoded, 'the allowlist is valid JSON');

		return $decoded;
	}

	/**
	 * The paths the allowlist excuses.
	 *
	 * @return array<int, string> The paths.
	 */
	private function allowlisted(): array {
		return array_map(
			static fn (array $entry): string => (string)($entry['file'] ?? ''),
			($this->allowlist()['entries'] ?? [])
		);
	}

	/**
	 * No new calendar arrives.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/terms-on-the-engine-calendar/specs/termijnbewaking-schemas/spec.md
	 */
	public function testNoFileUnderLibHoldsACalendar(): void {
		$unexcused = array_values(array_diff($this->holders(), $this->allowlisted()));

		$this->assertSame(
			[],
			$unexcused,
			"These files hold a holiday list of their own:\n  ".implode("\n  ", $unexcused)
				."\nWhich days are not worked is the engine working calendar's answer (ADR-022). Ask it, or "
				."add an entry to ".basename(self::ALLOWLIST)." naming when this copy retires. Two lists is "
				."two answers to one question, and statutory dates are decided by whichever one the caller "
				."happened to reach."
		);
	}

	/**
	 * The allowlist only shrinks.
	 *
	 * An entry for a file that no longer holds a list fails, so the list
	 * cannot outlive its reasons.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/terms-on-the-engine-calendar/specs/termijnbewaking-schemas/spec.md
	 */
	public function testTheAllowlistHoldsNoFileThatHasStoppedHoldingOne(): void {
		$stale = array_values(array_diff($this->allowlisted(), $this->holders()));

		$this->assertSame(
			[],
			$stale,
			"These allowlisted files no longer hold a calendar; take them off the list:\n  "
				.implode("\n  ", $stale)
		);
	}

	/**
	 * Every entry says when the copy retires.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/terms-on-the-engine-calendar/specs/termijnbewaking-schemas/spec.md
	 */
	public function testEveryEntryNamesItsRetirement(): void {
		foreach (($this->allowlist()['entries'] ?? []) as $entry) {
			$file = (string)($entry['file'] ?? '');
			$this->assertNotSame('', $file, 'every entry names a file');
			$this->assertNotSame(
				'',
				trim((string)($entry['retiresWith'] ?? '')),
				sprintf("the entry for '%s' names the change that retires it", $file)
			);
			$this->assertNotSame(
				'',
				trim((string)($entry['reason'] ?? '')),
				sprintf("the entry for '%s' says why it is still here", $file)
			);
		}
	}

	/**
	 * A file that only NAMES the holidays is not a holder.
	 *
	 * The control, and the reason this test looks for the list rather than the
	 * words. `Kcc\SlaCalculator` lists Koningsdag and Bevrijdingsdag in its
	 * docblock and delegates every question to `WorkingDayCalculator`; a
	 * scanner matching names would report it and be wrong.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/terms-on-the-engine-calendar/specs/termijnbewaking-schemas/spec.md
	 */
	public function testAFileThatOnlyNamesHolidaysIsNotAHolder(): void {
		$source = (string)file_get_contents(self::ROOT.'/lib/Service/Kcc/SlaCalculator.php');
		$this->assertStringContainsString('Koningsdag', $source, 'the control file still names a holiday');

		$this->assertNotContains('lib/Service/Kcc/SlaCalculator.php', $this->holders());
	}

	/**
	 * And the file that really holds one is found.
	 *
	 * Without this the test above passes on a scanner that finds nothing at
	 * all, which is the emptiest kind of green.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/terms-on-the-engine-calendar/specs/termijnbewaking-schemas/spec.md
	 */
	public function testTheOneRealCalendarIsFound(): void {
		$this->assertContains('lib/Service/WorkingDayCalculator.php', $this->holders());
	}
}//end class
