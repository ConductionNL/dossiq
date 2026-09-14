<?php

/**
 * A statutory term path that skips the working calendar fails the build.
 *
 * The date-arithmetic audit is the input: a file the audit calls a statutory
 * term must reach the engine bridge or its documented fallback, and a file
 * that does date arithmetic and carries no row at all fails too. That is what
 * keeps the audit a fact rather than a document somebody wrote once.
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

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Tests\Unit\Architecture\TermCalendarScanner
 */
class EveryTermOnTheCalendarTest extends TestCase {
	private string $root;
	private string $auditPath;
	private string $allowlistPath;

	protected function setUp(): void {
		$this->root = dirname(__DIR__, 3);
		$this->auditPath = $this->root . '/docs/research/date-arithmetic-audit-2026-09-14.md';
		$this->allowlistPath = __DIR__ . '/every-term-on-the-calendar.allowlist.json';
	}

	/**
	 * The allowlist, decoded.
	 *
	 * @return array<string, mixed> The allowlist.
	 */
	private function allowlist(): array {
		$raw = file_get_contents($this->allowlistPath);
		self::assertIsString($raw, 'The allowlist is missing: ' . $this->allowlistPath);

		return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
	}

	/**
	 * The offenders in the real tree.
	 *
	 * @return array<int, string> One finding per offending file.
	 */
	private function offenders(): array {
		$verdicts = TermCalendarScanner::verdicts(auditPath: $this->auditPath);
		$sites = TermCalendarScanner::arithmeticSites(root: $this->root . '/lib', prefix: 'lib/');

		return TermCalendarScanner::offenders(
			sites: $sites,
			verdicts: $verdicts,
			root: $this->root . '/lib',
			prefix: 'lib/'
		);
	}

	/**
	 * Every statutory term path reaches a working calendar, unless the
	 * allowlist names the change that will take it.
	 *
	 * @return void
	 */
	public function testEveryStatutoryPathReachesTheCalendarOrIsAllowlisted(): void {
		$allowed = array_column($this->allowlist()['entries'], 'file');

		$unexplained = [];
		foreach ($this->offenders() as $finding) {
			$file = strstr($finding, ':', true);
			if (in_array($file, $allowed, true) === true) {
				continue;
			}

			$unexplained[] = $finding;
		}

		self::assertSame(
			[],
			$unexplained,
			"These statutory term paths compute a date without reaching a working calendar:\n"
			. implode("\n", $unexplained)
			. "\n\nRoute the date through TermijnTimerService::rollTermEndFor(), or add an "
			. "allowlist entry naming the change that will."
		);
	}

	/**
	 * Every allowlist entry carries a reason and the change that will take
	 * the file. An entry without a named change is not an entry.
	 *
	 * @return void
	 */
	public function testEveryAllowlistEntryNamesAChangeAndAReason(): void {
		foreach ($this->allowlist()['entries'] as $entry) {
			self::assertNotEmpty($entry['file'] ?? '', 'An allowlist entry names no file.');
			self::assertNotEmpty(
				trim((string)($entry['change'] ?? '')),
				$entry['file'] . ' is allowlisted with no change named. An entry without an owner is not an entry.'
			);
			self::assertTrue(
				is_dir($this->root . '/openspec/changes/' . $entry['change']),
				sprintf(
					'%s names the change %s, which is not an open change under openspec/changes/.',
					$entry['file'],
					$entry['change']
				)
			);
			self::assertGreaterThan(
				40,
				strlen(trim((string)($entry['reason'] ?? ''))),
				$entry['file'] . ' is allowlisted with no reason a reader could check.'
			);
		}
	}

	/**
	 * An entry that names no live offender is stale: the file was fixed and
	 * nobody removed the exemption, so the allowlist stops meaning anything.
	 *
	 * @return void
	 */
	public function testNoAllowlistEntryIsStale(): void {
		$offending = array_map(
			static fn (string $finding): string => (string)strstr($finding, ':', true),
			$this->offenders()
		);

		foreach ($this->allowlist()['entries'] as $entry) {
			self::assertContains(
				$entry['file'],
				$offending,
				$entry['file'] . ' is allowlisted but no longer offends. Remove the entry.'
			);
		}
	}

	/**
	 * A new statutory path that computes a beslistermijn with `modify('+N
	 * days')` fails, naming the file and the line.
	 *
	 * @return void
	 */
	public function testANewStatutoryPathWithoutTheCalendarFails(): void {
		$root = $this->fixtureTree(['SkippingTermService' => 'SkippingTermService.php.txt']);

		$offenders = TermCalendarScanner::offenders(
			sites: TermCalendarScanner::arithmeticSites(root: $root, prefix: 'lib/'),
			verdicts: ['lib/SkippingTermService.php' => 'statutory'],
			root: $root,
			prefix: 'lib/'
		);

		self::assertSame(
			['lib/SkippingTermService.php:14 computes a statutory term without reaching a working calendar.'],
			$offenders
		);
	}

	/**
	 * The same path routed through the bridge passes, so the test above
	 * reddens on the calendar and not on the arithmetic.
	 *
	 * @return void
	 */
	public function testTheSamePathReachingTheBridgePasses(): void {
		$root = $this->fixtureTree(['RollingTermService' => 'RollingTermService.php.txt']);

		$offenders = TermCalendarScanner::offenders(
			sites: TermCalendarScanner::arithmeticSites(root: $root, prefix: 'lib/'),
			verdicts: ['lib/RollingTermService.php' => 'statutory'],
			root: $root,
			prefix: 'lib/'
		);

		self::assertSame([], $offenders);
	}

	/**
	 * A file that does date arithmetic and carries no audit row fails: a new
	 * statutory path cannot pass by being absent from the table.
	 *
	 * @return void
	 */
	public function testAFileWithNoAuditRowFails(): void {
		$root = $this->fixtureTree(['SkippingTermService' => 'SkippingTermService.php.txt']);

		$offenders = TermCalendarScanner::offenders(
			sites: TermCalendarScanner::arithmeticSites(root: $root, prefix: 'lib/'),
			verdicts: [],
			root: $root,
			prefix: 'lib/'
		);

		self::assertSame(
			['lib/SkippingTermService.php:14 does date arithmetic and carries no row in the audit. Add one with a verdict.'],
			$offenders
		);
	}

	/**
	 * A file the audit clears is skipped, and its verdict is the reason.
	 *
	 * @return void
	 */
	public function testAFileTheAuditClearsIsSkipped(): void {
		$root = $this->fixtureTree(['SkippingTermService' => 'SkippingTermService.php.txt']);

		$offenders = TermCalendarScanner::offenders(
			sites: TermCalendarScanner::arithmeticSites(root: $root, prefix: 'lib/'),
			verdicts: ['lib/SkippingTermService.php' => 'neither'],
			root: $root,
			prefix: 'lib/'
		);

		self::assertSame([], $offenders);
	}

	/**
	 * A verdict the audit does not define is refused rather than silently
	 * treated as cleared.
	 *
	 * @return void
	 */
	public function testAnUnknownVerdictInTheAuditIsRefused(): void {
		$path = tempnam(sys_get_temp_dir(), 'audit') . '.md';
		file_put_contents($path, "| `lib/Service/X.php` | 10 | probably fine | | because |\n");

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('unknown verdict');

		try {
			TermCalendarScanner::verdicts(auditPath: $path);
		} finally {
			unlink($path);
		}
	}

	/**
	 * The audit parses, covers the real tree, and every verdict is one of the
	 * three the audit defines.
	 *
	 * @return void
	 */
	public function testTheAuditCoversEveryFileThatDoesDateArithmetic(): void {
		$verdicts = TermCalendarScanner::verdicts(auditPath: $this->auditPath);
		$sites = TermCalendarScanner::arithmeticSites(root: $this->root . '/lib', prefix: 'lib/');

		self::assertNotSame([], $verdicts, 'The audit table parsed to nothing.');
		foreach (array_keys($sites) as $path) {
			self::assertArrayHasKey(
				$path,
				$verdicts,
				$path . ' does date arithmetic and is not in the audit. Add a row with a verdict.'
			);
		}
	}

	/**
	 * Copy fixtures into a scratch tree the scanner can walk.
	 *
	 * @param array<string, string> $files Class name => fixture file name.
	 *
	 * @return string The scratch directory.
	 */
	private function fixtureTree(array $files): string {
		$root = sys_get_temp_dir() . '/dossiq-term-calendar-' . bin2hex(random_bytes(6));
		mkdir($root, 0o777, true);
		foreach ($files as $class => $fixture) {
			copy(__DIR__ . '/fixtures/' . $fixture, $root . '/' . $class . '.php');
		}

		return $root;
	}
}
