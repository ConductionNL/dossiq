<?php

/**
 * CaseEmailService sent-mail record golden tests.
 *
 * Pins what recordSentEmail() writes: the document record of the mail with
 * its fields, the one public timeline line with every field, and the id it
 * answers, for the default delivery and a named one.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
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

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\CaseEmailService;
use OCA\Dossiq\Service\Email\CaseContactDirectory;
use OCA\Dossiq\Service\Email\CaseEmailRepository;
use OCA\Dossiq\Service\Email\OutboundMailRules;
use OCA\Dossiq\Service\Timeline\CaseTimeline;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden tests for CaseEmailService::recordSentEmail().
 *
 * @covers \OCA\Dossiq\Service\CaseEmailService
 */
class CaseEmailSentRecordGoldenTest extends TestCase {

	/**
	 * Calls the doubles saw, in order.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $calls = [];

	/**
	 * The service over recording doubles; the repository answers this id.
	 *
	 * @param mixed $messageId What the repository answers.
	 *
	 * @return CaseEmailService
	 */
	private function service(mixed $messageId): CaseEmailService {
		$repository = $this->createMock(CaseEmailRepository::class);
		$repository->method('recordSentEmail')->willReturnCallback(
			function (...$args) use ($messageId) {
				$this->calls[] = ['document', ...$args];
				return $messageId;
			}
		);
		$timeline = $this->createMock(CaseTimeline::class);
		$timeline->method('record')->willReturnCallback(
			function (...$args): string {
				$this->calls[] = ['timeline', ...$args];
				return 'entry-1';
			}
		);

		return new CaseEmailService(
			$this->createMock(LoggerInterface::class),
			$repository,
			$this->createMock(CaseContactDirectory::class),
			(new \ReflectionClass(OutboundMailRules::class))->newInstanceWithoutConstructor(),
			$timeline
		);
	}//end service()

	/**
	 * The document, then the public line, then the id; the delivery defaults to sent.
	 *
	 * @return void
	 */
	public function testTheRecordTheLineAndTheId(): void {
		$id = $this->service('msg-7')->recordSentEmail('case-1', 'zaken@gemeente.test', 'anna@example.test', 'Uw aanvraag', 'Tekst');

		$this->assertSame('msg-7', $id);
		$this->assertSame(
			[
				['document', 'case-1', 'zaken@gemeente.test', 'anna@example.test', 'Uw aanvraag', 'Tekst'],
				[
					'timeline',
					'case-1',
					'mail-uitgaand',
					'Uw aanvraag',
					['recipient' => 'anna@example.test', 'subject' => 'Uw aanvraag', 'documentId' => 'msg-7', 'delivery' => 'sent', 'sender' => 'zaken@gemeente.test'],
					'public',
					[],
				],
			],
			$this->calls
		);
	}//end testTheRecordTheLineAndTheId()

	/**
	 * A named delivery is carried.
	 *
	 * @return void
	 */
	public function testANamedDelivery(): void {
		$id = $this->service('msg-8')->recordSentEmail('case-1', 'z@g.test', 'a@e.test', 'S', 'B', 'queued');

		$this->assertSame('msg-8', $id);
		$this->assertSame('queued', $this->calls[1][4]['delivery']);
		$this->assertSame('msg-8', $this->calls[1][4]['documentId']);
	}//end testANamedDelivery()
}//end class
