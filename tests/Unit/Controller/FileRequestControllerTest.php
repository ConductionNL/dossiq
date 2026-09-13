<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\FileRequestController;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\People\FileRequestService;
use OCA\Dossiq\Service\People\PersonLinkReader;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Who can be asked for a file, and the asking.
 *
 * @spec openspec/specs/people-on-the-case/spec.md#requirement-req-poc-005-a-file-request-shall-be-addressed-to-a-party-of-the-case
 */
class FileRequestControllerTest extends TestCase {

	/**
	 * The people on the case.
	 *
	 * @var PersonLinkReader&MockObject
	 */
	private PersonLinkReader&MockObject $people;

	/**
	 * The sending.
	 *
	 * @var FileRequestService&MockObject
	 */
	private FileRequestService&MockObject $fileRequests;

	/**
	 * Whether the handler may see the case.
	 *
	 * @var CaseAccessGuard&MockObject
	 */
	private CaseAccessGuard&MockObject $access;

	/**
	 * The controller under test.
	 *
	 * @var FileRequestController
	 */
	private FileRequestController $controller;

	/**
	 * Build the controller on doubles, signed in.
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
		$this->fileRequests = $this->createMock(originalClassName: FileRequestService::class);

		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('behandelaar');
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$this->access = $this->createMock(originalClassName: CaseAccessGuard::class);
		$this->access->method('hasCaseReadAccess')->willReturn(true);
		$this->access->method('hasCaseMutationAccess')->willReturn(true);

		$this->controller = new FileRequestController(
			appName: 'dossiq',
			request: $this->createMock(originalClassName: IRequest::class),
			people: $this->people,
			fileRequests: $this->fileRequests,
			access: $this->access,
			userSession: $session,
		);
	}//end setUp()

	/**
	 * Every party is listed; the one without an address cannot be asked.
	 *
	 * @return void
	 */
	public function testEveryPartyIsListedAndOnlyOneCanBeAsked(): void {
		$this->people->method('peopleOn')->willReturn(
			[
				['contactUid' => 'user:jan', 'displayName' => 'Jan de Vries', 'email' => 'jan@example.nl', 'role' => 'rt-1', 'kind' => 'user'],
				['contactUid' => 'contact-8', 'displayName' => 'Piet', 'email' => '', 'role' => 'rt-2', 'kind' => 'contact'],
			]
		);

		$parties = $this->controller->parties(caseId: 'case-1')->getData()['parties'];

		$this->assertCount(expectedCount: 2, haystack: $parties);
		$this->assertTrue(condition: $parties[0]['canBeAsked']);
		$this->assertSame(expected: 'user', actual: $parties[0]['kind']);
		$this->assertFalse(condition: $parties[1]['canBeAsked'], message: 'no address, nobody to send to');
		$this->assertSame(expected: 'Piet', actual: $parties[1]['name']);
	}//end testEveryPartyIsListedAndOnlyOneCanBeAsked()

	/**
	 * A request names the party and answers what was sent.
	 *
	 * @return void
	 */
	public function testARequestIsSentToTheNamedParty(): void {
		$this->fileRequests->expects($this->once())
			->method('request')
			->with('case-1', 'user:jan', 'The lease', 7)
			->willReturn(['recipient' => 'jan@example.nl', 'token' => 'tok-1']);

		$response = $this->controller->create(caseId: 'case-1', personId: ' user:jan ', note: ' The lease ', days: 7);

		$this->assertSame(expected: Http::STATUS_CREATED, actual: $response->getStatus());
		$this->assertSame(expected: 'jan@example.nl', actual: $response->getData()['recipient']);
	}//end testARequestIsSentToTheNamedParty()

	/**
	 * A request naming nobody is a 400, and nothing is sent.
	 *
	 * @return void
	 */
	public function testARequestNamingNobodyIsRefused(): void {
		$this->fileRequests->expects($this->never())->method('request');

		$this->assertSame(
			expected: Http::STATUS_BAD_REQUEST,
			actual: $this->controller->create(caseId: 'case-1')->getStatus(),
		);
	}//end testARequestNamingNobodyIsRefused()

	/**
	 * The service's own refusal reaches the caller with its status and reason.
	 *
	 * @return void
	 */
	public function testTheServicesRefusalReachesTheCaller(): void {
		$this->fileRequests->method('request')->willThrowException(
			new RuntimeException('This person has no email address, so there is nobody to send the request to', 422)
		);

		$response = $this->controller->create(caseId: 'case-1', personId: 'contact-8');

		$this->assertSame(expected: 422, actual: $response->getStatus());
		$this->assertStringContainsString(needle: 'no email address', haystack: $response->getData()['error']);
	}//end testTheServicesRefusalReachesTheCaller()

	/**
	 * A failure with no HTTP code is a 500, not a 400 that reads like the caller's fault.
	 *
	 * @return void
	 */
	public function testAnUnexpectedFailureIsAServerError(): void {
		$this->fileRequests->method('request')->willThrowException(
			new RuntimeException('The file request could not be sent: smtp is down', 500)
		);

		$this->assertSame(
			expected: Http::STATUS_INTERNAL_SERVER_ERROR,
			actual: $this->controller->create(caseId: 'case-1', personId: 'user:jan')->getStatus(),
		);
	}//end testAnUnexpectedFailureIsAServerError()

	/**
	 * A handler who cannot see the case learns nothing about it: not who is on
	 * it, and not whether it exists.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/people-on-the-case/spec.md#requirement-req-poc-005-a-file-request-shall-be-addressed-to-a-party-of-the-case
	 */
	public function testACaseTheHandlerCannotSeeAnswersNotFound(): void {
		$access = $this->createMock(originalClassName: CaseAccessGuard::class);
		$access->method('hasCaseReadAccess')->willReturn(false);
		$access->method('hasCaseMutationAccess')->willReturn(false);
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('outsider');
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$controller = new FileRequestController(
			appName: 'dossiq',
			request: $this->createMock(originalClassName: IRequest::class),
			people: $this->people,
			fileRequests: $this->fileRequests,
			access: $access,
			userSession: $session,
		);
		$this->people->expects($this->never())->method('peopleOn');
		$this->fileRequests->expects($this->never())->method('request');

		$this->assertSame(
			expected: Http::STATUS_NOT_FOUND,
			actual: $controller->parties(caseId: 'case-1')->getStatus(),
		);
		$this->assertSame(
			expected: Http::STATUS_NOT_FOUND,
			actual: $controller->create(caseId: 'case-1', personId: 'user:jan')->getStatus(),
		);
	}//end testACaseTheHandlerCannotSeeAnswersNotFound()

	/**
	 * Nobody signed in: neither endpoint answers anything about the case.
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerIsRefusedByBothEndpoints(): void {
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$controller = new FileRequestController(
			appName: 'dossiq',
			request: $this->createMock(originalClassName: IRequest::class),
			people: $this->people,
			fileRequests: $this->fileRequests,
			access: $this->access,
			userSession: $session,
		);
		$this->people->expects($this->never())->method('peopleOn');
		$this->fileRequests->expects($this->never())->method('request');

		$this->assertSame(
			expected: Http::STATUS_UNAUTHORIZED,
			actual: $controller->parties(caseId: 'case-1')->getStatus(),
		);
		$this->assertSame(
			expected: Http::STATUS_UNAUTHORIZED,
			actual: $controller->create(caseId: 'case-1', personId: 'user:jan')->getStatus(),
		);
	}//end testAnAnonymousCallerIsRefusedByBothEndpoints()
}//end class
