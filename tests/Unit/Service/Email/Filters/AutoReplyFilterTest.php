<?php

/**
 * Unit tests for the auto-reply filter.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Email\Filters
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
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Email\Filters;

use OCA\Dossiq\Service\Email\Filters\AutoReplyFilter;
use OCA\Dossiq\Service\Email\Filters\FilterOutcome;
use OCA\Dossiq\Service\Email\InboundMessage;
use PHPUnit\Framework\TestCase;

/**
 * Machine-generated answers are refused; mail a person wrote passes.
 *
 * @covers \OCA\Dossiq\Service\Email\Filters\AutoReplyFilter
 * @uses \OCA\Dossiq\Service\Email\Filters\FilterOutcome
 * @uses \OCA\Dossiq\Service\Email\Filters\FilterVerdict
 * @uses \OCA\Dossiq\Service\Email\InboundMessage
 */
class AutoReplyFilterTest extends TestCase {

	/**
	 * Build a message with the given headers.
	 *
	 * @param array<string, string> $headers Lower-cased header names to values.
	 * @param string                $from    The From value.
	 * @param string                $subject The subject.
	 *
	 * @return InboundMessage The message.
	 */
	private function message(array $headers = [], string $from = 'Jan <jan@example.org>', string $subject = 'Vraag over mijn aanvraag'): InboundMessage {
		return new InboundMessage(
			accountId: 1,
			mailbox: 'INBOX',
			uid: 1,
			subject: $subject,
			from: $from,
			headers: $headers,
		);
	}//end message()

	/**
	 * The filter's name and place in the order.
	 *
	 * @return void
	 */
	public function testNameAndOrder(): void {
		$filter = new AutoReplyFilter();

		self::assertSame('auto-reply', $filter->name());
		self::assertSame(30, $filter->order());
	}//end testNameAndOrder()

	/**
	 * Any Auto-Submitted value other than "no" is refused, case-insensitively.
	 *
	 * @return void
	 */
	public function testAnAutoSubmittedMessageIsRejected(): void {
		$verdict = (new AutoReplyFilter())->decide(message: $this->message(headers: ['auto-submitted' => ' Auto-Replied ']));

		self::assertSame(FilterOutcome::REJECT, $verdict->outcome);
		self::assertSame('auto-reply', $verdict->filterName);
		self::assertSame('The message declares Auto-Submitted: auto-replied, so a machine sent it.', $verdict->reason);
	}//end testAnAutoSubmittedMessageIsRejected()

	/**
	 * Auto-Submitted: no is what a person's client sends, and it passes.
	 *
	 * @return void
	 */
	public function testAutoSubmittedNoPasses(): void {
		$verdict = (new AutoReplyFilter())->decide(message: $this->message(headers: ['auto-submitted' => 'No']));

		self::assertSame(FilterOutcome::PASS, $verdict->outcome);
	}//end testAutoSubmittedNoPasses()

	/**
	 * Each bulk Precedence value is refused; an ordinary one passes.
	 *
	 * @return void
	 */
	public function testABulkPrecedenceIsRejected(): void {
		$filter = new AutoReplyFilter();

		foreach (['bulk', 'LIST', 'junk', 'auto_reply'] as $precedence) {
			$verdict = $filter->decide(message: $this->message(headers: ['precedence' => $precedence]));
			self::assertSame(FilterOutcome::REJECT, $verdict->outcome, $precedence);
			self::assertSame(
				'The message declares Precedence: ' . strtolower($precedence) . ', so it is bulk rather than addressed to us.',
				$verdict->reason
			);
		}

		self::assertSame(
			FilterOutcome::PASS,
			$filter->decide(message: $this->message(headers: ['precedence' => 'first-class']))->outcome
		);
	}//end testABulkPrecedenceIsRejected()

	/**
	 * An auto-responder header is refused even with an empty value.
	 *
	 * @return void
	 */
	public function testAnAutoResponderHeaderIsRejectedWhateverItsValue(): void {
		$filter = new AutoReplyFilter();

		foreach (['x-autoreply', 'x-autorespond', 'x-auto-response-suppress'] as $header) {
			$verdict = $filter->decide(message: $this->message(headers: [$header => '']));
			self::assertSame(FilterOutcome::REJECT, $verdict->outcome, $header);
			self::assertSame('The message carries ' . $header . ', which only automatic mail sets.', $verdict->reason);
		}
	}//end testAnAutoResponderHeaderIsRejectedWhateverItsValue()

	/**
	 * A message without any of those headers passes.
	 *
	 * @return void
	 */
	public function testAPersonalMessagePasses(): void {
		$verdict = (new AutoReplyFilter())->decide(message: $this->message());

		self::assertSame(FilterOutcome::PASS, $verdict->outcome);
		self::assertSame('auto-reply', $verdict->filterName);
		self::assertSame('', $verdict->reason);
	}//end testAPersonalMessagePasses()
}//end class
