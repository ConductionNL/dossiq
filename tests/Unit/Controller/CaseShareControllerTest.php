<?php

/**
 * CaseShareController and CaseShareList tests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\CaseShareController;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\Notice\CaseActNotices;
use OCA\Dossiq\Service\Sharing\CaseLinkShares;
use OCA\Dossiq\Service\Sharing\CaseShareList;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The shares of a case are read and revoked behind the case guard.
 *
 * @covers \OCA\Dossiq\Controller\CaseShareController
 * @covers \OCA\Dossiq\Service\Sharing\CaseShareList
 */
class CaseShareControllerTest extends TestCase {

	/**
	 * Case guard mock.
	 *
	 * @var CaseAccessGuard|MockObject
	 */
	private CaseAccessGuard $access;

	/**
	 * Link shares mock.
	 *
	 * @var CaseLinkShares|MockObject
	 */
	private CaseLinkShares $linkShares;

	/**
	 * Build the controller for user `anouk`.
	 *
	 * @return CaseShareController
	 */
	private function controller(): CaseShareController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('anouk');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		$l10n->method('n')->willReturnCallback(static fn (string $one, string $more, int $count): string => str_replace('%n', (string)$count, ($count === 1) ? $one : $more));
		$this->access     = $this->createMock(CaseAccessGuard::class);
		$this->linkShares = $this->createMock(CaseLinkShares::class);

		return new CaseShareController(
			request: $this->createMock(IRequest::class),
			caseAccess: $this->access,
			shares: new CaseShareList(linkShares: $this->linkShares, l10n: $l10n),
			linkShares: $this->linkShares,
			userSession: $session,
			notices: new CaseActNotices(l10n: $l10n),
		);
	}//end controller()

	/**
	 * The list maps each share and marks only the reader's own live share revocable.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-page-answers-in-card-notices/specs/case-share-via-shares-leaf/spec.md#requirement-the-case-lists-its-shares-and-a-refused-revoke-is-a-card-notice
	 */
	public function testTheListMapsEachShare(): void {
		$controller = $this->controller();
		$this->access->method('hasCaseReadAccess')->willReturn(true);
		$this->linkShares->method('listForCase')->with('case-1')->willReturn(
			[
				['accessLinkId' => 7, 'state' => 'live', 'createdBy' => 'anouk', 'label' => '', 'sharedDocuments' => '["d1","d2"]', 'accessLinkUrl' => 'https://x/s/7', 'expiresAt' => '2026-12-01T00:00:00Z'],
				['accessLinkId' => 8, 'state' => 'live', 'createdBy' => 'pieter', 'label' => 'Voor de advocaat', 'sharedDocuments' => '[]'],
				['accessLinkId' => 9, 'state' => 'revoked', 'createdBy' => 'anouk'],
				['accessLinkId' => 0, 'state' => 'live'],
			]
		);

		$rows = $controller->index(caseId: 'case-1')->getData()['results'];

		self::assertCount(3, $rows);
		self::assertSame(['id' => '7', 'kind' => 'file', 'title' => 'Link to 2 documents', 'meta' => 'by anouk · until 2026-12-01', 'status' => 'active', 'url' => 'https://x/s/7', 'link' => 'https://x/s/7', 'revocable' => true], $rows[0]);
		self::assertSame('case', $rows[1]['kind']);
		self::assertSame('Voor de advocaat', $rows[1]['title']);
		self::assertFalse($rows[1]['revocable']);
		self::assertSame('revoked', $rows[2]['status']);
		self::assertFalse($rows[2]['revocable']);
	}//end testTheListMapsEachShare()

	/**
	 * A reader without case access gets 403 and no share is read.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-page-answers-in-card-notices/specs/case-share-via-shares-leaf/spec.md#requirement-the-case-lists-its-shares-and-a-refused-revoke-is-a-card-notice
	 */
	public function testTheListIsRefusedWithoutCaseAccess(): void {
		$controller = $this->controller();
		$this->access->method('hasCaseReadAccess')->willReturn(false);
		$this->linkShares->expects($this->never())->method('listForCase');

		self::assertSame(Http::STATUS_FORBIDDEN, $controller->index(caseId: 'case-1')->getStatus());
	}//end testTheListIsRefusedWithoutCaseAccess()

	/**
	 * A share of another case is refused before anything is revoked.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-page-answers-in-card-notices/specs/case-share-via-shares-leaf/spec.md#requirement-the-case-lists-its-shares-and-a-refused-revoke-is-a-card-notice
	 */
	public function testAShareOfAnotherCaseIsNotRevoked(): void {
		$controller = $this->controller();
		$this->access->method('hasCaseReadAccess')->willReturn(true);
		$this->linkShares->method('belongsToCase')->with(7, 'case-1')->willReturn(false);
		$this->linkShares->expects($this->never())->method('revokeLink');

		self::assertSame(Http::STATUS_FORBIDDEN, $controller->destroy(caseId: 'case-1', shareId: '7')->getStatus());
	}//end testAShareOfAnotherCaseIsNotRevoked()

	/**
	 * A revoke OpenRegister refuses is a 409 with a card notice.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-page-answers-in-card-notices/specs/case-share-via-shares-leaf/spec.md#requirement-the-case-lists-its-shares-and-a-refused-revoke-is-a-card-notice
	 */
	public function testARefusedRevokeAnswersWithANotice(): void {
		$controller = $this->controller();
		$this->access->method('hasCaseReadAccess')->willReturn(true);
		$this->linkShares->method('belongsToCase')->willReturn(true);
		$this->linkShares->method('revokeLink')->with(7, 'anouk')->willReturn(false);

		$response = $controller->destroy(caseId: 'case-1', shareId: '7');

		self::assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		self::assertSame('Revoking refused by OpenRegister:', $response->getData()['notice']['title']);
		self::assertSame('alert', $response->getData()['notice']['role']);
	}//end testARefusedRevokeAnswersWithANotice()

	/**
	 * An accepted revoke answers `{ revoked: true }`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-page-answers-in-card-notices/specs/case-share-via-shares-leaf/spec.md#requirement-the-case-lists-its-shares-and-a-refused-revoke-is-a-card-notice
	 */
	public function testARevokeIsDone(): void {
		$controller = $this->controller();
		$this->access->method('hasCaseReadAccess')->willReturn(true);
		$this->linkShares->method('belongsToCase')->willReturn(true);
		$this->linkShares->method('revokeLink')->willReturn(true);

		self::assertSame(['revoked' => true], $controller->destroy(caseId: 'case-1', shareId: '7')->getData());
	}//end testARevokeIsDone()
}//end class
