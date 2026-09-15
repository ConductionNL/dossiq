<?php

/**
 * The handling switches, read from one place.
 *
 * Covers the reader every scattered caller now goes through: the declared block
 * wins, the legacy property answers for a case type nobody migrated, an
 * unknown message name is dropped rather than sent, and a switch no reader
 * reads is named so publication can refuse it.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\CaseType\CaseTypeHandling;
use OCA\Dossiq\Service\TermijnNotificationService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CaseTypeHandling.
 *
 * @covers \OCA\Dossiq\Service\CaseType\CaseTypeHandling
 */
class CaseTypeHandlingSwitchesTest extends TestCase {

	/**
	 * The reader under test.
	 *
	 * @var CaseTypeHandling
	 */
	private CaseTypeHandling $handling;

	/**
	 * Build the reader.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->handling = new CaseTypeHandling();
	}//end setUp()

	/**
	 * The declared group is the answer, and changing it is the only change needed.
	 *
	 * @return void
	 */
	public function testTheDeclaredDefaultGroupIsTheGroupACaseIsRoutedTo(): void {
		$caseType = ['handling' => ['defaultGroup' => 'toezicht']];

		self::assertSame(expected: 'toezicht', actual: $this->handling->defaultGroup(caseType: $caseType));
	}//end testTheDeclaredDefaultGroupIsTheGroupACaseIsRoutedTo()

	/**
	 * A case type nobody migrated still answers, from its intake destinations.
	 *
	 * Without this the day this shipped would have emptied the routing group of
	 * every existing case type and told nobody.
	 *
	 * @return void
	 */
	public function testAnUnmigratedCaseTypeStillNamesItsGroup(): void {
		$caseType = ['intakeDestinations' => [['department' => 'vergunningen']]];

		self::assertSame(expected: 'vergunningen', actual: $this->handling->defaultGroup(caseType: $caseType));
	}//end testAnUnmigratedCaseTypeStillNamesItsGroup()

	/**
	 * The declared handler beats the legacy property, and the legacy property
	 * answers when the block is silent.
	 *
	 * @return void
	 */
	public function testTheDeclaredHandlerWinsAndDefaultAssigneeIsTheFallback(): void {
		self::assertSame(
			expected: 'ahmed',
			actual: $this->handling->defaultHandler(
				caseType: ['handling' => ['defaultHandler' => 'ahmed'], 'defaultAssignee' => 'lisa']
			)
		);

		self::assertSame(
			expected: 'lisa',
			actual: $this->handling->defaultHandler(caseType: ['defaultAssignee' => 'lisa'])
		);
	}//end testTheDeclaredHandlerWinsAndDefaultAssigneeIsTheFallback()

	/**
	 * A case type that declares no messages keeps sending all of them.
	 *
	 * Defaulting to none would have silenced every acknowledgement on every
	 * existing instance on the day this shipped, with the settings screen still
	 * showing them as on.
	 *
	 * @return void
	 */
	public function testACaseTypeThatDeclaresNothingStillSendsEveryMessage(): void {
		self::assertSame(
			expected: CaseTypeHandling::MESSAGES,
			actual: $this->handling->automaticMessages(caseType: ['title' => 'Bezwaar'])
		);
	}//end testACaseTypeThatDeclaresNothingStillSendsEveryMessage()

	/**
	 * A message the case type does not name is not sent, and a name nothing
	 * sends is dropped rather than carried into the dispatcher.
	 *
	 * @return void
	 */
	public function testOnlyTheDeclaredAndKnownMessagesAreSent(): void {
		$caseType = ['handling' => ['automaticMessages' => ['extension', 'invented-message']]];

		self::assertSame(
			expected: ['extension'],
			actual: $this->handling->automaticMessages(caseType: $caseType)
		);
		self::assertTrue(condition: $this->handling->sends(caseType: $caseType, message: 'extension'));
		self::assertFalse(
			condition: $this->handling->sends(caseType: $caseType, message: 'ontvangstbevestiging')
		);
	}//end testOnlyTheDeclaredAndKnownMessagesAreSent()

	/**
	 * A case type declaring no messages at all sends none.
	 *
	 * The empty list is a decision somebody made, and it is not the same as the
	 * absent block that means "everything".
	 *
	 * @return void
	 */
	public function testAnEmptyDeclaredListSendsNothing(): void {
		$caseType = ['handling' => ['automaticMessages' => []]];

		self::assertSame(expected: [], actual: $this->handling->automaticMessages(caseType: $caseType));
		self::assertFalse(
			condition: $this->handling->sends(caseType: $caseType, message: 'ontvangstbevestiging')
		);
	}//end testAnEmptyDeclaredListSendsNothing()

	/**
	 * The message vocabulary the case type authors against is the one the
	 * sender actually dispatches.
	 *
	 * Two copies of one vocabulary drift, and the drift is invisible: a case
	 * type would declare a message the dispatcher throws on.
	 *
	 * @return void
	 */
	public function testTheAuthoredMessageNamesAreTheOnesTheSenderKnows(): void {
		self::assertSame(
			expected: TermijnNotificationService::TEMPLATES,
			actual: CaseTypeHandling::MESSAGES
		);
	}//end testTheAuthoredMessageNamesAreTheOnesTheSenderKnows()

	/**
	 * Each case type opens the screen its own block names.
	 *
	 * @return void
	 */
	public function testTheIntakeScreenFollowsTheCaseType(): void {
		self::assertSame(
			expected: 'vergunning-intake',
			actual: $this->handling->intakeScreen(caseType: ['handling' => ['intakeScreen' => 'vergunning-intake']])
		);
		self::assertSame(expected: '', actual: $this->handling->intakeScreen(caseType: []));
	}//end testTheIntakeScreenFollowsTheCaseType()

	/**
	 * A switch nothing reads is named, so publication can refuse it.
	 *
	 * @return void
	 */
	public function testASwitchNoReaderReadsIsNamed(): void {
		$caseType = [
			'handling' => [
				'defaultGroup' => 'toezicht',
				'escalateAfterDays' => 5,
				'autoCloseOnPayment' => true,
			],
		];

		self::assertSame(
			expected: ['autoCloseOnPayment', 'escalateAfterDays'],
			actual: $this->handling->unreadSwitches(caseType: $caseType)
		);
		self::assertSame(
			expected: [],
			actual: $this->handling->unreadSwitches(caseType: ['handling' => ['defaultGroup' => 'toezicht']])
		);
	}//end testASwitchNoReaderReadsIsNamed()
}//end class
