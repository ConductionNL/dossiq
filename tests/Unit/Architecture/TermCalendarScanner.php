<?php

/**
 * Scanner for statutory term paths that compute a date without the calendar.
 *
 * Reads the verdict column of
 * `docs/research/date-arithmetic-audit-2026-09-14.md` and, for every file the
 * audit calls a statutory term path, checks that the file actually reaches a
 * working calendar: the engine bridge `TermijnTimerService::rollTermEnd()` or
 * `rollTermEndFor()`, or `WorkingDayCalculator`, which is that bridge's
 * documented fallback. A file that reaches neither is reported with the line
 * of its first date arithmetic, because "somewhere in this file" is not a
 * finding anyone can act on.
 *
 * Two properties matter here:
 *
 *  1. The audit is the input, not a second list maintained beside it. A row
 *     whose verdict changes changes what the build enforces, which is what
 *     turns the audit from a document into a fact.
 *  2. A file that does date arithmetic and appears in NO row fails as well.
 *     Otherwise a new statutory path could pass by being absent, which is how
 *     thirty-two files reached this repository unexamined.
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
 * @spec openspec/changes/every-term-on-the-engine-calendar/specs/termijnbewaking-schemas/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Architecture;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * Reads the audit, finds the statutory paths that skip the calendar.
 *
 * @spec openspec/changes/every-term-on-the-engine-calendar/specs/termijnbewaking-schemas/spec.md
 */
final class TermCalendarScanner {
	/**
	 * The date-arithmetic patterns the audit was built from. Kept here as ONE
	 * string so the scanner and the audit cannot drift apart silently.
	 *
	 * @var string
	 */
	public const ARITHMETIC_PATTERN = "/modify\('[+-]|new DateInterval|->add\(|->sub\(|strtotime\('[+-]/";

	/**
	 * What counts as reaching a working calendar.
	 *
	 * @var array<int, string>
	 */
	public const CALENDAR_MARKERS = ['rollTermEndFor', 'rollTermEnd', 'WorkingDayCalculator'];

	/**
	 * The verdict a row must carry to be enforced.
	 *
	 * @var string
	 */
	public const VERDICT_STATUTORY = 'statutory';

	/**
	 * The verdicts a row may carry.
	 *
	 * @var array<int, string>
	 */
	public const VERDICTS = ['statutory', 'business', 'neither'];

	/**
	 * Read the audit's table into `file => verdict`.
	 *
	 * @param string $auditPath The audit markdown file.
	 *
	 * @return array<string, string> Verdict per file path, relative to the app root.
	 *
	 * @throws RuntimeException When the audit is missing or carries an unknown verdict.
	 */
	public static function verdicts(string $auditPath): array {
		$markdown = file_get_contents($auditPath);
		if ($markdown === false) {
			throw new RuntimeException('The date-arithmetic audit is missing: ' . $auditPath);
		}

		$verdicts = [];
		foreach (explode("\n", $markdown) as $line) {
			$matched = [];
			if (preg_match('/^\|\s*`(lib\/[^`]+\.php)`\s*\|([^|]*)\|([^|]*)\|/', $line, $matched) !== 1) {
				continue;
			}

			$verdict = trim($matched[3]);
			if (in_array($verdict, self::VERDICTS, true) === false) {
				throw new RuntimeException(
					sprintf("The audit row for %s carries the unknown verdict '%s'.", $matched[1], $verdict)
				);
			}

			$verdicts[$matched[1]] = $verdict;
		}

		return $verdicts;
	}

	/**
	 * Every file under a root that does date arithmetic, with the line of its
	 * first occurrence.
	 *
	 * @param string $root The directory to walk (the app's `lib/`, or a fixture).
	 * @param string $prefix What to prefix each reported path with.
	 *
	 * @return array<string, int> `path => line`.
	 */
	public static function arithmeticSites(string $root, string $prefix): array {
		$sites = [];
		$paths = [];
		$walk = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
		);
		foreach ($walk as $entry) {
			if ($entry->isFile() === true && $entry->getExtension() === 'php') {
				$paths[] = $entry->getPathname();
			}
		}

		sort($paths);
		foreach ($paths as $path) {
			$source = file_get_contents($path);
			if ($source === false) {
				continue;
			}

			$line = self::firstArithmeticLine(source: $source);
			if ($line === null) {
				continue;
			}

			$sites[$prefix . substr($path, (strlen($root) + 1))] = $line;
		}

		return $sites;
	}

	/**
	 * The statutory paths that reach no calendar.
	 *
	 * @param array<string, int> $sites `path => line` from {@see arithmeticSites()}.
	 * @param array<string, string> $verdicts `path => verdict` from {@see verdicts()}.
	 * @param string $root The directory the paths live under.
	 * @param string $prefix The prefix the paths carry.
	 *
	 * @return array<int, string> One finding per offending file, naming the line.
	 */
	public static function offenders(array $sites, array $verdicts, string $root, string $prefix): array {
		$offenders = [];
		foreach ($sites as $path => $line) {
			if (array_key_exists($path, $verdicts) === false) {
				$offenders[] = sprintf(
					'%s:%d does date arithmetic and carries no row in the audit. Add one with a verdict.',
					$path,
					$line
				);
				continue;
			}

			if ($verdicts[$path] !== self::VERDICT_STATUTORY) {
				continue;
			}

			$source = file_get_contents($root . '/' . substr($path, strlen($prefix)));
			if ($source === false || self::reachesCalendar(source: $source) === true) {
				continue;
			}

			$offenders[] = sprintf(
				'%s:%d computes a statutory term without reaching a working calendar.',
				$path,
				$line
			);
		}

		return $offenders;
	}

	/**
	 * Whether a file reaches a working calendar at all.
	 *
	 * @param string $source The file's source.
	 *
	 * @return bool True when it names the bridge or the fallback calculator.
	 */
	public static function reachesCalendar(string $source): bool {
		$code = self::withoutCommentsAndStrings(source: $source);
		foreach (self::CALENDAR_MARKERS as $marker) {
			if (str_contains($code, $marker) === true) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The line of the first date arithmetic in a file, ignoring comments and
	 * string literals so a docblock describing the pattern is not a finding.
	 *
	 * @param string $source The file's source.
	 *
	 * @return int|null The 1-based line, or null when the file does none.
	 */
	private static function firstArithmeticLine(string $source): ?int {
		$code = self::withoutCommentsAndStrings(source: $source);
		$lines = explode("\n", $code);
		foreach ($lines as $index => $line) {
			if (preg_match(self::ARITHMETIC_PATTERN, $line) === 1) {
				return ($index + 1);
			}
		}

		return null;
	}

	/**
	 * A copy of the source with comments and string bodies blanked, keeping
	 * the line count intact so reported lines still point at real code.
	 *
	 * @param string $source The file's source.
	 *
	 * @return string The masked copy.
	 */
	private static function withoutCommentsAndStrings(string $source): string {
		$masked = '';
		foreach (token_get_all($source) as $token) {
			if (is_array($token) === false) {
				$masked .= $token;
				continue;
			}

			$blank = in_array(
				$token[0],
				[T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML, T_ENCAPSED_AND_WHITESPACE],
				true
			);
			if ($blank === true) {
				$masked .= preg_replace('/[^\n]/', ' ', $token[1]);
				continue;
			}

			$masked .= $token[1];
		}

		return $masked;
	}
}//end class
