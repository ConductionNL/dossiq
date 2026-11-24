<?php

/**
 * Unit tests for the citizen case collection declaration.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Portal
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
 * @spec openspec/specs/portal-contribution/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Portal;

use OCA\Dossiq\Portal\CitizenCaseCollection;
use OCA\Dossiq\Portal\CitizenManifest;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Portal\CitizenCaseCollection
 * @uses   \OCA\Dossiq\Portal\CitizenManifest
 */
class CitizenCaseCollectionTest extends TestCase {

	/**
	 * The organisation's intro is trimmed onto the collection; a blank one adds nothing.
	 *
	 * @return void
	 */
	public function testTheIntroIsTrimmedAndABlankOneIsLeftOut(): void {
		$with = (new CitizenCaseCollection(caseListIntro: '  Uw aanvragen.  '))->declaration();
		$without = (new CitizenCaseCollection(caseListIntro: '   '))->declaration();

		self::assertSame(expected: 'Uw aanvragen.', actual: $with['intro']);
		self::assertArrayNotHasKey(key: 'intro', array: $without);
	}//end testTheIntroIsTrimmedAndABlankOneIsLeftOut()

	/**
	 * The resident proposes changes from a row, and the branch and party fields are projected.
	 *
	 * @return void
	 */
	public function testTheRowActionAndTheTurnFieldsAreDeclared(): void {
		$collection = (new CitizenCaseCollection())->declaration();

		self::assertSame(expected: [CitizenManifest::PROPOSE_ACTION], actual: $collection['rowActions']);
		self::assertContains(needle: CitizenManifest::BRANCH_FIELD, haystack: $collection['fields']);
		self::assertContains(needle: CitizenManifest::PARTY_FIELD, haystack: $collection['fields']);
	}//end testTheRowActionAndTheTurnFieldsAreDeclared()
}//end class
