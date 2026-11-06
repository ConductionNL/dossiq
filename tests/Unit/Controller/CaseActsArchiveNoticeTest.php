<?php

/**
 * Archiving a case tells the requester only when the handler asked for it.
 *
 * Board DqArchiveren draws "Sanne de Vries laten weten dat de zaak is
 * afgehandeld" under the reason. The act posts it as `notifyRequester`; the
 * answer then carries whether the requester was told, in the same keys an
 * extension answers with.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\CaseActsController;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\Lifecycle\ArchiveRequesterNotice;
use OCA\Dossiq\Service\Lifecycle\CaseActs;
use OCA\Dossiq\Service\Lifecycle\CaseActsOverview;
use OCA\Dossiq\Service\Lifecycle\CaseEndingActs;
use OCA\Dossiq\Service\Lifecycle\CaseHoldActs;
use OCA\Dossiq\Service\Lifecycle\CaseIncompleteness;
use OCA\Dossiq\Service\Lifecycle\DraftCaseActs;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Controller\CaseActsController
 * @uses \OCA\Dossiq\Service\Lifecycle\CaseActs
 * @uses \OCA\Dossiq\Exception\RefusedException
 */
class CaseActsArchiveNoticeTest extends TestCase {

	/**
	 * What the archive act answers.
	 *
	 * @var array<string, mixed>
	 */
	private const ARCHIVED = ['caseId' => 'case-1', 'act' => 'archive', 'archiveStatus' => 'archived'];

	/**
	 * Finish, abort and archive.
	 *
	 * @var CaseEndingActs&MockObject
	 */
	private CaseEndingActs $endings;

	/**
	 * The notice to the requester.
	 *
	 * @var ArchiveRequesterNotice&MockObject
	 */
	private ArchiveRequesterNotice $notice;

	/**
	 * Doubles for every act and a signed-in handler.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->endings = $this->createMock(originalClassName: CaseEndingActs::class);
		$this->notice = $this->createMock(originalClassName: ArchiveRequesterNotice::class);
	}//end setUp()

	/**
	 * The controller over a request carrying the given params.
	 *
	 * @param array<string, mixed> $params The request params.
	 * @param bool                 $wired  Whether the notice is wired.
	 *
	 * @return CaseActsController The controller.
	 */
	private function controller(array $params, bool $wired = true): CaseActsController {
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);

		$guard = $this->createMock(originalClassName: CaseAccessGuard::class);
		$guard->method('hasCaseMutationAccess')->willReturn(true);
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('ahmed');
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$notice = null;
		if ($wired === true) {
			$notice = $this->notice;
		}

		return new CaseActsController(
			appName: 'dossiq',
			request: $request,
			acts: new CaseActs(
				endings: $this->endings,
				holds: $this->createMock(originalClassName: CaseHoldActs::class),
				drafts: $this->createMock(originalClassName: DraftCaseActs::class),
				incompleteness: $this->createMock(originalClassName: CaseIncompleteness::class),
			),
			overview: $this->createMock(originalClassName: CaseActsOverview::class),
			caseAccessGuard: $guard,
			userSession: $session,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			archiveNotice: $notice,
		);
	}//end controller()

	/**
	 * Asked to, the act archives first and then answers whether the requester was told.
	 *
	 * @return void
	 */
	public function testAskedToTheArchiveAnswersWhetherTheRequesterWasTold(): void {
		$this->endings->expects($this->once())->method('archive')->willReturn(self::ARCHIVED);
		$told = ['noticeStatus' => 'sent', 'noticeChannel' => 'portal-inbox', 'noticeReasonCode' => '', 'noticeReason' => ''];
		$this->notice->expects($this->once())->method('tell')->with('case-1')->willReturn($told);

		$response = $this->controller(params: ['reason' => 'Dossier compleet', 'notifyRequester' => true])
			->archive(caseId: 'case-1');

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: 'archived', actual: $response->getData()['archiveStatus']);
		$this->assertSame(expected: $told, actual: $response->getData()['notice']);
	}//end testAskedToTheArchiveAnswersWhetherTheRequesterWasTold()

	/**
	 * A form posts its checkbox as text; "true" and "1" are a yes as well.
	 *
	 * @return void
	 */
	public function testATextualYesIsAYes(): void {
		$this->endings->method('archive')->willReturn(self::ARCHIVED);
		$this->notice->expects($this->exactly(count: 2))->method('tell')->willReturn(['noticeStatus' => 'sent']);

		$this->controller(params: ['reason' => 'x', 'notifyRequester' => 'true'])->archive(caseId: 'case-1');
		$this->controller(params: ['reason' => 'x', 'notifyRequester' => '1'])->archive(caseId: 'case-1');
	}//end testATextualYesIsAYes()

	/**
	 * Not asked, nobody is told and the answer carries no notice.
	 *
	 * @return void
	 */
	public function testNotAskedNobodyIsTold(): void {
		$this->endings->method('archive')->willReturn(self::ARCHIVED);
		$this->notice->expects($this->never())->method('tell');

		$response = $this->controller(params: ['reason' => 'x', 'notifyRequester' => false])->archive(caseId: 'case-1');

		$this->assertArrayNotHasKey(key: 'notice', array: $response->getData());
	}//end testNotAskedNobodyIsTold()

	/**
	 * Without the notice wired the archive still answers, and says nobody was told.
	 *
	 * @return void
	 */
	public function testWithoutTheNoticeWiredTheAnswerSaysNobodyWasTold(): void {
		$this->endings->method('archive')->willReturn(self::ARCHIVED);

		$response = $this->controller(params: ['reason' => 'x', 'notifyRequester' => true], wired: false)
			->archive(caseId: 'case-1');

		$this->assertSame(expected: 'not-sent', actual: $response->getData()['notice']['noticeStatus']);
		$this->assertSame(expected: 'notice-not-wired', actual: $response->getData()['notice']['noticeReasonCode']);
	}//end testWithoutTheNoticeWiredTheAnswerSaysNobodyWasTold()

	/**
	 * A refused archive tells nobody that the case is completed.
	 *
	 * @return void
	 */
	public function testARefusedArchiveTellsNobody(): void {
		$this->endings->method('archive')->willThrowException(
			new RefusedException(
				rule: 'case-not-ended',
				sentence: 'Finish or abort this case before archiving it.',
				status: RefusedException::STATUS_REFUSED,
			)
		);
		$this->notice->expects($this->never())->method('tell');

		$response = $this->controller(params: ['reason' => 'x', 'notifyRequester' => true])->archive(caseId: 'case-1');

		$this->assertSame(expected: 'case-not-ended', actual: $response->getData()['error']);
	}//end testARefusedArchiveTellsNobody()
}//end class
