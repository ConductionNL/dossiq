<?php

/**
 * CaseTakeoverController unit tests: who may ask, and who may answer.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
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
 *
 * @spec openspec/specs/case-management/spec.md#requirement-a-colleague-may-ask-the-holder-for-a-case-req-cus-02
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\CaseTakeoverController;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\Custody\CaseTakeoverRequest;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Decision 168: answering a takeover is per-case mutation access; asking is read access.
 *
 * @covers \OCA\Dossiq\Controller\CaseTakeoverController
 */
class CaseTakeoverControllerTest extends TestCase {

	/**
	 * The takeover service double.
	 *
	 * @var CaseTakeoverRequest&MockObject
	 */
	private CaseTakeoverRequest $takeovers;

	/**
	 * The access guard double.
	 *
	 * @var CaseAccessGuard&MockObject
	 */
	private CaseAccessGuard $guard;

	/**
	 * The controller under test.
	 *
	 * @var CaseTakeoverController
	 */
	private CaseTakeoverController $controller;

	/**
	 * A signed-in caller who may READ case-1 but not change it.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->takeovers = $this->createMock(CaseTakeoverRequest::class);
		$this->guard = $this->createMock(CaseAccessGuard::class);
		$this->guard->method('hasCaseReadAccess')->willReturn(true);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('sofie');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($key === 'reason' ? 'Mijn wijk' : $default)
		);

		$this->controller = new CaseTakeoverController(
			'dossiq',
			$request,
			$this->takeovers,
			$this->guard,
			$session,
			$this->createMock(LoggerInterface::class),
		);
	}//end setUp()

	/**
	 * Read access is enough to ask: the asker does not hold the case.
	 *
	 * @return void
	 */
	public function testReadAccessMayAsk(): void {
		$this->guard->method('hasCaseMutationAccess')->willReturn(false);
		$this->takeovers->expects(self::once())->method('request')->willReturn(['id' => 'tk-1']);

		self::assertSame(Http::STATUS_OK, $this->controller->ask('case-1')->getStatus());
	}//end testReadAccessMayAsk()

	/**
	 * Without mutation access neither answer is given, and nothing reaches the service.
	 *
	 * @return void
	 */
	public function testWithoutMutationAccessNobodyAnswers(): void {
		$this->guard->method('hasCaseMutationAccess')->willReturn(false);
		$this->takeovers->expects(self::never())->method('accept');
		$this->takeovers->expects(self::never())->method('refuse');

		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller->accept('case-1', 'tk-1')->getStatus());
		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller->refuse('case-1', 'tk-1')->getStatus());
	}//end testWithoutMutationAccessNobodyAnswers()

	/**
	 * With mutation access the answer is given ON THE AUTHORISED CASE, so a request elsewhere is not reachable.
	 *
	 * @return void
	 */
	public function testTheAnswerIsBoundToTheAuthorisedCase(): void {
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->takeovers->expects(self::once())->method('accept')
			->with('tk-1', 'sofie', 'case-1')
			->willReturn(['status' => 'accepted']);
		$this->takeovers->expects(self::once())->method('refuse')
			->with('tk-1', 'Mijn wijk', 'sofie', 'case-1')
			->willReturn(['status' => 'refused']);

		self::assertSame(Http::STATUS_OK, $this->controller->accept('case-1', 'tk-1')->getStatus());
		self::assertSame(Http::STATUS_OK, $this->controller->refuse('case-1', 'tk-1')->getStatus());
	}//end testTheAnswerIsBoundToTheAuthorisedCase()
}//end class
