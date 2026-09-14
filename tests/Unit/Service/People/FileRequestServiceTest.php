<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\People;

use OCA\Dossiq\Service\People\FileRequestService;
use OCA\Dossiq\Service\People\PersonLinkReader;
use OCA\Dossiq\Service\Zaakdossier\DocumentProjectionService;
use OCP\Constants;
use OCP\Files\Folder;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A file request is an email share of the case folder, addressed to a party.
 *
 * @spec openspec/specs/people-on-the-case/spec.md#requirement-req-poc-005-a-file-request-shall-be-addressed-to-a-party-of-the-case
 */
class FileRequestServiceTest extends TestCase {

	/**
	 * The people on the case.
	 *
	 * @var PersonLinkReader&MockObject
	 */
	private PersonLinkReader&MockObject $people;

	/**
	 * The case folder.
	 *
	 * @var DocumentProjectionService&MockObject
	 */
	private DocumentProjectionService&MockObject $folders;

	/**
	 * The share manager.
	 *
	 * @var IShareManager&MockObject
	 */
	private IShareManager&MockObject $shares;

	/**
	 * The share the service built.
	 *
	 * @var IShare&MockObject
	 */
	private IShare&MockObject $share;

	/**
	 * What was set on the share.
	 *
	 * @var array<string, mixed>
	 */
	private array $set = [];

	/**
	 * The service under test.
	 *
	 * @var FileRequestService
	 */
	private FileRequestService $service;

	/**
	 * Build the service on doubles, recording what the share is told.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->people = $this->createMock(originalClassName: PersonLinkReader::class);
		$this->people->method('emailOf')->willReturnCallback(
			static fn (array $link): string => trim((string)($link['email'] ?? ''))
		);
		$this->people->method('nameOf')->willReturnCallback(
			static fn (array $link): string => trim((string)($link['displayName'] ?? ''))
		);
		$this->folders = $this->createMock(originalClassName: DocumentProjectionService::class);

		$this->share = $this->createMock(originalClassName: IShare::class);
		foreach (
			[
				'setNode' => 'node',
				'setShareType' => 'shareType',
				'setSharedWith' => 'sharedWith',
				'setSharedBy' => 'sharedBy',
				'setShareOwner' => 'shareOwner',
				'setPermissions' => 'permissions',
				'setExpirationDate' => 'expires',
				'setNote' => 'note',
			] as $setter => $key
		) {
			$this->share->method($setter)->willReturnCallback(
				function (mixed $value) use ($key): IShare {
					$this->set[$key] = $value;
					return $this->share;
				}
			);
		}

		$this->share->method('getToken')->willReturn('tok-1');
		$this->shares = $this->createMock(originalClassName: IShareManager::class);
		$this->shares->method('newShare')->willReturn($this->share);

		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('behandelaar');
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$this->service = new FileRequestService(
			people: $this->people,
			folders: $this->folders,
			shares: $this->shares,
			userSession: $session,
		);
	}//end setUp()

	/**
	 * Put a party and a case folder in place.
	 *
	 * @param string $email The party's address.
	 *
	 * @return void
	 */
	private function party(string $email = 'piet@example.nl'): void {
		$this->people->method('personOn')->willReturn(
			['contactUid' => 'contact-8', 'displayName' => 'Piet Pietersen', 'email' => $email]
		);
		$owner = $this->createMock(originalClassName: IUser::class);
		$owner->method('getUID')->willReturn('admin');
		$folder = $this->createMock(originalClassName: Folder::class);
		$folder->method('getOwner')->willReturn($owner);
		$this->folders->method('folderOf')->willReturn($folder);
	}//end party()

	/**
	 * The request is an email share of the case folder that may only create.
	 *
	 * @return void
	 */
	public function testTheRequestIsACreateOnlyEmailShareOfTheCaseFolder(): void {
		$this->party();
		$this->shares->expects($this->once())->method('createShare')->with($this->share)->willReturn($this->share);

		$sent = $this->service->request(caseId: 'case-1', personUid: 'contact-8', note: 'The lease, please', days: 7);

		$this->assertSame(expected: 'piet@example.nl', actual: $this->set['sharedWith']);
		$this->assertSame(expected: IShare::TYPE_EMAIL, actual: $this->set['shareType']);
		$this->assertSame(expected: Constants::PERMISSION_CREATE, actual: $this->set['permissions']);
		$this->assertSame(expected: 'behandelaar', actual: $this->set['sharedBy']);
		$this->assertSame(expected: 'admin', actual: $this->set['shareOwner']);
		$this->assertSame(expected: 'The lease, please', actual: $this->set['note']);
		$this->assertSame(expected: 'piet@example.nl', actual: $sent['recipient']);
		$this->assertSame(expected: 'Piet Pietersen', actual: $sent['recipientName']);
		$this->assertSame(expected: 'tok-1', actual: $sent['token']);
		$this->assertSame(
			expected: (new \DateTime('+7 days'))->format('Y-m-d'),
			actual: $sent['expiresAt'],
		);
	}//end testTheRequestIsACreateOnlyEmailShareOfTheCaseFolder()

	/**
	 * No party, no request.
	 *
	 * @return void
	 */
	public function testSomebodyWhoIsNotOnTheCaseIsRefused(): void {
		$this->people->method('personOn')->willReturn(null);
		$this->shares->expects($this->never())->method('createShare');

		try {
			$this->service->request(caseId: 'case-1', personUid: 'stranger');
			$this->fail(message: 'a person who is not on the case must be refused');
		} catch (RuntimeException $e) {
			$this->assertSame(expected: 404, actual: $e->getCode());
		}
	}//end testSomebodyWhoIsNotOnTheCaseIsRefused()

	/**
	 * A party with no address cannot be asked, and the reason says so.
	 *
	 * @return void
	 */
	public function testAPartyWithoutAnAddressIsRefusedWithTheReason(): void {
		$this->party(email: '');
		$this->shares->expects($this->never())->method('createShare');

		try {
			$this->service->request(caseId: 'case-1', personUid: 'contact-8');
			$this->fail(message: 'a party with no address must be refused');
		} catch (RuntimeException $e) {
			$this->assertSame(expected: 422, actual: $e->getCode());
			$this->assertStringContainsString(needle: 'no email address', haystack: $e->getMessage());
		}
	}//end testAPartyWithoutAnAddressIsRefusedWithTheReason()

	/**
	 * A case with no folder has nowhere to upload to.
	 *
	 * @return void
	 */
	public function testACaseWithoutAFolderIsRefused(): void {
		$this->people->method('personOn')->willReturn(
			['contactUid' => 'contact-8', 'displayName' => 'Piet', 'email' => 'piet@example.nl']
		);
		$this->folders->method('folderOf')->willReturn(null);
		$this->shares->expects($this->never())->method('createShare');

		try {
			$this->service->request(caseId: 'case-1', personUid: 'contact-8');
			$this->fail(message: 'a case with no folder must be refused');
		} catch (RuntimeException $e) {
			$this->assertSame(expected: 422, actual: $e->getCode());
		}
	}//end testACaseWithoutAFolderIsRefused()

	/**
	 * No days named: the request stands for the default fortnight, capped at a year.
	 *
	 * @return void
	 */
	public function testTheRequestStandsForTheDefaultWhenNoDaysAreNamed(): void {
		$this->party();
		$this->shares->method('createShare')->willReturn($this->share);

		$sent = $this->service->request(caseId: 'case-1', personUid: 'contact-8');
		$this->assertSame(
			expected: (new \DateTime('+' . FileRequestService::DEFAULT_DAYS . ' days'))->format('Y-m-d'),
			actual: $sent['expiresAt'],
		);

		$capped = $this->service->request(caseId: 'case-1', personUid: 'contact-8', days: 4000);
		$this->assertSame(
			expected: (new \DateTime('+365 days'))->format('Y-m-d'),
			actual: $capped['expiresAt'],
		);
	}//end testTheRequestStandsForTheDefaultWhenNoDaysAreNamed()

	/**
	 * Nobody signed in: no share, and the caller is told why.
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerCannotAsk(): void {
		$this->party();
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$service = new FileRequestService(
			people: $this->people,
			folders: $this->folders,
			shares: $this->shares,
			userSession: $session,
		);
		$this->shares->expects($this->never())->method('createShare');

		try {
			$service->request(caseId: 'case-1', personUid: 'contact-8');
			$this->fail(message: 'an anonymous caller must be refused');
		} catch (RuntimeException $e) {
			$this->assertSame(expected: 401, actual: $e->getCode());
		}
	}//end testAnAnonymousCallerCannotAsk()

	/**
	 * A share the manager refuses comes back as a failure naming the reason,
	 * not as a request the handler believes was sent.
	 *
	 * @return void
	 */
	public function testAShareTheManagerRefusesIsReported(): void {
		$this->party();
		$this->shares->method('createShare')->willThrowException(new RuntimeException('sharing by mail is disabled'));

		try {
			$this->service->request(caseId: 'case-1', personUid: 'contact-8');
			$this->fail(message: 'a refused share must be reported');
		} catch (RuntimeException $e) {
			$this->assertSame(expected: 500, actual: $e->getCode());
			$this->assertStringContainsString(needle: 'sharing by mail is disabled', haystack: $e->getMessage());
		}
	}//end testAShareTheManagerRefusesIsReported()

	/**
	 * With no note the share carries none, rather than an empty one.
	 *
	 * @return void
	 */
	public function testNoNoteMeansNoNoteOnTheShare(): void {
		$this->party();
		$this->shares->method('createShare')->willReturn($this->share);
		$this->share->expects($this->never())->method('setNote');

		$this->service->request(caseId: 'case-1', personUid: 'contact-8');
	}//end testNoNoteMeansNoNoteOnTheShare()
}//end class
