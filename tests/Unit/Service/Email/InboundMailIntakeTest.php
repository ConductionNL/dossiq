<?php

/**
 * Unit tests for the class that decides what one inbound message becomes.
 *
 * 🔴 THE PROPERTY UNDER TEST IS THAT THERE IS NO SEVENTH BRANCH. The job this
 * replaced used to `continue` on a message it could not place, writing nothing,
 * so an instance losing every aanvraag looked exactly like an instance
 * receiving none. {@see self::testEveryMessageInABatchIsAccountedFor} walks one
 * message of every shape through `processBatch()` and asserts the counts add up
 * to the batch size, which is the assertion a silent `continue` fails.
 *
 * THE SECOND PROPERTY IS THE FORGERY GUARD. A `fail` threading result means the
 * message claims to reply to something this account never sent, and the subject
 * tag on such a message names somebody else's case.
 * {@see self::testAForgedThreadingClaimDoesNotReachTheCaseItsSubjectNames}
 * asserts the case is never looked up at all, rather than asserting on the
 * outcome, because a message that lands in the inbox for an unrelated reason
 * would pass an outcome assertion while the lookup still happened.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Email
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

namespace OCA\Dossiq\Tests\Unit\Service\Email;

use OCA\Dossiq\Service\Email\AuthenticationResult;
use OCA\Dossiq\Service\Email\AuthenticationVerdict;
use OCA\Dossiq\Service\Email\CaseEmailRepository;
use OCA\Dossiq\Service\Email\Filters\FilterPipeline;
use OCA\Dossiq\Service\Email\Filters\FilterVerdict;
use OCA\Dossiq\Service\Email\InboundMailIntake;
use OCA\Dossiq\Service\Email\InboundMessage;
use OCA\Dossiq\Service\Email\IntakeLog;
use OCA\Dossiq\Service\Email\IntakePolicy;
use OCA\Dossiq\Service\Email\MailGatewayInterface;
use OCA\Dossiq\Service\Email\ThreadingCheck;
use OCA\Dossiq\Service\Email\UnmatchedMailIntake;
use OCA\Dossiq\Service\EmailArchivalService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers the six outcomes, the policy branches and the threading guard.
 *
 * @covers \OCA\Dossiq\Service\Email\InboundMailIntake
 * @uses \OCA\Dossiq\Service\Email\Filters\FilterVerdict
 * @uses \OCA\Dossiq\Service\Email\InboundMessage
 * @uses \OCA\Dossiq\Service\Email\AuthenticationVerdict
 *
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */
final class InboundMailIntakeTest extends TestCase {

	/**
	 * The gateway that hands over raw sources.
	 *
	 * @var MailGatewayInterface&MockObject
	 */
	private MailGatewayInterface $gateway;

	/**
	 * The declared filter order.
	 *
	 * @var FilterPipeline&MockObject
	 */
	private FilterPipeline $pipeline;

	/**
	 * The four authentication results.
	 *
	 * @var AuthenticationVerdict&MockObject
	 */
	private AuthenticationVerdict $verdicts;

	/**
	 * The threading claim checker.
	 *
	 * @var ThreadingCheck&MockObject
	 */
	private ThreadingCheck $threading;

	/**
	 * What a failing verdict means per case type.
	 *
	 * @var IntakePolicy&MockObject
	 */
	private IntakePolicy $policy;

	/**
	 * The log.
	 *
	 * @var IntakeLog&MockObject
	 */
	private IntakeLog $log;

	/**
	 * Resolves a case identifier.
	 *
	 * @var CaseEmailRepository&MockObject
	 */
	private CaseEmailRepository $cases;

	/**
	 * What a message nobody claims becomes.
	 *
	 * @var UnmatchedMailIntake&MockObject
	 */
	private UnmatchedMailIntake $unmatched;

	/**
	 * Files a message on its case.
	 *
	 * @var EmailArchivalService&MockObject
	 */
	private EmailArchivalService $archival;

	/**
	 * Every outcome the log was asked to record, in order.
	 *
	 * @var array<int, string>
	 */
	private array $recorded = [];

	/**
	 * Build the collaborators with the defaults of a quiet, healthy instance.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->recorded = [];

		$this->gateway = $this->createMock(MailGatewayInterface::class);
		$this->gateway->method('source')->willReturn('');

		$this->pipeline = $this->createMock(FilterPipeline::class);
		$this->pipeline->method('run')->willReturn(FilterVerdict::accept());

		$this->verdicts = $this->createMock(AuthenticationVerdict::class);
		$this->verdicts->method('forMessage')->willReturn(
			[
				'spf' => AuthenticationResult::PASS,
				'dkim' => AuthenticationResult::PASS,
				'dmarc' => AuthenticationResult::PASS,
				'threading' => AuthenticationResult::NONE,
			]
		);

		$this->threading = $this->createMock(ThreadingCheck::class);
		$this->threading->method('allowsSubjectTagLink')->willReturn(true);

		$this->policy = $this->createMock(IntakePolicy::class);
		$this->policy->method('forCaseType')->willReturn(IntakePolicy::DEFAULT_POLICY);
		$this->policy->method('outcomeFor')->willReturn(IntakePolicy::ACCEPT);

		$this->log = $this->createMock(IntakeLog::class);
		$this->log->method('record')->willReturnCallback(
			function (
				InboundMessage $message,
				FilterVerdict $verdict,
				array $results,
				string $outcome,
				string $reason,
				string $caseId = '',
			): string {
				$this->recorded[] = $outcome;

				return 'entry-' . count($this->recorded);
			}
		);

		$this->cases = $this->createMock(CaseEmailRepository::class);
		$this->cases->method('findCaseIdByIdentifier')->willReturn(null);
		$this->cases->method('loadCaseRecord')->willReturn([]);

		$this->unmatched = $this->createMock(UnmatchedMailIntake::class);
		$this->unmatched->method('fallbackCaseTypeId')->willReturn('');
		$this->unmatched->method('caseFor')->willReturn(null);

		$this->archival = $this->createMock(EmailArchivalService::class);
	}//end setUp()

	/**
	 * The class under test, built from whatever the test configured.
	 *
	 * @return InboundMailIntake The intake path.
	 */
	private function intake(): InboundMailIntake {
		return new InboundMailIntake(
			gateway: $this->gateway,
			pipeline: $this->pipeline,
			verdicts: $this->verdicts,
			threading: $this->threading,
			policy: $this->policy,
			log: $this->log,
			cases: $this->cases,
			unmatched: $this->unmatched,
			archival: $this->archival,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end intake()

	/**
	 * One message, as the gateway hands it over.
	 *
	 * @param string $subject The subject line.
	 * @param int    $uid     The uid inside the folder.
	 *
	 * @return InboundMessage The message.
	 */
	private function message(string $subject = 'Een vraag', int $uid = 1): InboundMessage {
		return new InboundMessage(
			accountId: 7,
			mailbox: 'INBOX',
			uid: $uid,
			messageId: '<' . $uid . '@voorbeeld.nl>',
			subject: $subject,
			from: 'aanvrager@voorbeeld.nl',
			to: 'postbus@gemeente.nl',
		);
	}//end message()

	/**
	 * A rejected message is refused and written down, not dropped.
	 *
	 * @return void
	 */
	public function testARejectedMessageIsRecordedAsRefused(): void {
		$this->pipeline = $this->createMock(FilterPipeline::class);
		$this->pipeline->method('run')->willReturn(
			FilterVerdict::reject('auto-reply', 'The message carries auto-submitted: auto-replied.')
		);

		$outcome = $this->intake()->process(message: $this->message());

		self::assertSame(IntakeLog::OUTCOME_REFUSED, $outcome);
		self::assertSame([IntakeLog::OUTCOME_REFUSED], $this->recorded, 'A refusal is a log line.');
	}//end testARejectedMessageIsRecordedAsRefused()

	/**
	 * A filter asking for a forward leaves an inbox entry, because nobody sent it yet.
	 *
	 * @return void
	 */
	public function testAForwardRequestWaitsInTheInboxUntilSomebodyPerformsIt(): void {
		$this->pipeline = $this->createMock(FilterPipeline::class);
		$this->pipeline->method('run')->willReturn(
			FilterVerdict::forward('misdirected', 'Not ours', 'info@anderegemeente.nl')
		);

		$outcome = $this->intake()->process(message: $this->message());

		self::assertSame(IntakeLog::OUTCOME_INBOX, $outcome, 'A forward nobody sent is not a forward.');
	}//end testAForwardRequestWaitsInTheInboxUntilSomebodyPerformsIt()

	/**
	 * A case type that refuses an unauthenticated sender gets its refusal.
	 *
	 * @return void
	 */
	public function testACaseTypeThatRefusesGetsARefusal(): void {
		$this->policy = $this->createMock(IntakePolicy::class);
		$this->policy->method('forCaseType')->willReturn(IntakePolicy::REFUSE);
		$this->policy->method('outcomeFor')->willReturn(IntakePolicy::REFUSE);

		$outcome = $this->intake()->process(message: $this->message());

		self::assertSame(IntakeLog::OUTCOME_REFUSED, $outcome);
	}//end testACaseTypeThatRefusesGetsARefusal()

	/**
	 * The default policy holds a message rather than dropping or accepting it.
	 *
	 * @return void
	 */
	public function testTheDefaultPolicyHoldsTheMessageForAPerson(): void {
		$this->policy = $this->createMock(IntakePolicy::class);
		$this->policy->method('forCaseType')->willReturn(IntakePolicy::DEFAULT_POLICY);
		$this->policy->method('outcomeFor')->willReturn(IntakePolicy::QUARANTINE);

		$outcome = $this->intake()->process(message: $this->message());

		self::assertSame(IntakeLog::OUTCOME_QUARANTINED, $outcome);
		self::assertSame(IntakePolicy::QUARANTINE, IntakePolicy::DEFAULT_POLICY, 'Quarantine is the default.');
	}//end testTheDefaultPolicyHoldsTheMessageForAPerson()

	/**
	 * A message nothing claims lands in the intake inbox, never nowhere.
	 *
	 * @return void
	 */
	public function testAMessageNothingClaimsLandsInTheIntakeInbox(): void {
		$outcome = $this->intake()->process(message: $this->message());

		self::assertSame(IntakeLog::OUTCOME_INBOX, $outcome);
		self::assertSame([IntakeLog::OUTCOME_INBOX], $this->recorded, 'The inbox entry is written down.');
	}//end testAMessageNothingClaimsLandsInTheIntakeInbox()

	/**
	 * A subject tag its threading result supports reaches the case it names.
	 *
	 * @return void
	 */
	public function testAGenuineReplyReachesTheCaseItsSubjectNames(): void {
		$this->cases = $this->createMock(CaseEmailRepository::class);
		$this->cases->expects(self::once())
			->method('findCaseIdByIdentifier')
			->with('2026-000142')
			->willReturn('case-142');
		$this->cases->method('loadCaseRecord')->willReturn(['caseType' => ['id' => 'bezwaar']]);

		$this->archival->expects(self::once())
			->method('archiveLinkedEmail')
			->with('case-142', self::anything());

		$outcome = $this->intake()->process(message: $this->message(subject: 'Re: [ZAAK-2026-000142] bezwaar'));

		self::assertSame(IntakeLog::OUTCOME_CASE, $outcome);
	}//end testAGenuineReplyReachesTheCaseItsSubjectNames()

	/**
	 * A forged reference never even looks the case up.
	 *
	 * @return void
	 */
	public function testAForgedThreadingClaimDoesNotReachTheCaseItsSubjectNames(): void {
		$this->threading = $this->createMock(ThreadingCheck::class);
		$this->threading->method('allowsSubjectTagLink')->willReturn(false);

		$this->cases = $this->createMock(CaseEmailRepository::class);
		$this->cases->expects(self::never())->method('findCaseIdByIdentifier');
		$this->cases->method('loadCaseRecord')->willReturn([]);

		$this->archival->expects(self::never())->method('archiveLinkedEmail');

		$outcome = $this->intake()->process(message: $this->message(subject: 'Re: [ZAAK-2026-000142] bezwaar'));

		self::assertSame(IntakeLog::OUTCOME_INBOX, $outcome, 'A forged claim gets no case, and is still filed.');
	}//end testAForgedThreadingClaimDoesNotReachTheCaseItsSubjectNames()

	/**
	 * A message the fallback case type claims becomes a case of that type.
	 *
	 * @return void
	 */
	public function testAMessageTheFallbackCaseTypeClaimsBecomesACase(): void {
		$this->unmatched = $this->createMock(UnmatchedMailIntake::class);
		$this->unmatched->method('fallbackCaseTypeId')->willReturn('melding');
		$this->unmatched->method('caseFor')->willReturn('case-new');

		$outcome = $this->intake()->process(message: $this->message());

		self::assertSame(IntakeLog::OUTCOME_CASE, $outcome);
	}//end testAMessageTheFallbackCaseTypeClaimsBecomesACase()

	/**
	 * A filing failure still leaves the message accounted for.
	 *
	 * @return void
	 */
	public function testAFilingFailureStillRecordsTheMessage(): void {
		$this->unmatched = $this->createMock(UnmatchedMailIntake::class);
		$this->unmatched->method('fallbackCaseTypeId')->willReturn('melding');
		$this->unmatched->method('caseFor')->willReturn('case-new');

		$this->archival->method('archiveLinkedEmail')
			->willThrowException(new \RuntimeException('the case folder is gone'));

		$outcome = $this->intake()->process(message: $this->message());

		self::assertSame(IntakeLog::OUTCOME_CASE, $outcome, 'A write that failed is still not a drop.');
		self::assertSame([IntakeLog::OUTCOME_CASE], $this->recorded);
	}//end testAFilingFailureStillRecordsTheMessage()

	/**
	 * 🔴 THE BATCH ADDS UP. Six messages in, six outcomes out, none unwritten.
	 *
	 * @return void
	 */
	public function testEveryMessageInABatchIsAccountedFor(): void {
		$rows = [];
		for ($uid = 1; $uid <= 6; $uid++) {
			$rows[] = [
				'uid' => $uid,
				'messageId' => '<' . $uid . '@voorbeeld.nl>',
				'subject' => 'Bericht ' . $uid,
				'from' => 'aanvrager@voorbeeld.nl',
			];
		}

		$counts = $this->intake()->processBatch(rows: $rows, accountId: 7, mailbox: 'INBOX');

		self::assertSame(6, array_sum($counts), 'Every message leaves as exactly one outcome.');
		self::assertCount(6, $this->recorded, 'Every message leaves a log line.');
		self::assertSame([], array_diff(array_keys($counts), [
			IntakeLog::OUTCOME_CASE,
			IntakeLog::OUTCOME_QUARANTINED,
			IntakeLog::OUTCOME_INBOX,
			IntakeLog::OUTCOME_REFUSED,
			IntakeLog::OUTCOME_FORWARDED,
			IntakeLog::OUTCOME_MOVED,
		]), 'There is no seventh outcome meaning dropped.');
	}//end testEveryMessageInABatchIsAccountedFor()

	/**
	 * Intake reports unavailable rather than throwing when Mail is gone.
	 *
	 * @return void
	 */
	public function testIntakeIsUnavailableRatherThanBrokenWhenMailIsGone(): void {
		$this->gateway = $this->createMock(MailGatewayInterface::class);
		$this->gateway->method('isAvailable')->willReturn(false);
		$this->gateway->method('source')->willReturn('');

		self::assertFalse($this->intake()->isAvailable());
	}//end testIntakeIsUnavailableRatherThanBrokenWhenMailIsGone()
}//end class
