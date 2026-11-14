<?php

/**
 * InboundMailIntake edge-case unit tests
 *
 * One message that breaks does not stop the batch: it is logged and the rest
 * are counted. A message is read in with its raw source when Mail has one.
 * The policy is asked for the case type of the case a subject tag names,
 * whether that case type is stored as a reference or an expanded object, and
 * a case that cannot be read gets the default policy instead of stopping the
 * mailbox being emptied.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Email
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
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Email;

use OCA\Dossiq\Service\Email\AuthenticationResult;
use OCA\Dossiq\Service\Email\AuthenticationVerdict;
use OCA\Dossiq\Service\Email\CaseEmailRepository;
use OCA\Dossiq\Service\Email\Filters\FilterPipeline;
use OCA\Dossiq\Service\Email\Filters\FilterVerdict;
use OCA\Dossiq\Service\Email\InboundMailChecks;
use OCA\Dossiq\Service\Email\InboundMailFiling;
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
use RuntimeException;

/**
 * Edge cases of the intake path.
 *
 * @covers \OCA\Dossiq\Service\Email\InboundMailIntake
 * @uses \OCA\Dossiq\Service\Email\InboundMailChecks
 * @uses \OCA\Dossiq\Service\Email\InboundMailFiling
 * @uses \OCA\Dossiq\Service\Email\Filters\FilterVerdict
 * @uses \OCA\Dossiq\Service\Email\InboundMessage
 */
class InboundMailIntakeEdgesTest extends TestCase {

	/** @var MailGatewayInterface&MockObject */
	private MailGatewayInterface $gateway;

	/** @var CaseEmailRepository&MockObject */
	private CaseEmailRepository $cases;

	/** @var IntakePolicy&MockObject */
	private IntakePolicy $policy;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	/**
	 * The case types the policy was asked about.
	 *
	 * @var array<int, string>
	 */
	private array $askedCaseTypes = [];

	protected function setUp(): void {
		$this->askedCaseTypes = [];
		$this->gateway = $this->createMock(MailGatewayInterface::class);
		$this->cases = $this->createMock(CaseEmailRepository::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->policy = $this->createMock(IntakePolicy::class);
		$this->policy->method('forCaseType')->willReturnCallback(
			function (string $caseTypeId): string {
				$this->askedCaseTypes[] = $caseTypeId;
				return IntakePolicy::QUARANTINE;
			}
		);
		$this->policy->method('outcomeFor')->willReturn(IntakePolicy::QUARANTINE);
	}//end setUp()

	public function testOneBrokenMessageIsLoggedAndTheRestOfTheBatchIsCounted(): void {
		$this->gateway->method('source')->willReturnCallback(
			static function (int $accountId, string $mailbox, int $uid): string {
				if ($uid === 2) {
					throw new RuntimeException('IMAP fetch failed');
				}

				return '';
			}
		);
		$this->logger->expects($this->once())->method('error')->with(
			'Dossiq: intake failed on one message and the rest of the batch continues',
			['error' => 'IMAP fetch failed']
		);

		$counts = $this->intake()->processBatch(
			rows: [$this->row(uid: 1), $this->row(uid: 2), $this->row(uid: 3)],
			accountId: 7,
			mailbox: 'INBOX'
		);

		$this->assertSame([IntakeLog::OUTCOME_QUARANTINED => 2], $counts);
	}//end testOneBrokenMessageIsLoggedAndTheRestOfTheBatchIsCounted()

	public function testAMessageIsReadInWithItsRawSource(): void {
		$this->gateway->expects($this->once())->method('source')->with(7, 'INBOX', 4)->willReturn("Subject: x\r\n\r\nbody");

		$message = $this->intake()->hydrate(row: $this->row(uid: 4), accountId: 7, mailbox: 'INBOX');

		$this->assertSame("Subject: x\r\n\r\nbody", $message->source);
		$this->assertSame(4, $message->uid);
	}//end testAMessageIsReadInWithItsRawSource()

	public function testTheNamedCasesTypeIsAskedForWhetherStoredAsAReferenceOrAnObject(): void {
		$this->cases->method('findCaseIdByIdentifier')->willReturn('case-142');
		$this->cases->method('loadCaseRecord')->willReturnOnConsecutiveCalls(
			['caseType' => 'ct-melding'],
			['caseType' => ['id' => 'ct-bezwaar']],
			['title' => 'no type']
		);
		$intake = $this->intake();

		foreach ([1, 2, 3] as $uid) {
			$intake->process(message: $this->message(uid: $uid, subject: 'Re: [ZAAK-2026-000142] vraag'));
		}

		$this->assertSame(['ct-melding', 'ct-bezwaar', ''], $this->askedCaseTypes);
	}//end testTheNamedCasesTypeIsAskedForWhetherStoredAsAReferenceOrAnObject()

	public function testACaseThatCannotBeReadGetsTheDefaultPolicy(): void {
		$this->cases->method('findCaseIdByIdentifier')->willReturn('case-142');
		$this->cases->method('loadCaseRecord')->willThrowException(new RuntimeException('deleted'));
		$this->logger->expects($this->once())->method('debug')->with(
			'Dossiq: the case behind an intake policy could not be read',
			['case' => 'case-142', 'error' => 'deleted']
		);

		$outcome = $this->intake()->process(message: $this->message(uid: 1, subject: 'Re: [ZAAK-2026-000142] vraag'));

		$this->assertSame(IntakeLog::OUTCOME_QUARANTINED, $outcome);
		$this->assertSame([''], $this->askedCaseTypes);
	}//end testACaseThatCannotBeReadGetsTheDefaultPolicy()

	/**
	 * The intake over the test's collaborators and quiet defaults.
	 *
	 * @return InboundMailIntake
	 */
	private function intake(): InboundMailIntake {
		$pipeline = $this->createMock(FilterPipeline::class);
		$pipeline->method('run')->willReturn(FilterVerdict::accept());
		$verdicts = $this->createMock(AuthenticationVerdict::class);
		$verdicts->method('forMessage')->willReturn(
			['spf' => AuthenticationResult::PASS, 'dkim' => AuthenticationResult::PASS, 'dmarc' => AuthenticationResult::PASS, 'threading' => AuthenticationResult::PASS]
		);
		$threading = $this->createMock(ThreadingCheck::class);
		$threading->method('allowsSubjectTagLink')->willReturn(true);
		$log = $this->createMock(IntakeLog::class);
		$log->method('record')->willReturn('entry-1');
		$unmatched = $this->createMock(UnmatchedMailIntake::class);
		$unmatched->method('fallbackCaseTypeId')->willReturn('');

		return new InboundMailIntake(
			gateway: $this->gateway,
			checks: new InboundMailChecks(pipeline: $pipeline, verdicts: $verdicts, threading: $threading),
			policy: $this->policy,
			log: $log,
			filing: new InboundMailFiling(cases: $this->cases, unmatched: $unmatched, archival: $this->createMock(EmailArchivalService::class)),
			logger: $this->logger,
		);
	}//end intake()

	/**
	 * A gateway row.
	 *
	 * @param int $uid The uid.
	 *
	 * @return array<string, mixed>
	 */
	private function row(int $uid): array {
		return ['uid' => $uid, 'messageId' => '<' . $uid . '@voorbeeld.nl>', 'subject' => 'Bericht ' . $uid, 'from' => 'aanvrager@voorbeeld.nl'];
	}//end row()

	/**
	 * A message.
	 *
	 * @param int    $uid     The uid.
	 * @param string $subject The subject.
	 *
	 * @return InboundMessage
	 */
	private function message(int $uid, string $subject): InboundMessage {
		return new InboundMessage(accountId: 7, mailbox: 'INBOX', uid: $uid, messageId: '<' . $uid . '@voorbeeld.nl>', subject: $subject, from: 'aanvrager@voorbeeld.nl', to: 'postbus@gemeente.nl');
	}//end message()
}//end class
