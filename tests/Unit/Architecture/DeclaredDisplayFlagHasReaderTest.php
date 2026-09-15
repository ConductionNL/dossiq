<?php

/**
 * Structural guard: every declared display flag is honoured somewhere.
 *
 * `statusType.hiddenInLists` is why this exists. It shipped as a checkbox on
 * the status type form, with a description promising that cases in that status
 * stay out of the working list, and for a while nothing in dossiq read it. It
 * then gained exactly one reader, under a different name, on one lens of one
 * page, which is the state this change found it in.
 *
 * Neither half of that is visible to a person reading the schema. A flag with
 * no reader and a flag with seven read identically in JSON, and the failure is
 * silent in the worst way: the administrator ticks the box, nothing happens,
 * and there is nothing to report because nothing errored.
 *
 * 🔑 THE TEST FOLLOWS THE DECLARED CALCULATION RATHER THAN THE NAME, and the
 * reason is recorded in the change's own proposal as a mistake it made first.
 * A grep for `hiddenInLists` says dark. The flag is not dark; the case mirrors
 * it as the calculated `statusHiddenInLists`, and that is the name every
 * reader uses. A scanner matching names would have opened a defect against a
 * control that works.
 *
 * It fails in BOTH directions. A flag with no reader and no allowlist entry
 * fails, which is the point. An allowlisted flag that has since gained a
 * reader also fails, so the allowlist can only shrink deliberately rather than
 * quietly outliving the reason it was written.
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
 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-status-machinery/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Every display flag a dossiq schema declares is read, or allowlisted.
 *
 * @covers \OCA\Dossiq\Tests\Unit\Architecture\DisplayFlagReaderScanner
 */
class DeclaredDisplayFlagHasReaderTest extends TestCase {

	/**
	 * The repository root.
	 *
	 * @var string
	 */
	private const ROOT = __DIR__.'/../../..';

	/**
	 * The allowlist of flags nothing honours yet.
	 *
	 * @var string
	 */
	private const ALLOWLIST = __DIR__.'/declared-display-flag.allowlist.json';

	/**
	 * The fixture register, whose flags are known by construction.
	 *
	 * @var string
	 */
	private const FIXTURES = __DIR__.'/fixtures/displayFlags';

	/**
	 * The scanner pointed at the real register.
	 *
	 * @return DisplayFlagReaderScanner The scanner under test.
	 */
	private function scanner(): DisplayFlagReaderScanner {
		return new DisplayFlagReaderScanner(
			registerFile: self::ROOT.'/lib/Settings/dossiq_register.json',
			sourceDirs: [self::ROOT.'/lib', self::ROOT.'/src'],
		);
	}//end scanner()

	/**
	 * The allowlist, as written.
	 *
	 * @return array<string, mixed> The decoded allowlist.
	 */
	private function allowlist(): array {
		$decoded = json_decode((string)file_get_contents(self::ALLOWLIST), true);
		$this->assertIsArray(actual: $decoded, message: 'the allowlist must be readable JSON');

		return $decoded;
	}//end allowlist()

	/**
	 * Every declared display flag has a reader, or a reason-bearing entry.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-status-machinery/spec.md
	 */
	public function testEveryDeclaredDisplayFlagIsHonoured(): void {
		$scanner = $this->scanner();
		$allowed = array_column((array)($this->allowlist()['entries'] ?? []), 'flag');

		$dark = [];
		foreach ($scanner->declaredFlags() as $flag) {
			if ($scanner->readersOf(flag: $flag) !== []) {
				continue;
			}

			if (in_array($flag, $allowed, true) === true) {
				continue;
			}

			$dark[] = $flag;
		}

		$this->assertSame(
			expected: [],
			actual: $dark,
			message: 'A display flag nobody reads is a control that silently does nothing. '
				.'Give it a reader, or an entry in '
				.'tests/Unit/Architecture/declared-display-flag.allowlist.json naming the '
				.'change that will honour it.'
		);
	}//end testEveryDeclaredDisplayFlagIsHonoured()

	/**
	 * An allowlisted flag that has gained a reader fails.
	 *
	 * Without this half the list only ever grows: a flag gets honoured, the
	 * entry stays, and the next reader of the file is told a working control
	 * is dark.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-status-machinery/spec.md
	 */
	public function testNoAllowlistEntrySurvivesItsReader(): void {
		$scanner = $this->scanner();

		$stale = [];
		foreach ((array)($this->allowlist()['entries'] ?? []) as $entry) {
			$flag = (string)($entry['flag'] ?? '');
			if ($flag === '' || $scanner->readersOf(flag: $flag) === []) {
				continue;
			}

			$stale[] = $flag;
		}

		$this->assertSame(
			expected: [],
			actual: $stale,
			message: 'These flags are allowlisted as unread and now have readers. Remove their entries.'
		);
	}//end testNoAllowlistEntrySurvivesItsReader()

	/**
	 * Every allowlist entry names a flag, a change and a reason.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-status-machinery/spec.md
	 */
	public function testEveryAllowlistEntryNamesItsOwner(): void {
		$declared = $this->scanner()->declaredFlags();

		foreach ((array)($this->allowlist()['entries'] ?? []) as $index => $entry) {
			foreach (['flag', 'change', 'reason'] as $key) {
				$this->assertNotSame(
					expected: '',
					actual: trim((string)($entry[$key] ?? '')),
					message: sprintf('allowlist entry %d must name its %s', $index, $key)
				);
			}

			$this->assertContains(
				needle: (string)$entry['flag'],
				haystack: $declared,
				message: sprintf(
					'allowlist entry %d names "%s", which no schema declares. An entry for a flag that '
						.'does not exist can never be removed by fixing anything.',
					$index,
					(string)$entry['flag']
				)
			);
		}
	}//end testEveryAllowlistEntryNamesItsOwner()

	/**
	 * The two flags dossiq declares today are the two it is meant to declare.
	 *
	 * A structural test over an empty set reports the same green as one over a
	 * full set, and the scan finding nothing is exactly how this guard would
	 * fail silently: a renamed vocabulary, a moved register, a changed schema
	 * shape. Naming the flags is the control that separates "nothing is dark"
	 * from "nothing was looked at".
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-status-machinery/spec.md
	 */
	public function testTheScanSeesTheFlagsThatAreThere(): void {
		$this->assertSame(
			expected: ['case.statusHiddenInLists', 'statusType.hiddenInLists'],
			actual: $this->scanner()->declaredFlags(),
			message: 'the display-flag scan must still find dossiq\'s declared flags'
		);
	}//end testTheScanSeesTheFlagsThatAreThere()

	/**
	 * The flag the settings form authors is honoured through its mirror only.
	 *
	 * This is the D-4 scenario as live data rather than as a fixture.
	 * `statusType.hiddenInLists` has no direct reader outside the form that
	 * writes it, and it passes, because the case declares
	 * `statusHiddenInLists` as calculated from it and every list filters that.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-status-machinery/spec.md
	 */
	public function testTheStatusFlagIsReadThroughItsCalculatedMirror(): void {
		$scanner = $this->scanner();

		$this->assertContains(
			needle: 'statusHiddenInLists',
			haystack: $scanner->namesFor(flag: 'statusType.hiddenInLists'),
			message: 'the mirror must be derived from the register\'s own calculation declaration'
		);

		$readers = $scanner->readersOf(flag: 'statusType.hiddenInLists');
		$this->assertNotSame(expected: [], actual: $readers, message: 'the mirrored flag must resolve to readers');

		$named = implode(' ', $readers);
		$this->assertStringContainsString(needle: 'manifest.json', haystack: $named);
		$this->assertStringContainsString(needle: 'KpiAggregationService.php', haystack: $named);
	}//end testTheStatusFlagIsReadThroughItsCalculatedMirror()

	/**
	 * A flag nothing reads is found, on a register built to contain one.
	 *
	 * The three fixture assertions are what make the real scan above mean
	 * something. Run only against dossiq, the guard is green whether it works
	 * or not, because dossiq currently has no dark flag: a scanner with a
	 * broken vocabulary, a wrong root or an unreadable register would report
	 * exactly the same empty list.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-status-machinery/spec.md
	 */
	public function testAControlNobodyReadsIsFound(): void {
		$scanner = new DisplayFlagReaderScanner(
			registerFile: self::FIXTURES.'/register.json',
			sourceDirs: [self::FIXTURES.'/readers'],
		);

		$this->assertSame(
			expected: ['gadget.hiddenSomewhere', 'gadget.showOnPoster', 'widget.hostHiddenHere'],
			actual: $scanner->declaredFlags(),
			message: 'the vocabulary must pick the display booleans and leave the others alone'
		);

		// Nothing in the fixture reader mentions `showOnPoster` by any name.
		$this->assertSame(
			expected: [],
			actual: $scanner->readersOf(flag: 'gadget.showOnPoster'),
			message: 'a flag nobody reads must come back with no readers'
		);
	}//end testAControlNobodyReadsIsFound()

	/**
	 * A flag read only through its calculated mirror passes, on the fixture.
	 *
	 * The fixture reader names `hostHiddenHere` and never `hiddenSomewhere`,
	 * which is the shape that fooled the first read of this candidate.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-status-machinery/spec.md
	 */
	public function testAMirroredFlagPassesOnTheFixture(): void {
		$scanner = new DisplayFlagReaderScanner(
			registerFile: self::FIXTURES.'/register.json',
			sourceDirs: [self::FIXTURES.'/readers'],
		);

		$body = (string)file_get_contents(self::FIXTURES.'/readers/CatalogueQuery.php');
		$this->assertStringNotContainsString(
			needle: 'hiddenSomewhere',
			haystack: preg_replace('/^\s*\/\/.*$/m', '', $body) ?? '',
			message: 'the fixture only proves the point while its reader never names the flag'
		);

		$this->assertNotSame(
			expected: [],
			actual: $scanner->readersOf(flag: 'gadget.hiddenSomewhere'),
			message: 'a flag whose calculated mirror is read has a reader'
		);
	}//end testAMirroredFlagPassesOnTheFixture()
}//end class
