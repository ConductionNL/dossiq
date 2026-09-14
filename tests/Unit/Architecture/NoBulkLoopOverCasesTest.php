<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Architecture
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * dossiq runs no bulk loop over cases.
 *
 * The rule this defends is the whole of D-1: a bulk act that iterates in
 * dossiq stops when the tab closes, reports nothing anybody can read
 * afterwards, and leaves four hundred statutory cases in an unknown state. The
 * loop belongs to OpenRegister's bulk job, which records what it did to each
 * case and can be cancelled, resumed and read.
 *
 * A deleted loop is easy to reintroduce, because writing one is the obvious
 * thing to do and nothing about it looks wrong in review: it is twelve lines
 * and it works on a selection of five. So the shape is scanned for rather than
 * remembered.
 *
 * The signal is narrow on purpose: a method under `lib/` that accepts a LIST OF
 * CASE IDS and walks it. That is exactly what the two deleted services did,
 * and it is what any replacement would have to do. Reading a list is legitimate
 * and stays legitimate, so a read carries an entry in {@see self::READERS} that
 * says what it reads and why the job cannot do it instead. Adding an entry is
 * the deliberate act; forgetting to is the failure.
 *
 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
 */
class NoBulkLoopOverCasesTest extends TestCase {

	/**
	 * The parameter names that mean "a list of cases to act on".
	 *
	 * @var array<int, string>
	 */
	private const CASE_LIST_PARAMETERS = ['caseIds', 'caseUuids', 'selectedIds', 'selectedCaseIds'];

	/**
	 * The methods that walk a list of case ids and are allowed to, each with
	 * the reason it is a read rather than an act.
	 *
	 * @var array<string, string>
	 */
	private const READERS = [
		'lib/Service/Bulk/CaseTypeVersionGuard.php::caseTypeIdsOf' =>
			'Reads each selected case type so the refusal can name both versions. '
			. 'It happens BEFORE any job exists, which is the point of the refusal, '
			. 'so there is no job to do it inside.',
	];

	/**
	 * Every method under lib/ that takes a list of case ids and walks it.
	 *
	 * @return array<string, string> Method key to the source of its body.
	 */
	private function loopsOverACaseList(): array {
		$found = [];
		$root = dirname(__DIR__, 3);

		$files = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($root . '/lib', RecursiveDirectoryIterator::SKIP_DOTS)
		);

		foreach ($files as $file) {
			if ($file->isFile() === false || $file->getExtension() !== 'php') {
				continue;
			}

			$relative = str_replace($root . '/', '', $file->getPathname());
			$source = (string)file_get_contents($file->getPathname());

			foreach ($this->methodsTakingACaseList(source: $source) as $method => $body) {
				if ($this->walksIt(body: $body) === true) {
					$found[$relative . '::' . $method] = $body;
				}
			}
		}

		return $found;
	}//end loopsOverACaseList()

	/**
	 * Split a file into the methods whose signature names a list of case ids.
	 *
	 * The body runs to the next method signature or the end of the file, which
	 * over-reads rather than under-reads: a loop in the method after this one
	 * is reported here too. That is the safe direction for a tripwire, and the
	 * message names the file either way.
	 *
	 * @param string $source The file's source.
	 *
	 * @return array<string, string> Method name to body.
	 */
	private function methodsTakingACaseList(string $source): array {
		$names = implode('|', self::CASE_LIST_PARAMETERS);
		$pattern = '/function\s+(\w+)\s*\([^)]*array\s+\$(?:' . $names . ')\b/s';

		if (preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE) === 0) {
			return [];
		}

		$bodies = [];
		$count = count($matches[0]);
		for ($index = 0; $index < $count; $index++) {
			$start = $matches[0][$index][1];
			$end = (($index + 1) < $count) ? $matches[0][($index + 1)][1] : strlen($source);
			$bodies[$matches[1][$index][0]] = substr($source, $start, ($end - $start));
		}

		return $bodies;
	}//end methodsTakingACaseList()

	/**
	 * Whether a method body walks the list it was handed.
	 *
	 * @param string $body The method body.
	 *
	 * @return bool True when it loops over the case list.
	 */
	private function walksIt(string $body): bool {
		$names = implode('|', self::CASE_LIST_PARAMETERS);

		return preg_match('/foreach\s*\(\s*\$(?:' . $names . ')\b/', $body) === 1;
	}//end walksIt()

	/**
	 * No method under lib/ walks a list of cases except the declared readers.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
	 */
	public function testNothingUnderLibWalksAListOfCasesExceptTheDeclaredReaders(): void {
		$unexpected = array_diff(array_keys($this->loopsOverACaseList()), array_keys(self::READERS));

		$this->assertSame(
			[],
			array_values($unexpected),
			"dossiq has grown a bulk loop over cases again.\n"
			. "A bulk act belongs to OpenRegister's job, which records what it did to each case,\n"
			. "can be cancelled and survives the tab closing. Declare an action instead\n"
			. "(see lib/BulkAction/), or, if this really is a read, add it to\n"
			. "NoBulkLoopOverCasesTest::READERS with the reason.\n"
			. 'Found: ' . implode(', ', $unexpected)
		);
	}//end testNothingUnderLibWalksAListOfCasesExceptTheDeclaredReaders()

	/**
	 * Every declared reader still exists.
	 *
	 * An allowlist that outlives what it allows is how a tripwire quietly
	 * stops covering anything: the entries stay, the loops move, and the test
	 * passes on a list of files that are no longer there.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
	 */
	public function testEveryDeclaredReaderStillWalksAListOfCases(): void {
		$actual = array_keys($this->loopsOverACaseList());

		foreach (array_keys(self::READERS) as $reader) {
			$this->assertContains($reader, $actual, $reader . ' is allowlisted but no longer walks a case list');
		}
	}//end testEveryDeclaredReaderStillWalksAListOfCases()

	/**
	 * The two services that held the loops are gone, not merely unrouted.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
	 */
	public function testTheTwoLoopingServicesAreGone(): void {
		$root = dirname(__DIR__, 3);

		$this->assertFileDoesNotExist($root . '/lib/Service/BulkStatusTransitionService.php');
		$this->assertFileDoesNotExist($root . '/lib/Service/SelectionReassignmentService.php');
	}//end testTheTwoLoopingServicesAreGone()
}//end class
