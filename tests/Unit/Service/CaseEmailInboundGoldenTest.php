<?php

/**
 * CaseEmailService inbound golden tests.
 *
 * Pins what processInbound() answers for an email whose subject names a
 * case that exists, one that does not, and one that names none, and what it
 * records and logs for the linked one.
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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden tests for CaseEmailService::processInbound().
 *
 * @covers \OCA\Dossiq\Service\CaseEmailService
 */
class CaseEmailInboundGoldenTest extends TestCase {

	/**
	 * @var CaseEmailRepository&MockObject
	 */
	private $repository;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private $logger;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->repository = $this->createMock(CaseEmailRepository::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The service over the repository double.
	 *
	 * @return CaseEmailService
	 */
	private function service(): CaseEmailService {
		return new CaseEmailService(
			$this->logger,
			$this->repository,
			$this->createMock(CaseContactDirectory::class),
			(new \ReflectionClass(OutboundMailRules::class))->newInstanceWithoutConstructor(),
			$this->createMock(CaseTimeline::class)
		);
	}//end service()

	/**
	 * An email naming a case that exists is recorded on it and logged.
	 *
	 * @return void
	 */
	public function testAnEmailNamingAKnownCaseIsLinked(): void {
		$this->repository->expects($this->once())->method('findCaseIdByIdentifier')->with('2026-00042')->willReturn('case-7');
		$this->repository->expects($this->once())->method('recordReceivedEmail')
			->with('case-7', 'anna@example.test', 'zaken@gemeente.test', 'Re: [ZAAK-2026-00042] Vergunning', 'Hallo', '<m-1@example.test>')
			->willReturn('msg-9');
		$this->logger->expects($this->once())->method('info')->with('Inbound email auto-linked to case case-7', ['app' => 'dossiq']);

		$answer = $this->service()->processInbound('anna@example.test', 'zaken@gemeente.test', 'Re: [ZAAK-2026-00042] Vergunning', 'Hallo', '<m-1@example.test>');

		$this->assertSame(['linked' => true, 'caseId' => 'case-7', 'messageId' => 'msg-9', 'method' => 'auto'], $answer);
	}//end testAnEmailNamingAKnownCaseIsLinked()

	/**
	 * An email naming an unknown case is queued with the number it named.
	 *
	 * @return void
	 */
	public function testAnEmailNamingAnUnknownCaseIsUnlinked(): void {
		$this->repository->method('findCaseIdByIdentifier')->willReturn(null);
		$this->repository->expects($this->never())->method('recordReceivedEmail');
		$this->logger->expects($this->never())->method('info');

		$answer = $this->service()->processInbound('anna@example.test', 'zaken@gemeente.test', '[ZAAK-2026-0001] Vraag', 'Hallo');

		$this->assertSame(['linked' => false, 'caseNumber' => '2026-0001', 'from' => 'anna@example.test', 'subject' => '[ZAAK-2026-0001] Vraag', 'method' => 'unlinked'], $answer);
	}//end testAnEmailNamingAnUnknownCaseIsUnlinked()

	/**
	 * An email naming no case is queued without looking one up; the reply-to defaults to ''.
	 *
	 * @return void
	 */
	public function testAnEmailNamingNoCaseIsUnlinked(): void {
		$this->repository->expects($this->never())->method('findCaseIdByIdentifier');

		$answer = $this->service()->processInbound('anna@example.test', 'zaken@gemeente.test', 'Vraag over ZAAK-2026-0001', 'Hallo');

		$this->assertSame(['linked' => false, 'caseNumber' => null, 'from' => 'anna@example.test', 'subject' => 'Vraag over ZAAK-2026-0001', 'method' => 'unlinked'], $answer);
	}//end testAnEmailNamingNoCaseIsUnlinked()

	/**
	 * A linked email without a reply-to is recorded with ''.
	 *
	 * @return void
	 */
	public function testALinkedEmailWithoutAReplyToRecordsAnEmptyOne(): void {
		$this->repository->method('findCaseIdByIdentifier')->willReturn('case-7');
		$this->repository->expects($this->once())->method('recordReceivedEmail')
			->with('case-7', 'a@x.test', 'b@y.test', '[ZAAK-2026-0001]', 'B', '')
			->willReturn('msg-1');

		$this->assertSame('msg-1', $this->service()->processInbound('a@x.test', 'b@y.test', '[ZAAK-2026-0001]', 'B')['messageId']);
	}//end testALinkedEmailWithoutAReplyToRecordsAnEmptyOne()
}//end class
