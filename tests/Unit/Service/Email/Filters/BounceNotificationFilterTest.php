<?php

/**
 * Unit tests for the bounce-notification filter.
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

use OCA\Dossiq\Service\Email\Filters\BounceNotificationFilter;
use OCA\Dossiq\Service\Email\Filters\FilterOutcome;
use OCA\Dossiq\Service\Email\InboundMessage;
use PHPUnit\Framework\TestCase;

/**
 * Delivery reports are refused; ordinary mail passes.
 *
 * @covers \OCA\Dossiq\Service\Email\Filters\BounceNotificationFilter
 * @uses \OCA\Dossiq\Service\Email\Filters\FilterOutcome
 * @uses \OCA\Dossiq\Service\Email\Filters\FilterVerdict
 * @uses \OCA\Dossiq\Service\Email\InboundMessage
 */
class BounceNotificationFilterTest extends TestCase {

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
		$filter = new BounceNotificationFilter();

		self::assertSame('bounce-notification', $filter->name());
		self::assertSame(20, $filter->order());
	}//end testNameAndOrder()

	/**
	 * A multipart/report with a delivery status is refused.
	 *
	 * @return void
	 */
	public function testADeliveryStatusReportIsRejected(): void {
		$verdict = (new BounceNotificationFilter())->decide(
			message: $this->message(headers: ['content-type' => 'multipart/report; Report-Type=Delivery-Status; boundary="x"'])
		);

		self::assertSame(FilterOutcome::REJECT, $verdict->outcome);
		self::assertSame('The message is a multipart/report carrying a delivery status.', $verdict->reason);
	}//end testADeliveryStatusReportIsRejected()

	/**
	 * Both spellings of the null return-path are refused.
	 *
	 * @return void
	 */
	public function testTheNullReturnPathIsRejected(): void {
		$filter = new BounceNotificationFilter();

		foreach (['<>', ' '] as $returnPath) {
			$verdict = $filter->decide(message: $this->message(headers: ['return-path' => $returnPath]));
			self::assertSame(FilterOutcome::REJECT, $verdict->outcome);
			self::assertSame('The message carries the null return-path a bounce is sent with.', $verdict->reason);
		}
	}//end testTheNullReturnPathIsRejected()

	/**
	 * A missing Return-Path is not the null return-path, and a real one passes.
	 *
	 * @return void
	 */
	public function testAnAbsentOrRealReturnPathPasses(): void {
		$filter = new BounceNotificationFilter();

		self::assertSame(FilterOutcome::PASS, $filter->decide(message: $this->message())->outcome);
		self::assertSame(
			FilterOutcome::PASS,
			$filter->decide(message: $this->message(headers: ['return-path' => '<jan@example.org>']))->outcome
		);
	}//end testAnAbsentOrRealReturnPathPasses()

	/**
	 * X-Failed-Recipients is refused.
	 *
	 * @return void
	 */
	public function testAFailedRecipientsHeaderIsRejected(): void {
		$verdict = (new BounceNotificationFilter())->decide(
			message: $this->message(headers: ['x-failed-recipients' => 'someone@example.org'])
		);

		self::assertSame(FilterOutcome::REJECT, $verdict->outcome);
		self::assertSame('The message names the recipients delivery failed for.', $verdict->reason);
	}//end testAFailedRecipientsHeaderIsRejected()

	/**
	 * A postmaster-style sender is refused, matched case-insensitively via the address.
	 *
	 * @return void
	 */
	public function testAMailServerSenderIsRejected(): void {
		$filter = new BounceNotificationFilter();

		foreach (['MAILER-DAEMON@mx.example.org', 'Postmaster <postmaster@example.org>', 'mail-daemon@x.org', 'double-bounce@x.org'] as $from) {
			$verdict = $filter->decide(message: $this->message(from: $from));
			self::assertSame(FilterOutcome::REJECT, $verdict->outcome, $from);
		}

		self::assertSame(
			'The sender is postmaster, which is a mail server reporting rather than a person writing.',
			$filter->decide(message: $this->message(from: 'postmaster@example.org'))->reason
		);
	}//end testAMailServerSenderIsRejected()

	/**
	 * A sender whose local part only resembles a postmaster passes.
	 *
	 * @return void
	 */
	public function testALookalikeSenderPasses(): void {
		$filter = new BounceNotificationFilter();

		self::assertSame(FilterOutcome::PASS, $filter->decide(message: $this->message(from: 'postmaster.jan@example.org'))->outcome);
		self::assertSame(FilterOutcome::PASS, $filter->decide(message: $this->message(from: 'jan@mailer-daemon.org'))->outcome);
		self::assertSame(FilterOutcome::PASS, $filter->decide(message: $this->message(from: ''))->outcome);
	}//end testALookalikeSenderPasses()
}//end class
