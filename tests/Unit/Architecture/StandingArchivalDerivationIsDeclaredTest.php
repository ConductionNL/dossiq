<?php

/**
 * Structural guard: the two classes that still derive a case's archival future
 * say so, and nothing else joins them.
 *
 * Decision D7 puts the archiving process in openregister. Two dossiq classes
 * have not moved, and the reason is measured rather than preferred:
 * openregister nominates an object when it reaches a state its schema declares
 * in `x-openregister-lifecycle.final`, and a case's status is a `statusType`
 * uuid that no static enum list can hold. Removing the two before that is
 * answered would leave every closing case with no archiefactiedatum at all.
 *
 * A reason that lives only in a pull request body stops being readable the week
 * after it merges, and the next person deletes the class or writes a third one.
 * So the reason lives in the files, and this test fails when it leaves them.
 *
 * It fails in BOTH directions on purpose. A missing note is a class whose
 * removal now looks safe. A third class doing the same arithmetic is the
 * duplication the single derivation was built to end.
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
 * @spec openspec/changes/the-case-archives-through-openregister/specs/archief-edepot-handover/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Keeps the standing derivation declared, and keeps it alone.
 *
 * @spec openspec/changes/the-case-archives-through-openregister/specs/archief-edepot-handover/spec.md
 */
class StandingArchivalDerivationIsDeclaredTest extends TestCase {

	/**
	 * The two classes that still derive a case's archival future.
	 *
	 * @var array<int, string>
	 */
	private const STANDING = [
		'lib/Service/Archival/ArchivalNominationDeriver.php',
		'lib/Service/Archival/ArchivalBaseDateResolver.php',
	];

	/**
	 * The sentence each of them has to carry.
	 *
	 * Matched on the phrase rather than the whole paragraph, so the wording can
	 * be improved without the test turning into a spelling checker. The phrase
	 * is the claim itself: this class stands in for openregister.
	 *
	 * @var string
	 */
	private const CLAIM = 'STANDS IN FOR OPENREGISTER';

	/**
	 * The design note the claim has to point at, so the reason is findable.
	 *
	 * @var string
	 */
	private const POINTER = 'the-case-archives-through-openregister/design.md';

	/**
	 * The repository root.
	 *
	 * @return string The absolute path to the app root.
	 */
	private function root(): string {
		return dirname(__DIR__, 3);
	}//end root()

	/**
	 * Each standing class says it stands in, and says what blocks its removal.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-case-archives-through-openregister/specs/archief-edepot-handover/spec.md
	 */
	public function testEachStandingDerivationNamesWhatBlocksItsRemoval(): void {
		foreach (self::STANDING as $relative) {
			$path = $this->root() . '/' . $relative;

			$this->assertFileExists(
				$path,
				$relative . ' is gone. If openregister can now nominate a case, delete this'
					. ' test with it; if it cannot, the archiefactiedatum went with the file.'
			);

			$source = (string)file_get_contents($path);

			$this->assertStringContainsString(
				self::CLAIM,
				$source,
				$relative . ' no longer says it stands in for openregister, so its removal'
					. ' now reads as safe when it is not.'
			);

			$this->assertStringContainsString(
				self::POINTER,
				$source,
				$relative . ' no longer points at the design note that measures what blocks'
					. ' its removal, so the reason cannot be checked.'
			);
		}
	}//end testEachStandingDerivationNamesWhatBlocksItsRemoval()

	/**
	 * No third class adds the archival period to a base date.
	 *
	 * The marker is `archivalPeriod` read beside a `DateInterval`, which is the
	 * arithmetic itself rather than a name that happens to mention archiving.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-case-archives-through-openregister/specs/archief-edepot-handover/spec.md
	 */
	public function testNoThirdClassDerivesAnArchiveActionDate(): void {
		$found = [];

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($this->root() . '/lib')
		);

		foreach ($iterator as $file) {
			if ($file->isFile() === false || $file->getExtension() !== 'php') {
				continue;
			}

			$relative = str_replace($this->root() . '/', '', $file->getPathname());
			if (in_array($relative, self::STANDING, true) === true) {
				continue;
			}

			$source = (string)file_get_contents($file->getPathname());
			if (str_contains($source, 'archivalPeriod') === true
				&& str_contains($source, 'DateInterval') === true
			) {
				$found[] = $relative;
			}
		}

		$this->assertSame(
			[],
			$found,
			'These files add an archival period to a date, which belongs to openregister'
				. ' and, until openregister can, to exactly the two standing classes: '
				. implode(', ', $found)
		);
	}//end testNoThirdClassDerivesAnArchiveActionDate()
}//end class
