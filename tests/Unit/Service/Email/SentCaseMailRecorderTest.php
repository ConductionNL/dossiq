<?php

/**
 * SentCaseMailRecorder Unit Tests
 *
 * A sent case mail is stored as a case document first, then gets one public
 * timeline line naming the recipient, the subject, the stored document, the
 * delivery and the sender; the stored id is answered.
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
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Email;

use OCA\Dossiq\Service\Email\CaseEmailRepository;
use OCA\Dossiq\Service\Email\SentCaseMailRecorder;
use OCA\Dossiq\Service\Timeline\CaseTimeline;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SentCaseMailRecorder.
 *
 * @covers \OCA\Dossiq\Service\Email\SentCaseMailRecorder
 * @uses \OCA\Dossiq\Service\Email\SentCaseMail
 */
final class SentCaseMailRecorderTest extends TestCase {

	/**
	 * What the doubles saw, in order.
	 *
	 * @var array<int, array<int|string, mixed>>
	 */
	private array $calls = [];

	/**
	 * A recorder over recording doubles; the repository answers this id.
	 *
	 * @param string $messageId What the repository answers for the stored mail.
	 *
	 * @return SentCaseMailRecorder
	 */
	private function recorder(string $messageId): SentCaseMailRecorder {
		$repository = $this->createMock(originalClassName: CaseEmailRepository::class);
		$repository->method('recordSentEmail')->willReturnCallback(
			function (string $caseId, string $fromAddress, string $to, string $subject, string $body) use ($messageId): string {
				$this->calls[] = ['document', $caseId, $fromAddress, $to, $subject, $body];
				return $messageId;
			}
		);
		$timeline = $this->createMock(originalClassName: CaseTimeline::class);
		$timeline->method('record')->willReturnCallback(
			function (string $caseId, string $kind, string $message, array $fields, string $visibility): string {
				$this->calls[] = ['timeline', $caseId, $kind, $message, $fields, $visibility];
				return 'entry-1';
			}
		);

		return new SentCaseMailRecorder(repository: $repository, timeline: $timeline);
	}//end recorder()

	/**
	 * The document is stored first, then its public line, and the id is answered.
	 *
	 * @return void
	 */
	public function testTheDocumentThenThePublicLine(): void {
		$id = $this->recorder(messageId: 'msg-7')->record(
			caseId: 'case-1',
			fromAddress: 'zaken@gemeente.test',
			to: 'anna@example.test',
			subject: 'Uw aanvraag',
			body: 'Tekst'
		);

		$this->assertSame(expected: 'msg-7', actual: $id);
		$this->assertSame(
			expected: [
				['document', 'case-1', 'zaken@gemeente.test', 'anna@example.test', 'Uw aanvraag', 'Tekst'],
				[
					'timeline',
					'case-1',
					'mail-uitgaand',
					'Uw aanvraag',
					[
						'recipient' => 'anna@example.test',
						'subject' => 'Uw aanvraag',
						'documentId' => 'msg-7',
						'delivery' => 'sent',
						'sender' => 'zaken@gemeente.test',
					],
					CaseTimeline::PUBLIC_ENTRY,
				],
			],
			actual: $this->calls
		);
	}//end testTheDocumentThenThePublicLine()

	/**
	 * A named delivery and an unknown sender travel to the line as they were given.
	 *
	 * @return void
	 */
	public function testANamedDeliveryAndAnUnknownSender(): void {
		$id = $this->recorder(messageId: 'msg-8')->record(
			caseId: 'case-2',
			fromAddress: '',
			to: 'a@e.test',
			subject: 'S',
			body: 'B',
			delivery: 'sent-not-filed'
		);

		$this->assertSame(expected: 'msg-8', actual: $id);
		$this->assertSame(expected: 'sent-not-filed', actual: $this->calls[1][4]['delivery']);
		$this->assertSame(expected: 'msg-8', actual: $this->calls[1][4]['documentId']);
		$this->assertSame(expected: '', actual: $this->calls[1][4]['sender']);
	}//end testANamedDeliveryAndAnUnknownSender()
}//end class
