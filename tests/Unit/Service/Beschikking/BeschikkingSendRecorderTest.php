<?php

/**
 * Unit tests for BeschikkingSendRecorder: a delivered beschikking is a public
 * timeline entry; one that was not sent is an internal entry and a refusal,
 * which asks for an address when there was no channel at all.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Beschikking;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Beschikking\BeschikkingSendRecorder;
use OCA\Dossiq\Service\Beschikking\BeschikkingSendRun;
use OCA\Dossiq\Service\Timeline\CaseTimeline;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Beschikking\BeschikkingSendRecorder
 *
 * @uses \OCA\Dossiq\Service\Beschikking\BeschikkingSendRun
 * @uses \OCA\Dossiq\Exception\RefusedException
 */
class BeschikkingSendRecorderTest extends TestCase {

	private CaseTimeline&MockObject $timeline;

	protected function setUp(): void {
		parent::setUp();
		$this->timeline = $this->createMock(CaseTimeline::class);
	}//end setUp()

	private function sendRun(array $decision = [], array $dispatch = []): BeschikkingSendRun {
		$run = new BeschikkingSendRun(decisionId: 'dec-1', actor: 'alice');
		$run->decision = $decision;
		$run->dispatch = $dispatch;
		return $run;
	}//end run()

	public function testADeliveryIsAPublicTimelineEntry(): void {
		$this->timeline->expects($this->once())->method('record')->with(
			'case-1',
			'beschikking-verzonden',
			'Beschikking verzonden',
			['channel' => 'mijnoverheid', 'sentOn' => '2026-11-12', 'decisionType' => 'toekenning', 'reference' => 'B-42', 'beschikkingId' => 'dec-1'],
			'public'
		);

		(new BeschikkingSendRecorder($this->timeline))->delivered(
			$this->sendRun([], ['channel' => 'mijnoverheid']),
			['caseId' => ' case-1 ', 'announcementDate' => '2026-11-12', 'decisionType' => 'toekenning', 'reference' => 'B-42']
		);
	}//end testADeliveryIsAPublicTimelineEntry()

	public function testADeliveryWithoutACaseRecordsNothing(): void {
		$this->timeline->expects($this->never())->method('record');

		(new BeschikkingSendRecorder($this->timeline))->delivered($this->sendRun(), ['caseId' => '  ']);
	}//end testADeliveryWithoutACaseRecordsNothing()

	public function testAFailedSendIsAnInternalEntryAndAnIndeterminateRefusal(): void {
		$this->timeline->expects($this->once())->method('record')->with(
			'case-1',
			'beschikking-verzonden',
			'Beschikking niet verzonden',
			['reasonCode' => 'transport-down', 'reason' => 'The post service did not answer.', 'channelsTried' => 2, 'reference' => 'B-42', 'beschikkingId' => 'dec-1'],
			'internal'
		);

		$refusal = (new BeschikkingSendRecorder($this->timeline))->notSent(
			$this->sendRun(
				['caseId' => 'case-1', 'reference' => 'B-42'],
				['reasonCode' => 'transport-down', 'reason' => 'The post service did not answer.', 'channelsTried' => ['email', 'post']]
			)
		);

		$this->assertSame('beschikking-not-sent', $refusal->getRule());
		$this->assertSame('The post service did not answer.', $refusal->getSentence());
		$this->assertSame(RefusedException::STATUS_INDETERMINATE, $refusal->getStatus());
	}//end testAFailedSendIsAnInternalEntryAndAnIndeterminateRefusal()

	public function testNoChannelAsksForAnAddress(): void {
		$refusal = (new BeschikkingSendRecorder($this->timeline))->notSent($this->sendRun(['caseId' => 'case-1'], ['reasonCode' => 'no-channel']));

		$this->assertSame('beschikking-no-address', $refusal->getRule());
		$this->assertSame(RefusedException::STATUS_UNPROCESSABLE, $refusal->getStatus());
		$this->assertSame(
			'This case has no address for the requester, so the beschikking was not sent. Add an address, then send it again.',
			$refusal->getSentence()
		);
	}//end testNoChannelAsksForAnAddress()

	public function testAFailedSendWithoutDetailsOrCaseUsesTheDefaults(): void {
		$this->timeline->expects($this->never())->method('record');

		$refusal = (new BeschikkingSendRecorder($this->timeline))->notSent($this->sendRun());

		$this->assertSame('beschikking-not-sent', $refusal->getRule());
		$this->assertSame('No transport took the beschikking, so it was not sent.', $refusal->getSentence());
	}//end testAFailedSendWithoutDetailsOrCaseUsesTheDefaults()
}//end class
