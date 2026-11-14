<?php

/**
 * The oldest-case line of the queue summary names the case type and how its deadline stands.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Queue
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Queue;

use OCA\Dossiq\Service\Queue\QueueOldestNote;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Queue\QueueOldestNote
 */
class QueueOldestNoteTest extends TestCase {
	/**
	 * A note over an IL10N double that renders what it is asked.
	 *
	 * @return QueueOldestNote
	 */
	private function note(): QueueOldestNote {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters)
		);
		$l10n->method('n')->willReturnCallback(
			static fn (string $singular, string $plural, int $count, array $parameters = []): string => vsprintf(
				str_replace('%n', (string)$count, ($count === 1 ? $singular : $plural)),
				$parameters
			)
		);

		return new QueueOldestNote(l10n: $l10n);
	}//end note()

	/**
	 * Without a type or a deadline the line is generic.
	 *
	 * @return void
	 */
	public function testWithoutATypeOrDeadlineTheLineIsGeneric(): void {
		$this->assertSame('the oldest case in the queue', $this->note()->line(type: '', dueDays: 4));
		$this->assertSame('the oldest case in the queue', $this->note()->line(type: 'Permit', dueDays: null));
	}//end testWithoutATypeOrDeadlineTheLineIsGeneric()

	/**
	 * A deadline today, overdue or ahead reads in the right form.
	 *
	 * @return void
	 */
	public function testTheDeadlineReadsInTheRightForm(): void {
		$note = $this->note();

		$this->assertSame('Permit, deadline today', $note->line(type: 'Permit', dueDays: 0));
		$this->assertSame('Permit, 1 day overdue', $note->line(type: 'Permit', dueDays: -1));
		$this->assertSame('Permit, 12 days overdue', $note->line(type: 'Permit', dueDays: -12));
		$this->assertSame('Permit, deadline in 1 day', $note->line(type: 'Permit', dueDays: 1));
		$this->assertSame('Permit, deadline in 3 days', $note->line(type: 'Permit', dueDays: 3));
	}//end testTheDeadlineReadsInTheRightForm()
}//end class
