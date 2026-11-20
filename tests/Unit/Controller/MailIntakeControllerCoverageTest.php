<?php

/**
 * MailIntakeController bounce, move and edge-path tests.
 *
 * Covers what MailIntakeControllerTest leaves out: the outcome filter on the
 * log, reading one entry, the doorzendplicht bounce and the mailbox move in
 * both their outcomes, a log that throws, a message logged without its
 * original, and a session that ends between the two guards of a filing by
 * hand.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
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
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use DateTime;
use OCA\Dossiq\Controller\MailIntakeController;
use OCA\Dossiq\Kernel\Contracts\BounceActionInterface;
use OCA\Dossiq\Kernel\Contracts\CaseAccessGuardInterface;
use OCA\Dossiq\Kernel\Contracts\FilterPipelineInterface;
use OCA\Dossiq\Kernel\Contracts\InboundMailIntakeInterface;
use OCA\Dossiq\Kernel\Contracts\IntakeLogInterface;
use OCA\Dossiq\Kernel\Contracts\IntakePolicyInterface;
use OCA\Dossiq\Kernel\Contracts\JunkRulesInterface;
use OCA\Dossiq\Kernel\Contracts\MoveActionInterface;
use OCA\Dossiq\Service\Email\InboundMessage;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The bounce, the move and the edges of the intake surface.
 *
 * @covers \OCA\Dossiq\Controller\MailIntakeController
 * @uses \OCA\Dossiq\Controller\ManualFiling
 * @uses \OCA\Dossiq\Controller\Support\IntakeRoleGate
 * @uses \OCA\Dossiq\Service\Email\Filters\FilterVerdict
 * @uses \OCA\Dossiq\Service\Email\InboundMessage
 * @uses \OCA\Dossiq\Service\Support\SessionUid
 *
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */
final class MailIntakeControllerCoverageTest extends TestCase {

	/**
	 * The log.
	 *
	 * @var IntakeLogInterface&MockObject
	 */
	private IntakeLogInterface $log;

	/**
	 * The bounce act.
	 *
	 * @var BounceActionInterface&MockObject
	 */
	private BounceActionInterface $bounce;

	/**
	 * The move act.
	 *
	 * @var MoveActionInterface&MockObject
	 */
	private MoveActionInterface $move;

	/**
	 * The intake path.
	 *
	 * @var InboundMailIntakeInterface&MockObject
	 */
	private InboundMailIntakeInterface $intake;

	/**
	 * The per-case guard.
	 *
	 * @var CaseAccessGuardInterface&MockObject
	 */
	private CaseAccessGuardInterface $caseAccessGuard;

	/**
	 * The session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession $session;

	/**
	 * The signed-in caller.
	 *
	 * @var IUser&MockObject
	 */
	private IUser $user;

	/**
	 * Build collaborators for a caller who holds the intake role.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->log             = $this->createMock(originalClassName: IntakeLogInterface::class);
		$this->bounce          = $this->createMock(originalClassName: BounceActionInterface::class);
		$this->move            = $this->createMock(originalClassName: MoveActionInterface::class);
		$this->intake          = $this->createMock(originalClassName: InboundMailIntakeInterface::class);
		$this->caseAccessGuard = $this->createMock(originalClassName: CaseAccessGuardInterface::class);

		$this->user = $this->createMock(originalClassName: IUser::class);
		$this->user->method('getUID')->willReturn('intaker');

		$this->session = $this->createMock(originalClassName: IUserSession::class);
	}//end setUp()

	/**
	 * The controller, with a signed-in intake handler unless the test set the session up.
	 *
	 * @param bool $defaultSession Whether to configure the session to answer the handler.
	 *
	 * @return MailIntakeController The surface under test.
	 */
	private function controller(bool $defaultSession = true): MailIntakeController {
		if ($defaultSession === true) {
			$this->session->method('getUser')->willReturn($this->user);
		}

		$policy = $this->createMock(originalClassName: IntakePolicyInterface::class);
		$policy->method('mayRunIntake')->with('intaker')->willReturn(true);

		$pipeline = $this->createMock(originalClassName: FilterPipelineInterface::class);
		$pipeline->method('declaredOrder')->willReturn(['blocked-sender']);

		$junkRules = $this->createMock(originalClassName: JunkRulesInterface::class);
		$junkRules->method('rules')->willReturn([]);

		$time = $this->createMock(originalClassName: ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-09-14T12:00:00+00:00'));

		return new MailIntakeController(
			request: $this->createMock(originalClassName: IRequest::class),
			log: $this->log,
			policy: $policy,
			pipeline: $pipeline,
			junkRules: $junkRules,
			bounce: $this->bounce,
			move: $this->move,
			intake: $this->intake,
			userSession: $this->session,
			time: $time,
			caseAccessGuard: $this->caseAccessGuard,
		);
	}//end controller()

	/**
	 * One stored entry, as the log answers it.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 *
	 * @return array<string, mixed> The entry.
	 */
	private function entry(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'entry-1',
				'accountId' => 7,
				'mailbox' => 'INBOX',
				'uid' => 12,
				'mailMessageId' => '<1@voorbeeld.nl>',
				'sender' => 'aanvrager@voorbeeld.nl',
				'subject' => 'Bezwaar',
				'outcome' => IntakeLogInterface::OUTCOME_INBOX,
				'spfResult' => 'pass',
				'dkimResult' => 'fail',
				'dmarcResult' => 'none',
				'threadingResult' => '',
				'original' => "From: aanvrager@voorbeeld.nl\r\n\r\ntekst",
			],
			$overrides
		);
	}//end entry()

	/**
	 * The log is narrowed by outcome as well as by sender.
	 *
	 * @return void
	 */
	public function testTheLogIsNarrowedByOutcome(): void {
		$this->log->expects($this->once())->method('search')
			->with(['_limit' => 100, 'outcome' => IntakeLogInterface::OUTCOME_QUARANTINED])
			->willReturn([['id' => 'entry-1']]);

		$response = $this->controller()->index(outcome: IntakeLogInterface::OUTCOME_QUARANTINED);

		self::assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		self::assertSame(
			expected: ['results' => [['id' => 'entry-1']], 'filterOrder' => ['blocked-sender'], 'junkRules' => []],
			actual: $response->getData()
		);
	}//end testTheLogIsNarrowedByOutcome()

	/**
	 * One entry is answered whole.
	 *
	 * @return void
	 */
	public function testShowAnswersTheEntry(): void {
		$entry = $this->entry();
		$this->log->expects($this->once())->method('find')->with('entry-1')->willReturn($entry);

		$response = $this->controller()->show(entryId: 'entry-1');

		self::assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		self::assertSame(expected: $entry, actual: $response->getData());
	}//end testShowAnswersTheEntry()

	/**
	 * A log that throws reads as an entry that is not there.
	 *
	 * @return void
	 */
	public function testALogThatThrowsIsNotFound(): void {
		$this->log->method('find')->willThrowException(new RuntimeException('store down'));

		$response = $this->controller()->show(entryId: 'entry-1');

		self::assertSame(expected: Http::STATUS_NOT_FOUND, actual: $response->getStatus());
		self::assertSame(expected: ['message' => 'not_found'], actual: $response->getData());
	}//end testALogThatThrowsIsNotFound()

	/**
	 * A sent bounce goes out with the rebuilt message and is recorded on the entry.
	 *
	 * @return void
	 */
	public function testASentBounceIsRecordedOnTheEntry(): void {
		$this->log->method('find')->willReturn($this->entry());
		$this->bounce->expects($this->once())->method('bounce')
			->with(
				$this->callback(
					callback: static fn (InboundMessage $message): bool => $message->accountId === 7
						&& $message->mailbox === 'INBOX'
						&& $message->uid === 12
						&& $message->from === 'aanvrager@voorbeeld.nl'
						&& $message->source !== ''
				),
				'Gemeente Elders <post@elders.nl>',
				'Belongs to Elders',
				'intaker',
				['spf' => 'pass', 'dkim' => 'fail', 'dmarc' => 'none', 'threading' => '']
			)
			->willReturn(['sent' => true, 'entryId' => 'entry-2']);
		$this->log->expects($this->once())->method('amend')->with(
			'entry-1',
			[
				'outcome' => IntakeLogInterface::OUTCOME_FORWARDED,
				'forwardedTo' => 'post@elders.nl',
				'forwardedBy' => 'intaker',
				'forwardedReason' => 'Belongs to Elders',
			]
		);

		$response = $this->controller()->bounce(entryId: 'entry-1', address: 'Gemeente Elders <post@elders.nl>', reason: 'Belongs to Elders');

		self::assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		self::assertSame(expected: ['sent' => true, 'entryId' => 'entry-2'], actual: $response->getData());
	}//end testASentBounceIsRecordedOnTheEntry()

	/**
	 * A bounce that did not go out is a 400 and is not recorded.
	 *
	 * @return void
	 */
	public function testAFailedBounceIsRefusedAndNotRecorded(): void {
		$this->log->method('find')->willReturn($this->entry());
		$this->bounce->method('bounce')->willReturn(['sent' => false, 'entryId' => '']);
		$this->log->expects($this->never())->method('amend');

		$response = $this->controller()->bounce(entryId: 'entry-1', address: 'post@elders.nl', reason: 'Elders');

		self::assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus());
		self::assertSame(expected: ['message' => 'bounce_failed'], actual: $response->getData());
	}//end testAFailedBounceIsRefusedAndNotRecorded()

	/**
	 * A confirmed move is recorded on the entry.
	 *
	 * @return void
	 */
	public function testAConfirmedMoveIsRecordedOnTheEntry(): void {
		$this->log->method('find')->willReturn($this->entry());
		$this->move->expects($this->once())->method('move')
			->with(
				$this->isInstanceOf(className: InboundMessage::class),
				'INBOX/Archief',
				'Not for us',
				'intaker',
				['spf' => 'pass', 'dkim' => 'fail', 'dmarc' => 'none', 'threading' => '']
			)
			->willReturn(['moved' => true, 'entryId' => 'entry-3']);
		$this->log->expects($this->once())->method('amend')->with(
			'entry-1',
			['outcome' => IntakeLogInterface::OUTCOME_MOVED, 'movedTo' => 'INBOX/Archief', 'movedBy' => 'intaker']
		);

		$response = $this->controller()->move(entryId: 'entry-1', target: 'INBOX/Archief', note: 'Not for us');

		self::assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		self::assertSame(expected: ['moved' => true, 'entryId' => 'entry-3'], actual: $response->getData());
	}//end testAConfirmedMoveIsRecordedOnTheEntry()

	/**
	 * A move the mail server did not confirm is a 400 and is not recorded.
	 *
	 * @return void
	 */
	public function testAnUnconfirmedMoveIsRefusedAndNotRecorded(): void {
		$this->log->method('find')->willReturn($this->entry());
		$this->move->method('move')->willReturn(['moved' => false, 'entryId' => '']);
		$this->log->expects($this->never())->method('amend');

		$response = $this->controller()->move(entryId: 'entry-1', target: 'INBOX/Archief');

		self::assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus());
		self::assertSame(expected: ['message' => 'move_failed'], actual: $response->getData());
	}//end testAnUnconfirmedMoveIsRefusedAndNotRecorded()

	/**
	 * A message logged without its original is rebuilt from the row alone.
	 *
	 * @return void
	 */
	public function testAMessageWithoutItsOriginalIsRebuiltFromTheRow(): void {
		$this->log->method('find')->willReturn($this->entry(overrides: ['original' => '']));
		$this->intake->expects($this->once())->method('process')
			->with(
				$this->callback(
					callback: static fn (InboundMessage $message): bool => $message->source === ''
						&& $message->messageId === '<1@voorbeeld.nl>'
						&& $message->subject === 'Bezwaar'
				)
			)
			->willReturn(IntakeLogInterface::OUTCOME_CASE);

		$response = $this->controller()->junk(entryId: 'entry-1', junk: false);

		self::assertSame(expected: ['junk' => false, 'outcome' => IntakeLogInterface::OUTCOME_CASE], actual: $response->getData());
	}//end testAMessageWithoutItsOriginalIsRebuiltFromTheRow()

	/**
	 * A session that ends between the role check and the case check is unauthenticated.
	 *
	 * @return void
	 */
	public function testFileOnCaseWhoseSessionEndsBeforeTheCaseCheckIsUnauthenticated(): void {
		$this->session->method('getUser')->willReturnOnConsecutiveCalls($this->user, null);
		$this->caseAccessGuard->expects($this->never())->method('hasCaseReadAccess');
		$this->intake->expects($this->never())->method('fileOnCase');

		$response = $this->controller(defaultSession: false)
			->fileOnCase(entryId: 'entry-1', caseId: 'case-114', reason: 'Wrong match');

		self::assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $response->getStatus());
		self::assertSame(expected: ['message' => 'unauthenticated'], actual: $response->getData());
	}//end testFileOnCaseWhoseSessionEndsBeforeTheCaseCheckIsUnauthenticated()
}//end class
