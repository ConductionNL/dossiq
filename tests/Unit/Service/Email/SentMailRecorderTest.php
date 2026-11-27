<?php

/**
 * SentMailRecorder Unit Tests
 *
 * A sent mail is stored on its case first, then its line goes on the case
 * timeline as a public entry naming the stored document.
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
use OCA\Dossiq\Service\Email\SentMailRecorder;
use OCA\Dossiq\Service\Timeline\CaseTimeline;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SentMailRecorder.
 *
 * @covers \OCA\Dossiq\Service\Email\SentMailRecorder
 */
final class SentMailRecorderTest extends TestCase {

	/**
	 * The calls on the repository and the timeline, in order.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $calls = [];

	/**
	 * A recorder whose repository stores the mail under the given id.
	 *
	 * @param mixed $messageId What the repository answers for the stored mail.
	 *
	 * @return SentMailRecorder
	 */
	private function recorder(mixed $messageId): SentMailRecorder {
		$repository = $this->createMock(originalClassName: CaseEmailRepository::class);
		$repository->method('recordSentEmail')->willReturnCallback(
			function (...$args) use ($messageId) {
				$this->calls[] = ['document', ...$args];
				return $messageId;
			}
		);
		$timeline = $this->createMock(originalClassName: CaseTimeline::class);
		$timeline->method('record')->willReturnCallback(
			function (...$args): string {
				$this->calls[] = ['timeline', ...$args];
				return 'entry-1';
			}
		);

		return new SentMailRecorder(repository: $repository, timeline: $timeline);
	}//end recorder()

	/**
	 * The document is stored before the line, and the line names it.
	 *
	 * @return void
	 */
	public function testTheMailIsStoredThenItsLineNamesTheDocument(): void {
		$id = $this->recorder(messageId: 'msg-1')->record(
			caseId: 'case-1',
			fromAddress: 'zaken@gemeente.test',
			to: 'anna@example.test',
			subject: 'Uw aanvraag',
			body: 'Tekst',
			delivery: 'sent-not-filed',
		);

		$this->assertSame('msg-1', $id);
		$this->assertSame(
			[
				['document', 'case-1', 'zaken@gemeente.test', 'anna@example.test', 'Uw aanvraag', 'Tekst'],
				[
					'timeline',
					'case-1',
					'mail-uitgaand',
					'Uw aanvraag',
					[
						'recipient' => 'anna@example.test',
						'subject' => 'Uw aanvraag',
						'documentId' => 'msg-1',
						'delivery' => 'sent-not-filed',
						'sender' => 'zaken@gemeente.test',
					],
					'public',
					[],
				],
			],
			$this->calls
		);
	}//end testTheMailIsStoredThenItsLineNamesTheDocument()

	/**
	 * A mail whose sender is not known here is still stored and named on the line, with an empty sender.
	 *
	 * @return void
	 */
	public function testAnUnknownSenderIsRecordedEmpty(): void {
		$id = $this->recorder(messageId: 'msg-2')->record(
			caseId: 'case-2',
			fromAddress: '',
			to: 'b@example.test',
			subject: 'S',
			body: 'B',
			delivery: 'queued',
		);

		$this->assertSame('msg-2', $id);
		$this->assertSame('msg-2', $this->calls[1][4]['documentId']);
		$this->assertSame('queued', $this->calls[1][4]['delivery']);
		$this->assertSame('', $this->calls[1][4]['sender']);
	}//end testAnUnknownSenderIsRecordedEmpty()
}//end class
