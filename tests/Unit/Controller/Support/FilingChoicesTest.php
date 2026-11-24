<?php

/**
 * Unit tests for the Link to case dialog's choices.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Controller\Support
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
 * @spec openspec/specs/case-email-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller\Support;

use OCA\Dossiq\Controller\ManualFiling;
use OCA\Dossiq\Controller\Support\FilingChoices;
use OCA\Dossiq\Kernel\Contracts\FilingFollowUpsInterface;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Controller\Support\FilingChoices
 * @uses   \OCA\Dossiq\Controller\ManualFiling
 * @uses   \OCA\Dossiq\Service\Intake\MessagePreview
 */
class FilingChoicesTest extends TestCase {

	/**
	 * A filed message from Henk Bakker on case-114.
	 *
	 * @param string $from The From header of the original.
	 *
	 * @return ManualFiling
	 */
	private function filing(string $from): ManualFiling {
		$filing = new ManualFiling();
		$filing->entryId = 'entry-1';
		$filing->caseId = 'case-114';
		$filing->entry = [
			'sender' => 'henk@example.org',
			'subject' => 'Vraag',
			'original' => 'From: ' . $from . "\r\n\r\nHallo",
		];

		return $filing;
	}//end filing()

	/**
	 * Both choices run, each with the sender's display name, and say what they did.
	 *
	 * @return void
	 */
	public function testBothChoicesRunWithTheSendersName(): void {
		$followUps = $this->createMock(originalClassName: FilingFollowUpsInterface::class);
		$followUps->expects($this->once())
			->method('addSenderAsParty')
			->with('case-114', 'henk@example.org', 'Henk Bakker')
			->willReturn(value: 'added');
		$followUps->expects($this->once())
			->method('notifyHandler')
			->with('case-114', 'intaker', 'Henk Bakker', 'Vraag')
			->willReturn(value: 'notified');

		$done = (new FilingChoices(followUps: $followUps))->run(
			filing: $this->filing(from: 'Henk Bakker <henk@example.org>'),
			choices: ['addSender' => true, 'notifyHandler' => true],
			callerId: 'intaker'
		);

		self::assertSame(expected: ['party' => 'added', 'handler' => 'notified'], actual: $done);
	}//end testBothChoicesRunWithTheSendersName()

	/**
	 * Without a display name the handler is told the address; nothing runs that was not chosen.
	 *
	 * @return void
	 */
	public function testTheAddressNamesASenderWithoutAName(): void {
		$followUps = $this->createMock(originalClassName: FilingFollowUpsInterface::class);
		$followUps->expects($this->never())->method('addSenderAsParty');
		$followUps->expects($this->once())
			->method('notifyHandler')
			->with('case-114', 'intaker', 'henk@example.org', 'Vraag')
			->willReturn(value: 'notified');

		$done = (new FilingChoices(followUps: $followUps))->run(
			filing: $this->filing(from: 'henk@example.org'),
			choices: ['addSender' => false, 'notifyHandler' => true],
			callerId: 'intaker'
		);

		self::assertSame(expected: ['handler' => 'notified'], actual: $done);
	}//end testTheAddressNamesASenderWithoutAName()

	/**
	 * No choice, no follow-up and an empty answer.
	 *
	 * @return void
	 */
	public function testNoChoiceRunsNothing(): void {
		$followUps = $this->createMock(originalClassName: FilingFollowUpsInterface::class);
		$followUps->expects($this->never())->method('addSenderAsParty');
		$followUps->expects($this->never())->method('notifyHandler');

		$done = (new FilingChoices(followUps: $followUps))->run(
			filing: $this->filing(from: 'Henk Bakker <henk@example.org>'),
			choices: ['addSender' => false, 'notifyHandler' => false],
			callerId: 'intaker'
		);

		self::assertSame(expected: [], actual: $done);
	}//end testNoChoiceRunsNothing()
}//end class
