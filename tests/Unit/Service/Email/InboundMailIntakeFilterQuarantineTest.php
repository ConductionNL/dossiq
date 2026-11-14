<?php

/**
 * InboundMailIntake when a filter quarantines a message and when a subject tag names a case that does not exist.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
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

/**
 * @covers \OCA\Dossiq\Service\Email\InboundMailIntake
 * @uses   \OCA\Dossiq\Service\Email\InboundMailChecks
 * @uses   \OCA\Dossiq\Service\Email\InboundMailFiling
 * @uses   \OCA\Dossiq\Service\Email\InboundMessage
 * @uses   \OCA\Dossiq\Service\Email\Filters\FilterVerdict
 */
class InboundMailIntakeFilterQuarantineTest extends TestCase {

	/**
	 * The four authentication results every message gets here.
	 *
	 * @var array<string, string>
	 */
	private const RESULTS = [
		'spf' => AuthenticationResult::PASS,
		'dkim' => AuthenticationResult::FAIL,
		'dmarc' => AuthenticationResult::PASS,
		'threading' => AuthenticationResult::PASS,
	];

	/**
	 * The case lookup.
	 *
	 * @var CaseEmailRepository&MockObject
	 */
	private CaseEmailRepository $cases;

	/**
	 * The intake policy.
	 *
	 * @var IntakePolicy&MockObject
	 */
	private IntakePolicy $policy;

	/**
	 * The intake log.
	 *
	 * @var IntakeLog&MockObject
	 */
	private IntakeLog $log;

	/**
	 * Set up the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->cases  = $this->createMock(CaseEmailRepository::class);
		$this->policy = $this->createMock(IntakePolicy::class);
		$this->log    = $this->createMock(IntakeLog::class);
	}//end setUp()

	/**
	 * The intake with a pipeline that answers the given verdict.
	 *
	 * @param FilterVerdict $verdict The pipeline's verdict.
	 *
	 * @return InboundMailIntake
	 */
	private function intake(FilterVerdict $verdict): InboundMailIntake {
		$pipeline = $this->createMock(FilterPipeline::class);
		$pipeline->method('run')->willReturn($verdict);
		$verdicts = $this->createMock(AuthenticationVerdict::class);
		$verdicts->method('forMessage')->willReturn(self::RESULTS);
		$threading = $this->createMock(ThreadingCheck::class);
		$threading->method('allowsSubjectTagLink')->willReturn(true);
		$unmatched = $this->createMock(UnmatchedMailIntake::class);
		$unmatched->method('fallbackCaseTypeId')->willReturn('ct-fallback');

		return new InboundMailIntake(
			gateway: $this->createMock(MailGatewayInterface::class),
			checks: new InboundMailChecks(pipeline: $pipeline, verdicts: $verdicts, threading: $threading),
			policy: $this->policy,
			log: $this->log,
			filing: new InboundMailFiling(cases: $this->cases, unmatched: $unmatched, archival: $this->createMock(EmailArchivalService::class)),
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end intake()

	/**
	 * A message with the given subject.
	 *
	 * @param string $subject The subject.
	 *
	 * @return InboundMessage
	 */
	private function message(string $subject): InboundMessage {
		return new InboundMessage(accountId: 7, mailbox: 'INBOX', uid: 1, messageId: '<1@voorbeeld.nl>', subject: $subject, from: 'aanvrager@voorbeeld.nl', to: 'postbus@gemeente.nl');
	}//end message()

	/**
	 * A filter's quarantine is logged with the authentication results and its reason; no policy or case is consulted.
	 *
	 * @return void
	 */
	public function testAFilterQuarantineIsLoggedWithItsReason(): void {
		$verdict = FilterVerdict::quarantine(filterName: 'junk', reason: 'Looks like a newsletter');
		$message = $this->message(subject: 'Re: [ZAAK-2026-000142] vraag');
		$this->policy->expects($this->never())->method('forCaseType');
		$this->cases->expects($this->never())->method('findCaseIdByIdentifier');
		$this->log->expects($this->once())
			->method('record')
			->with(
				$message,
				$verdict,
				self::RESULTS,
				IntakeLog::OUTCOME_QUARANTINED,
				'Looks like a newsletter'
			)
			->willReturn('entry-1');

		self::assertSame(IntakeLog::OUTCOME_QUARANTINED, $this->intake(verdict: $verdict)->process(message: $message));
	}//end testAFilterQuarantineIsLoggedWithItsReason()

	/**
	 * A subject tag naming a case that does not exist is placed as unmatched mail, under the fallback case type.
	 *
	 * @return void
	 */
	public function testATagNamingAnUnknownCaseIsPlacedAsUnmatched(): void {
		$this->cases->expects($this->once())->method('findCaseIdByIdentifier')->with('2026-000142')->willReturn(null);
		$this->cases->expects($this->never())->method('loadCaseRecord');
		$this->policy->expects($this->once())->method('forCaseType')->with('ct-fallback')->willReturn(IntakePolicy::QUARANTINE);
		$this->policy->method('outcomeFor')->willReturn(IntakePolicy::QUARANTINE);
		$this->log->expects($this->once())
			->method('record')
			->with(
				$this->anything(),
				$this->anything(),
				self::RESULTS,
				IntakeLog::OUTCOME_QUARANTINED,
				$this->anything(),
				''
			)
			->willReturn('entry-1');

		$outcome = $this->intake(verdict: FilterVerdict::accept())->process(message: $this->message(subject: 'Re: [ZAAK-2026-000142] vraag'));

		self::assertSame(IntakeLog::OUTCOME_QUARANTINED, $outcome);
	}//end testATagNamingAnUnknownCaseIsPlacedAsUnmatched()
}//end class
