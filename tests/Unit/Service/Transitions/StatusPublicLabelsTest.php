<?php

/**
 * StatusPublicLabels Unit Tests
 *
 * Three questions, and only a unit test can ask two of them.
 *
 * The first is the ordinary one: a status that declares a public label shows
 * it. The second is the fallback, which is what makes this change additive: a
 * status that declares nothing shows its name, so every case type written
 * before today reads to the applicant exactly as it did.
 *
 * 🔴 THE THIRD IS THE ONE THAT MATTERS, AND A BROWSER CANNOT SEE IT. A missing
 * public DESCRIPTION must render as nothing, never as the internal
 * `description`. The internal one is written for a handler and names internal
 * checks, internal registers and colleagues by role. A fallback there would
 * publish those words to a citizen on every case type that ever filled a
 * description in, and the page would look entirely correct while doing it. The
 * test below is the only place that difference is ever asserted.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Transitions
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/citizen-status-labels/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use OCA\Dossiq\Service\Transitions\StatusPublicLabels;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Transitions\StatusPublicLabels
 */
class StatusPublicLabelsTest extends TestCase {
	/**
	 * A declared public label is what the applicant reads.
	 *
	 * @return void
	 */
	public function testDeclaredLabelWins(): void {
		$status = [
			'name' => 'Toets register B',
			'publicLabel' => 'We check your application',
		];

		$this->assertSame(
			expected: 'We check your application',
			actual: StatusPublicLabels::publicLabelOf(statusType: $status)
		);
	}//end testDeclaredLabelWins()

	/**
	 * No public label: the applicant reads the name, as before this change.
	 *
	 * @return void
	 */
	public function testLabelFallsBackToTheName(): void {
		$this->assertSame(
			expected: 'Ontvangen',
			actual: StatusPublicLabels::publicLabelOf(statusType: ['name' => 'Ontvangen'])
		);
	}//end testLabelFallsBackToTheName()

	/**
	 * A label of nothing but spaces is no label at all.
	 *
	 * An author who clears the field leaves the empty string behind, and a
	 * blank public label rendered literally would replace the status with a
	 * gap on the page.
	 *
	 * @return void
	 */
	public function testBlankLabelFallsBackToTheName(): void {
		$status = [
			'name' => 'In behandeling',
			'publicLabel' => '   ',
		];

		$this->assertSame(
			expected: 'In behandeling',
			actual: StatusPublicLabels::publicLabelOf(statusType: $status)
		);
	}//end testBlankLabelFallsBackToTheName()

	/**
	 * A status with neither reads as nothing, not as 'null'.
	 *
	 * @return void
	 */
	public function testAnEmptyRowReadsAsNothing(): void {
		$this->assertSame(
			expected: '',
			actual: StatusPublicLabels::publicLabelOf(statusType: [])
		);
	}//end testAnEmptyRowReadsAsNothing()

	/**
	 * A declared public description is what the applicant reads.
	 *
	 * @return void
	 */
	public function testDeclaredDescriptionIsShown(): void {
		$status = [
			'description' => 'Handler checks register B for prior objections.',
			'publicDescription' => 'You do not have to do anything right now.',
		];

		$this->assertSame(
			expected: 'You do not have to do anything right now.',
			actual: StatusPublicLabels::publicDescriptionOf(statusType: $status)
		);
	}//end testDeclaredDescriptionIsShown()

	/**
	 * 🔴 No public description shows NOTHING, never the internal one.
	 *
	 * See the file docblock. This is the assertion the whole class exists for.
	 *
	 * @return void
	 */
	public function testDescriptionNeverFallsBackToTheInternalOne(): void {
		$status = [
			'name' => 'Toets register B',
			'description' => 'Handler checks register B for prior objections.',
		];

		$this->assertSame(
			expected: '',
			actual: StatusPublicLabels::publicDescriptionOf(statusType: $status)
		);
	}//end testDescriptionNeverFallsBackToTheInternalOne()

	/**
	 * A projected case carries the answer the calculation already resolved.
	 *
	 * @return void
	 */
	public function testTheCaseCarriesTheLabel(): void {
		$case = [
			'identifier' => '2026-0001',
			'statusPublicLabel' => 'We check your application',
			'statusPublicDescription' => 'You hear from us within two weeks.',
		];

		$this->assertSame(
			expected: 'We check your application',
			actual: StatusPublicLabels::labelOnCase(case: $case)
		);
		$this->assertSame(
			expected: 'You hear from us within two weeks.',
			actual: StatusPublicLabels::descriptionOnCase(case: $case)
		);
	}//end testTheCaseCarriesTheLabel()

	/**
	 * A case projected without the fields reads as nothing to show.
	 *
	 * The portal hands a subject a case narrowed to a whitelist. A whitelist
	 * that omits these two must not make the reader render the literal null.
	 *
	 * @return void
	 */
	public function testACaseWithoutTheFieldsReadsAsNothing(): void {
		$this->assertSame(
			expected: '',
			actual: StatusPublicLabels::labelOnCase(case: ['identifier' => '2026-0001'])
		);
		$this->assertSame(
			expected: '',
			actual: StatusPublicLabels::descriptionOnCase(case: ['identifier' => '2026-0001'])
		);
	}//end testACaseWithoutTheFieldsReadsAsNothing()
}//end class
