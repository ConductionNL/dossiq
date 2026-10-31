<?php

/**
 * Telling the requester that their case is completed, when it is archived.
 *
 * The notice goes through the one requester sender, so the channel order and
 * the record on the case are that sender's. What is asserted here is what this
 * class adds: the wording, the template and moment it is sent under, and that
 * every way of not sending it is answered in words rather than as silence.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Lifecycle
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/woo-requester-notices-really-go-out/specs/burger-notifications/spec.md#requirement-a-requester-notice-goes-out-through-a-real-channel-or-is-recorded-as-not-sent-req-wrn-001
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Lifecycle;

use OCA\Dossiq\Service\Lifecycle\ArchiveRequesterNotice;
use OCA\Dossiq\Service\Notification\RequesterNoticeSender;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Lifecycle\ArchiveRequesterNotice
 */
class ArchiveRequesterNoticeTest extends TestCase {

	/**
	 * An ended Woo case with a result the requester reads.
	 *
	 * @var array<string, mixed>
	 */
	private const CASE = [
		'id' => 'case-1',
		'identifier' => '2026-0082',
		'title' => 'Verlichting fietspad Lindelaan',
		'resultPublicLabel' => 'Deels openbaar gemaakt',
		'resultPublicDescription' => 'De adviezen en het raadsvoorstel zijn openbaar.',
	];

	/**
	 * The store the case is read from.
	 *
	 * @var CaseStatusStore&MockObject
	 */
	private CaseStatusStore $store;

	/**
	 * The one requester sender.
	 *
	 * @var RequesterNoticeSender&MockObject
	 */
	private RequesterNoticeSender $sender;

	/**
	 * A store that has the case, and a sender.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = $this->createMock(originalClassName: CaseStatusStore::class);
		$this->store->method('loadCase')->willReturn(self::CASE);
		$this->sender = $this->createMock(originalClassName: RequesterNoticeSender::class);
	}//end setUp()

	/**
	 * The notice names the case and its result, and answers the channel it went through.
	 *
	 * @return void
	 */
	public function testTheNoticeNamesTheCaseAndItsResultAndAnswersTheChannel(): void {
		$this->sender->expects($this->once())
			->method('send')
			->with(
				self::CASE,
				ArchiveRequesterNotice::TEMPLATE,
				$this->callback(
					callback: static fn (array $rendered): bool => $rendered['subject'] === 'Uw zaak 2026-0082 is afgehandeld'
						&& str_contains($rendered['body'], 'Verlichting fietspad Lindelaan')
						&& str_contains($rendered['body'], 'Deels openbaar gemaakt')
						&& str_contains($rendered['body'], 'De adviezen en het raadsvoorstel zijn openbaar.')
				),
				ArchiveRequesterNotice::MOMENT,
				$this->callback(callback: static fn (array $options): bool => $options['dedupeKey'] === 'archived:case-1'),
			)
			->willReturn(['status' => 'sent', 'channel' => 'portal-inbox', 'messageId' => 'm-1']);

		$notice = (new ArchiveRequesterNotice(store: $this->store, sender: $this->sender))->tell(caseId: 'case-1');

		$this->assertSame(
			expected: ['noticeStatus' => 'sent', 'noticeChannel' => 'portal-inbox', 'noticeReasonCode' => '', 'noticeReason' => ''],
			actual: $notice
		);
	}//end testTheNoticeNamesTheCaseAndItsResultAndAnswersTheChannel()

	/**
	 * A notice no channel took is answered with the sender's reason.
	 *
	 * @return void
	 */
	public function testANoticeNoChannelTookCarriesTheSendersReason(): void {
		$this->sender->method('send')->willReturn(
			[
				'status' => 'not-sent',
				'channel' => '',
				'reasonCode' => 'no-channel',
				'reason' => 'This case has no address for the requester, so the notice was not sent.',
			]
		);

		$notice = (new ArchiveRequesterNotice(store: $this->store, sender: $this->sender))->tell(caseId: 'case-1');

		$this->assertSame(expected: 'not-sent', actual: $notice['noticeStatus']);
		$this->assertSame(expected: 'no-channel', actual: $notice['noticeReasonCode']);
		$this->assertStringContainsString(needle: 'no address', haystack: $notice['noticeReason']);
	}//end testANoticeNoChannelTookCarriesTheSendersReason()

	/**
	 * Without a sender nothing is sent, and the answer says so.
	 *
	 * @return void
	 */
	public function testWithoutASenderTheAnswerSaysNothingWasSent(): void {
		$notice = (new ArchiveRequesterNotice(store: $this->store))->tell(caseId: 'case-1');

		$this->assertSame(expected: 'not-sent', actual: $notice['noticeStatus']);
		$this->assertSame(expected: 'notice-not-wired', actual: $notice['noticeReasonCode']);
	}//end testWithoutASenderTheAnswerSaysNothingWasSent()

	/**
	 * A case that cannot be read is not guessed at.
	 *
	 * @return void
	 */
	public function testACaseThatCannotBeReadIsNotSentTo(): void {
		$store = $this->createMock(originalClassName: CaseStatusStore::class);
		$store->method('loadCase')->willReturn(null);
		$this->sender->expects($this->never())->method('send');

		$notice = (new ArchiveRequesterNotice(store: $store, sender: $this->sender))->tell(caseId: 'case-1');

		$this->assertSame(expected: 'not-sent', actual: $notice['noticeStatus']);
		$this->assertSame(expected: 'case-not-found', actual: $notice['noticeReasonCode']);
	}//end testACaseThatCannotBeReadIsNotSentTo()

	/**
	 * A case without a result or a number still gets a sentence that reads.
	 *
	 * @return void
	 */
	public function testACaseWithoutAResultOrNumberStillReads(): void {
		$store = $this->createMock(originalClassName: CaseStatusStore::class);
		$store->method('loadCase')->willReturn(['id' => 'case-2', 'title' => 'Klacht']);
		$this->sender->expects($this->once())
			->method('send')
			->with(
				$this->anything(),
				$this->anything(),
				['subject' => 'Uw zaak is afgehandeld', 'body' => 'Wij hebben uw zaak Klacht afgehandeld.'],
				$this->anything(),
				$this->anything(),
			)
			->willReturn(['status' => 'sent', 'channel' => 'email']);

		(new ArchiveRequesterNotice(store: $store, sender: $this->sender))->tell(caseId: 'case-2');
	}//end testACaseWithoutAResultOrNumberStillReads()
}//end class
