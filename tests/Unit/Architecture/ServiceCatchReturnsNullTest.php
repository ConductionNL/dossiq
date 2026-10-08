<?php

/**
 * Structural guard: a catch that answers nothing is written down, with a reason.
 *
 * A rule that refuses and a store that fails leave a service the same way:
 * `catch (\Throwable) { return null; }`. The caller sees one empty value and
 * writes the sentence the user finally reads, so "the mandate register could
 * not be queried" arrives as "you are not authorised". Nothing in the tree
 * could tell the two apart, and nothing counted how often it happened.
 *
 * This test is the count. Every such site under lib/Service must appear in
 * `catch-return-null.allowlist.json` with a class and a reason; the ceiling
 * only goes down; and no entry may be classed `refusal`, because a refusal is
 * converted to a RefusedException rather than allowlisted (REQ-QG-CRN-2).
 *
 * The scanner it uses is itself under test here, against two fixtures: one
 * that swallows and must be found, one that rethrows, returns a value,
 * returns null only under a condition, answers late, and quotes the pattern
 * in a docblock and a string — and must not be found. An instrument that
 * cannot be shown wrong is not an instrument.
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
 * @spec openspec/changes/refusals-carry-a-status/specs/quality-gates/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Counts and ratchets the catch-and-return-nothing sites (REQ-QG-CRN-1).
 *
 * @covers \OCA\Dossiq\Tests\Unit\Architecture\CatchReturnNullScanner
 */
class ServiceCatchReturnsNullTest extends TestCase {
	/**
	 * The app root, which reported paths are relative to.
	 *
	 * @var string
	 */
	private const ROOT = __DIR__ . '/../../..';

	/**
	 * The directory under measurement.
	 *
	 * @var string
	 */
	private const SERVICE_DIR = self::ROOT . '/lib/Service';

	/**
	 * The allowlist document.
	 *
	 * @var string
	 */
	private const ALLOWLIST = __DIR__ . '/catch-return-null.allowlist.json';

	/**
	 * Fixtures the scanner is measured against.
	 *
	 * @var string
	 */
	private const FIXTURES = __DIR__ . '/fixtures/';

	/**
	 * Every live site is written down, with the file and method named.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/refusals-carry-a-status/specs/quality-gates/spec.md
	 */
	public function testEverySwallowingCatchIsAllowlisted(): void {
		$result = CatchReturnNullScanner::compare(
			sites: $this->liveSites(),
			allowed: $this->allowlist()
		);

		self::assertSame(
			expected: [],
			actual: $result['unlisted'],
			message: "These catch blocks answer null or [] and are not in catch-return-null.allowlist.json:\n"
			. implode("\n", $result['unlisted'])
			. "\n\nIf a rule refused, throw OCA\\Dossiq\\Exception\\RefusedException and let the "
			. 'controller translate it. If a collaborator was absent, log at warning naming what '
			. 'was absent and add an entry classed degradation. If it is a read miss, add an entry '
			. 'classed read-miss. Every entry carries a reason.'
		);
	}//end testEverySwallowingCatchIsAllowlisted()

	/**
	 * No entry outlives the site it describes.
	 *
	 * A list that keeps entries for code that is gone stops being read, and
	 * then the ceiling stops meaning anything.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/refusals-carry-a-status/specs/quality-gates/spec.md
	 */
	public function testEveryAllowlistEntryNamesALiveSite(): void {
		$result = CatchReturnNullScanner::compare(
			sites: $this->liveSites(),
			allowed: $this->allowlist()
		);

		self::assertSame(
			expected: [],
			actual: $result['stale'],
			message: "These allowlist entries name no live site; remove them and lower the ceiling:\n"
			. implode("\n", $result['stale'])
		);
	}//end testEveryAllowlistEntryNamesALiveSite()

	/**
	 * The ceiling is the count, and it only goes down.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/refusals-carry-a-status/specs/quality-gates/spec.md
	 */
	public function testTheCeilingOnlyGoesDown(): void {
		$allowlist = $this->allowlist();
		$live = count($this->liveSites());

		self::assertLessThanOrEqual(
			expected: (int)$allowlist['ceiling'],
			actual: $live,
			message: sprintf(
				'%d swallowing catches against a ceiling of %d. The ceiling only goes down: '
				. 'convert a site or classify it, do not raise the number.',
				$live,
				(int)$allowlist['ceiling']
			)
		);

		self::assertSame(
			expected: count((array)$allowlist['sites']),
			actual: (int)$allowlist['ceiling'],
			message: 'The ceiling must equal the number of entries, so it cannot be padded with headroom.'
		);
	}//end testTheCeilingOnlyGoesDown()

	/**
	 * No site is allowlisted as a refusal: a refusal is converted (REQ-QG-CRN-2).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/refusals-carry-a-status/specs/quality-gates/spec.md
	 */
	public function testNoRefusalIsAllowlisted(): void {
		$refusals = [];
		foreach ((array)$this->allowlist()['sites'] as $entry) {
			if ((string)$entry['class'] === 'refusal') {
				$refusals[] = CatchReturnNullScanner::key(entry: (array)$entry);
			}
		}

		self::assertSame(
			expected: [],
			actual: $refusals,
			message: "A site where a rule said no is converted, not allowlisted (REQ-QG-CRN-2):\n"
			. implode("\n", $refusals)
		);
	}//end testNoRefusalIsAllowlisted()

	/**
	 * Every entry carries a known class and a reason that names its method.
	 *
	 * A reason that does not name the method is boilerplate, and boilerplate
	 * is how a debt list stops being read.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/refusals-carry-a-status/specs/quality-gates/spec.md
	 */
	public function testEveryEntryCarriesAClassAndAReason(): void {
		foreach ((array)$this->allowlist()['sites'] as $entry) {
			$key = CatchReturnNullScanner::key(entry: (array)$entry);

			self::assertContains(
				needle: (string)$entry['class'],
				haystack: CatchReturnNullScanner::CLASSES,
				message: sprintf('Entry "%s" declares an unknown class "%s".', $key, (string)$entry['class'])
			);

			self::assertStringContainsString(
				needle: (string)$entry['method'],
				haystack: (string)$entry['reason'],
				message: sprintf('Entry "%s" has a reason that does not name its own method.', $key)
			);
		}//end foreach
	}//end testEveryEntryCarriesAClassAndAReason()

	/**
	 * A new swallowing catch is found, by file and method.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/refusals-carry-a-status/specs/quality-gates/spec.md
	 */
	public function testANewSwallowingCatchFailsTheBuild(): void {
		$sites = CatchReturnNullScanner::scanFile(
			path: self::FIXTURES . 'SwallowingService.php.txt',
			root: (string)realpath(self::ROOT)
		);

		self::assertCount(expectedCount: 2, haystack: $sites, message: 'Both fixture catches must be found.');
		self::assertSame(expected: 'findThing', actual: $sites[0]['method']);
		self::assertSame(expected: 'return null;', actual: $sites[0]['returns']);
		self::assertSame(expected: 'listThings', actual: $sites[1]['method']);
		self::assertSame(expected: 'return [];', actual: $sites[1]['returns']);

		$result = CatchReturnNullScanner::compare(sites: $sites, allowed: ['ceiling' => 2, 'sites' => []]);
		self::assertCount(expectedCount: 2, haystack: $result['unlisted'], message: 'An unlisted site must be reported.');
		self::assertStringContainsString(needle: 'SwallowingService', haystack: $result['unlisted'][0]);
		self::assertStringContainsString(needle: 'findThing', haystack: $result['unlisted'][0]);
	}//end testANewSwallowingCatchFailsTheBuild()

	/**
	 * The shapes that are not this one stay non-findings.
	 *
	 * Each was a false positive somewhere: a docblock describing the pattern,
	 * a string quoting it, a rethrow, a catch that answers a value, a return
	 * guarded by a condition, and an answer four statements late.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/refusals-carry-a-status/specs/quality-gates/spec.md
	 */
	public function testTheOtherShapesAreNotFindings(): void {
		$sites = CatchReturnNullScanner::scanFile(
			path: self::FIXTURES . 'HonestService.php.txt',
			root: (string)realpath(self::ROOT)
		);

		self::assertSame(expected: [], actual: $sites, message: 'None of these five shapes is a swallowing catch.');
	}//end testTheOtherShapesAreNotFindings()

	/**
	 * One more site than the ceiling allows fails.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/refusals-carry-a-status/specs/quality-gates/spec.md
	 */
	public function testExceedingTheCeilingFails(): void {
		$sites = CatchReturnNullScanner::scanFile(
			path: self::FIXTURES . 'SwallowingService.php.txt',
			root: (string)realpath(self::ROOT)
		);

		$entries = [];
		foreach ($sites as $site) {
			$entries[] = [
				'file' => $site['file'],
				'method' => $site['method'],
				'occurrence' => $site['occurrence'],
				'class' => 'read-miss',
				'reason' => 'fixture',
			];
		}

		$underCeiling = CatchReturnNullScanner::compare(
			sites: $sites,
			allowed: ['ceiling' => 2, 'sites' => $entries]
		);
		self::assertSame(expected: [], actual: $underCeiling['ceiling'], message: 'Two sites under a ceiling of two must pass.');

		$overCeiling = CatchReturnNullScanner::compare(
			sites: $sites,
			allowed: ['ceiling' => 1, 'sites' => $entries]
		);
		self::assertCount(expectedCount: 1, haystack: $overCeiling['ceiling'], message: 'Two sites against a ceiling of one must fail.');
		self::assertStringContainsString(needle: '2 sites against a ceiling of 1', haystack: $overCeiling['ceiling'][0]);
	}//end testExceedingTheCeilingFails()

	/**
	 * The live sites under lib/Service.
	 *
	 * @return array<int, array{file: string, method: string, line: int, occurrence: int, returns: string}>
	 */
	private function liveSites(): array {
		return CatchReturnNullScanner::scanDirectory(
			dir: (string)realpath(self::SERVICE_DIR),
			root: (string)realpath(self::ROOT)
		);
	}//end liveSites()

	/**
	 * The decoded allowlist document.
	 *
	 * @return array<string, mixed>
	 */
	private function allowlist(): array {
		$decoded = json_decode((string)file_get_contents(self::ALLOWLIST), true);
		self::assertIsArray(actual: $decoded, message: 'catch-return-null.allowlist.json must be a JSON object.');

		return $decoded;
	}//end allowlist()
}//end class
