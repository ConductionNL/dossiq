<?php

/**
 * Who a case type lets a handler pick, in the picker and on the write.
 *
 * The point of the requirement is that the two are the same declaration. A
 * picker offering two teams over an API that takes thirty is a narrowing that
 * exists on screen only, so the third group is driven twice here: once against
 * the list the picker would draw, and once against the write.
 *
 * The case type that narrows nothing is driven too, because that is every case
 * type an instance already has, and a change that narrowed them by accident
 * would take a gemeente's assignments away overnight.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Intake\AssigneeNarrowing;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the narrowing the picker reads and the write enforces.
 *
 * @covers \OCA\Dossiq\Service\Intake\AssigneeNarrowing
 * @uses \OCA\Dossiq\Exception\RefusedException
 */
class AssigneeNarrowingTest extends TestCase {

	/**
	 * A case type allowing exactly two teams.
	 *
	 * @var array<string, mixed>
	 */
	private const TWO_TEAMS = [
		'title' => 'Handhavingsverzoek',
		'assigneeNarrowing' => ['allowedGroups' => ['handhaving', 'juridische-zaken']],
	];

	/**
	 * The reader under test.
	 *
	 * @var AssigneeNarrowing
	 */
	private AssigneeNarrowing $narrowing;

	/**
	 * Build the reader.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->narrowing = new AssigneeNarrowing();
	}//end setUp()

	/**
	 * The picker offers only the declared teams.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md#requirement-a-case-type-narrows-who-may-be-assigned-at-creation-req-triage-03
	 */
	public function testThePickerOffersOnlyTheDeclaredTeams(): void {
		$offered = $this->narrowing->narrowGroups(
			offered: ['handhaving', 'juridische-zaken', 'burgerzaken'],
			caseType: self::TWO_TEAMS
		);

		$this->assertSame(['handhaving', 'juridische-zaken'], $offered);
	}//end testThePickerOffersOnlyTheDeclaredTeams()

	/**
	 * The write refuses a team the picker never offered.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md#requirement-a-case-type-narrows-who-may-be-assigned-at-creation-req-triage-03
	 */
	public function testTheWriteRefusesATeamThePickerNeverOffered(): void {
		try {
			$this->narrowing->assertWritable(
				case: ['title' => 'Verzoek', 'assignedGroup' => 'burgerzaken'],
				caseType: self::TWO_TEAMS
			);
			$this->fail('The write should have been refused.');
		} catch (RefusedException $e) {
			$this->assertSame(AssigneeNarrowing::RULE_GROUP_OUTSIDE, $e->getRule());
			$this->assertStringContainsString('burgerzaken', $e->getSentence());
		}
	}//end testTheWriteRefusesATeamThePickerNeverOffered()

	/**
	 * The refusal names the declaration that refused it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md#requirement-a-case-type-narrows-who-may-be-assigned-at-creation-req-triage-03
	 */
	public function testTheRefusalNamesTheCaseTypeDeclaration(): void {
		try {
			$this->narrowing->assertWritable(
				case: ['assignedGroup' => 'burgerzaken'],
				caseType: self::TWO_TEAMS
			);
			$this->fail('The write should have been refused.');
		} catch (RefusedException $e) {
			$this->assertStringContainsString('case type', $e->getSentence());
			$this->assertStringContainsString('who may handle this', $e->getSentence());
		}
	}//end testTheRefusalNamesTheCaseTypeDeclaration()

	/**
	 * A case type with no narrowing keeps every choice.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md#requirement-a-case-type-narrows-who-may-be-assigned-at-creation-req-triage-03
	 */
	public function testACaseTypeWithNoNarrowingKeepsEveryChoice(): void {
		$caseType = ['title' => 'Melding'];
		$every = ['handhaving', 'juridische-zaken', 'burgerzaken'];

		$this->assertTrue($this->narrowing->narrowsNothing(caseType: $caseType));
		$this->assertSame($every, $this->narrowing->narrowGroups(offered: $every, caseType: $caseType));

		$this->narrowing->assertWritable(
			case: ['assignedGroup' => 'burgerzaken', 'assignee' => 'jdevries'],
			caseType: $caseType
		);
	}//end testACaseTypeWithNoNarrowingKeepsEveryChoice()

	/**
	 * A person outside the narrowing is refused on their own axis.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md#requirement-a-case-type-narrows-who-may-be-assigned-at-creation-req-triage-03
	 */
	public function testAPersonOutsideTheNarrowingIsRefused(): void {
		$caseType = ['assigneeNarrowing' => ['allowedUsers' => ['jdevries']]];

		try {
			$this->narrowing->assertWritable(
				case: ['assignee' => 'mbakker'],
				caseType: $caseType
			);
			$this->fail('The write should have been refused.');
		} catch (RefusedException $e) {
			$this->assertSame(AssigneeNarrowing::RULE_USER_OUTSIDE, $e->getRule());
			$this->assertStringContainsString('mbakker', $e->getSentence());
		}
	}//end testAPersonOutsideTheNarrowingIsRefused()

	/**
	 * Narrowing teams does not narrow people, and the other way round.
	 *
	 * The two lists are separate axes. A case type that names its teams has
	 * not said anything about who inside them may take the case.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md
	 */
	public function testTheTwoListsAreSeparateAxes(): void {
		$this->narrowing->assertWritable(
			case: ['assignedGroup' => 'handhaving', 'assignee' => 'anyone-at-all'],
			caseType: self::TWO_TEAMS
		);

		$this->assertFalse($this->narrowing->narrowsNothing(caseType: self::TWO_TEAMS));
	}//end testTheTwoListsAreSeparateAxes()

	/**
	 * An unassigned case passes whatever the case type narrows.
	 *
	 * A case that names nobody has not chosen anybody outside the list, and
	 * refusing it would make an unassigned intake impossible.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md
	 */
	public function testAnUnassignedCasePasses(): void {
		$this->narrowing->assertWritable(
			case: ['title' => 'Nog niet toegewezen', 'assignedGroup' => '', 'assignee' => ''],
			caseType: self::TWO_TEAMS
		);

		$this->assertTrue(true, 'The write was not refused.');
	}//end testAnUnassignedCasePasses()
}//end class
