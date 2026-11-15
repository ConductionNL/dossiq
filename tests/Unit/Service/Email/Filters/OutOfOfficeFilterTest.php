<?php

/**
 * Unit tests for the out-of-office filter.
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

use OCA\Dossiq\Service\Email\Filters\FilterOutcome;
use OCA\Dossiq\Service\Email\Filters\OutOfOfficeFilter;
use OCA\Dossiq\Service\Email\InboundMessage;
use PHPUnit\Framework\TestCase;

/**
 * Absence notices are refused; ordinary subjects pass.
 *
 * @covers \OCA\Dossiq\Service\Email\Filters\OutOfOfficeFilter
 * @uses \OCA\Dossiq\Service\Email\Filters\FilterOutcome
 * @uses \OCA\Dossiq\Service\Email\Filters\FilterVerdict
 * @uses \OCA\Dossiq\Service\Email\InboundMessage
 */
class OutOfOfficeFilterTest extends TestCase {

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
		$filter = new OutOfOfficeFilter();

		self::assertSame('out-of-office', $filter->name());
		self::assertSame(40, $filter->order());
	}//end testNameAndOrder()

	/**
	 * The Exchange loop header is refused whatever the subject.
	 *
	 * @return void
	 */
	public function testTheExchangeLoopHeaderIsRejected(): void {
		$verdict = (new OutOfOfficeFilter())->decide(
			message: $this->message(headers: ['x-ms-exchange-inbox-rules-loop' => 'jan@example.org'])
		);

		self::assertSame(FilterOutcome::REJECT, $verdict->outcome);
		self::assertSame('The message carries the Exchange out-of-office loop header.', $verdict->reason);
	}//end testTheExchangeLoopHeaderIsRejected()

	/**
	 * Absence subjects in English and Dutch are refused, case-insensitively.
	 *
	 * @return void
	 */
	public function testAnAbsenceSubjectIsRejected(): void {
		$filter = new OutOfOfficeFilter();

		foreach (['Out of Office: until Monday', 'Automatisch antwoord', 'AFWEZIG: tot 3 juni', 'Automatic reply: your request'] as $subject) {
			$verdict = $filter->decide(message: $this->message(subject: $subject));
			self::assertSame(FilterOutcome::REJECT, $verdict->outcome, $subject);
		}

		self::assertSame(
			'The subject opens with "afwezigheidsbericht", which is an absence notice.',
			$filter->decide(message: $this->message(subject: '  Afwezigheidsbericht'))->reason
		);
	}//end testAnAbsenceSubjectIsRejected()

	/**
	 * Reply and forward prefixes, stacked, are stripped before matching.
	 *
	 * @return void
	 */
	public function testReplyPrefixesAreStrippedFirst(): void {
		$verdict = (new OutOfOfficeFilter())->decide(message: $this->message(subject: 'RE: Fwd : antw: Out-of-office'));

		self::assertSame(FilterOutcome::REJECT, $verdict->outcome);
		self::assertSame('The subject opens with "out-of-office", which is an absence notice.', $verdict->reason);
	}//end testReplyPrefixesAreStrippedFirst()

	/**
	 * A subject that only mentions absence later on passes.
	 *
	 * @return void
	 */
	public function testAMentionLaterInTheSubjectPasses(): void {
		$filter = new OutOfOfficeFilter();

		self::assertSame(FilterOutcome::PASS, $filter->decide(message: $this->message(subject: 'Vraag: ik ben afwezig: wat nu?'))->outcome);
		self::assertSame(FilterOutcome::PASS, $filter->decide(message: $this->message())->outcome);
	}//end testAMentionLaterInTheSubjectPasses()
}//end class
