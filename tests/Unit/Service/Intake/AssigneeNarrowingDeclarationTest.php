<?php

/**
 * A case type's handler narrowing as it is stored: untidy lists, users offered to it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Service\Intake
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 *
 * @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Intake;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Intake\AssigneeNarrowing;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Intake\AssigneeNarrowing
 * @uses   \OCA\Dossiq\Exception\RefusedException
 */
class AssigneeNarrowingDeclarationTest extends TestCase {

	/**
	 * A stored list keeps only distinct non-blank names, trimmed; anything that is no list narrows nothing.
	 *
	 * @return void
	 */
	public function testAStoredListIsTidiedAndANonListNarrowsNothing(): void {
		$narrowing = new AssigneeNarrowing();

		self::assertSame(
			['allowedGroups' => [], 'allowedUsers' => ['anna', 'bert']],
			$narrowing->declarationFor(
				caseType: ['assigneeNarrowing' => ['allowedGroups' => 'kcc', 'allowedUsers' => [' anna ', 7, '', '  ', 'anna', 'bert', null]]]
			)
		);
		self::assertTrue($narrowing->narrowsNothing(caseType: ['assigneeNarrowing' => ['allowedGroups' => 'kcc', 'allowedUsers' => [3, ' ']]]));
	}//end testAStoredListIsTidiedAndANonListNarrowsNothing()

	/**
	 * Offered users are narrowed to the allowed ones, keeping what was offered; with no list all are kept.
	 *
	 * @return void
	 */
	public function testOfferedUsersAreNarrowedToTheAllowedOnes(): void {
		$narrowing = new AssigneeNarrowing();
		$caseType = ['assigneeNarrowing' => ['allowedUsers' => ['anna', 'bert']]];

		self::assertSame([' anna', 'bert'], $narrowing->narrowUsers(offered: ['carl', ' anna', 'bert', 'dirk'], caseType: $caseType));
		self::assertSame(['carl', 'dirk'], $narrowing->narrowUsers(offered: ['carl', 'dirk'], caseType: []));
	}//end testOfferedUsersAreNarrowedToTheAllowedOnes()

	/**
	 * A handler outside a list tidied from untidy storage is still refused.
	 *
	 * @return void
	 */
	public function testAHandlerOutsideATidiedListIsRefused(): void {
		$caseType = ['assigneeNarrowing' => ['allowedUsers' => [' anna ', 42]]];

		(new AssigneeNarrowing())->assertWritable(case: ['assignee' => 'anna'], caseType: $caseType);

		try {
			(new AssigneeNarrowing())->assertWritable(case: ['assignee' => '42'], caseType: $caseType);
			self::fail('A handler outside the list must be refused');
		} catch (RefusedException $e) {
			self::assertSame(AssigneeNarrowing::RULE_USER_OUTSIDE, $e->getRule());
			self::assertSame(RefusedException::STATUS_UNPROCESSABLE, $e->getStatus());
			self::assertSame(
				'The handler 42 is not one this case type allows to handle it. The list lives on the case type, under who may handle this.',
				$e->getSentence()
			);
		}
	}//end testAHandlerOutsideATidiedListIsRefused()
}//end class
