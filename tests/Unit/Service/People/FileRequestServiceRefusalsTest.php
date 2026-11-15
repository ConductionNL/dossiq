<?php

/**
 * A file request asks pipelinq's refusal reader, when one is wired, before it sends.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\People
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
 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-dossiq-asks-before-it-sends-and-before-it-publishes-and-either-refusal-stops-it-req-plq-05
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\People;

use OCA\Dossiq\Service\People\FileRequestService;
use OCA\Dossiq\Service\People\PartyIndicatorReader;
use OCA\Dossiq\Service\People\PersonLinkReader;
use OCA\Dossiq\Service\Pipelinq\PartyRefusalReader;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Zaakdossier\DocumentProjectionService;
use OCP\Files\Folder;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for FileRequestService with a PartyRefusalReader wired.
 *
 * @covers \OCA\Dossiq\Service\People\FileRequestService
 * @uses \OCA\Dossiq\Service\People\PartyIndicatorReader
 * @uses \OCA\Dossiq\Service\People\UploadRequest
 */
class FileRequestServiceRefusalsTest extends TestCase {

	/**
	 * The share manager double.
	 *
	 * @var IShareManager&MockObject
	 */
	private IShareManager&MockObject $shares;

	/**
	 * The pipelinq refusal reader double.
	 *
	 * @var PartyRefusalReader&MockObject
	 */
	private PartyRefusalReader&MockObject $refusals;

	/**
	 * The service under test.
	 *
	 * @var FileRequestService
	 */
	private FileRequestService $service;

	/**
	 * A service whose one person on case-1 is a party with an email address.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$people = $this->createMock(PersonLinkReader::class);
		$people->method('personOn')->willReturn(['partyUuid' => ' party-7 ', 'email' => 'a@example.org', 'displayName' => 'A. Jansen']);
		$people->method('emailOf')->willReturnCallback(static fn (array $link): string => (string)$link['email']);
		$people->method('nameOf')->willReturnCallback(static fn (array $link): string => (string)$link['displayName']);

		$folders = $this->createMock(DocumentProjectionService::class);
		$folders->method('folderOf')->willReturn($this->createMock(Folder::class));

		$share = $this->createMock(IShare::class);
		$share->method('getToken')->willReturn('tok-9');
		$this->shares = $this->createMock(IShareManager::class);
		$this->shares->method('newShare')->willReturn($share);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('behandelaar');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getOpenRegisterClass')->willReturn(null);

		$this->refusals = $this->createMock(PartyRefusalReader::class);

		$this->service = new FileRequestService(
			people: $people,
			folders: $folders,
			indicators: new PartyIndicatorReader(settingsService: $settings, logger: $this->createMock(LoggerInterface::class)),
			shares: $this->shares,
			userSession: $session,
			refusals: $this->refusals,
		);
	}//end setUp()

	/**
	 * A refusal from the reader stops the request with its indicator, before any share exists.
	 *
	 * @return void
	 */
	public function testARefusalStopsTheRequest(): void {
		$this->refusals->expects(self::once())->method('maySendTo')->with('party-7')
			->willReturn(['refused' => true, 'sources' => ['pipelinq'], 'indicator' => 'Geen post']);
		$this->shares->expects(self::never())->method('createShare');

		try {
			$this->service->request(caseId: 'case-1', personUid: 'contact-7');
			self::fail('A refused party must not be sent a file request.');
		} catch (RuntimeException $e) {
			self::assertSame(403, $e->getCode());
			self::assertSame('A file request to A. Jansen is refused by the indicator "Geen post" on this party', $e->getMessage());
		}
	}//end testARefusalStopsTheRequest()

	/**
	 * No refusal lets the share be created.
	 *
	 * @return void
	 */
	public function testNoRefusalSendsTheRequest(): void {
		$this->refusals->expects(self::once())->method('maySendTo')->with('party-7')
			->willReturn(['refused' => false, 'sources' => [], 'indicator' => 'ignored']);
		$this->shares->expects(self::once())->method('createShare')->willReturnArgument(0);

		$before = (new \DateTime('+3 days'))->format('Y-m-d');
		$result = $this->service->request(caseId: 'case-1', personUid: 'contact-7', days: 3);
		$after = (new \DateTime('+3 days'))->format('Y-m-d');

		self::assertSame('a@example.org', $result['recipient']);
		self::assertSame('A. Jansen', $result['recipientName']);
		self::assertSame('tok-9', $result['token']);
		self::assertContains($result['expiresAt'], [$before, $after], 'The request expires after the asked number of days.');
	}//end testNoRefusalSendsTheRequest()

	/**
	 * A refusal without an indicator label does not stop the request.
	 *
	 * @return void
	 */
	public function testARefusalWithoutALabelDoesNotStopTheRequest(): void {
		$this->refusals->method('maySendTo')->willReturn(['refused' => true, 'sources' => ['openregister'], 'indicator' => '']);
		$this->shares->expects(self::once())->method('createShare')->willReturnArgument(0);

		self::assertSame('tok-9', $this->service->request(caseId: 'case-1', personUid: 'contact-7')['token']);
	}//end testARefusalWithoutALabelDoesNotStopTheRequest()
}//end class
