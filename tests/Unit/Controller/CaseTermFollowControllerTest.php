<?php

/**
 * CaseTermFollowController tests.
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\CaseTermFollowController;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\Term\DependentTermOffer;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Controller\CaseTermFollowController
 */
class CaseTermFollowControllerTest extends TestCase {

	/** @var DependentTermOffer&MockObject */
	private DependentTermOffer $offers;

	/** @var CaseAccessGuard&MockObject */
	private CaseAccessGuard $guard;

	/** @var IUserSession&MockObject */
	private IUserSession $session;

	protected function setUp(): void {
		$this->offers = $this->createMock(DependentTermOffer::class);
		$this->guard = $this->createMock(CaseAccessGuard::class);
		$this->session = $this->createMock(IUserSession::class);
	}

	private function controller(): CaseTermFollowController {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new CaseTermFollowController('dossiq', $this->createMock(IRequest::class), $this->offers, $this->guard, $this->session, $l10n);
	}

	private function signIn(bool $handles): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('handler');
		$this->session->method('getUser')->willReturn($user);
		$this->guard->method('hasCaseMutationAccess')->with('case-1', $user)->willReturn($handles);

		return $user;
	}

	public function testAcceptRefusesAnonymousCaller(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->offers->expects($this->never())->method('accept');

		$response = $this->controller()->accept('case-1', 'task-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'You are not signed in.'], $response->getData());
	}

	public function testAcceptRefusesSomeoneWhoDoesNotHandleTheCase(): void {
		$this->signIn(false);
		$this->offers->expects($this->never())->method('accept');

		$response = $this->controller()->accept('case-1', 'task-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['error' => 'You do not handle this case.'], $response->getData());
	}

	public function testAcceptReturnsTheEventWithTheActor(): void {
		$this->signIn(true);
		$this->offers->expects($this->once())->method('accept')->with('task-1', 'handler')
			->willReturn(['event' => ['term' => 't-1', 'moved' => 3]]);

		$response = $this->controller()->accept('case-1', 'task-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['event' => ['term' => 't-1', 'moved' => 3]], $response->getData());
	}

	public function testAcceptWithoutAnEventReturnsAnEmptyOne(): void {
		$this->signIn(true);
		$this->offers->method('accept')->willReturn([]);

		$this->assertSame(['event' => []], $this->controller()->accept('case-1', 'task-1')->getData());
	}

	/**
	 * @return array<string, array{string, int, string}>
	 */
	public static function refusals(): array {
		return [
			'not a follow task' => ['not-a-term-follow-task', Http::STATUS_NOT_FOUND, 'That offer no longer exists.'],
			'term gone' => ['term-gone', Http::STATUS_NOT_FOUND, 'This case no longer has the term the offer was about.'],
			'no end date' => ['term-has-no-end-date', Http::STATUS_CONFLICT, 'This term has no end date to move.'],
			'extension refused' => ['extension-refused', Http::STATUS_CONFLICT, 'This term cannot be extended any further. Ask a supervisor.'],
			'unknown rule' => ['something-else', Http::STATUS_CONFLICT, 'The term was not moved.'],
		];
	}

	/**
	 * @dataProvider refusals
	 */
	public function testAcceptMapsEachRefusalToItsStatusAndSentence(string $rule, int $status, string $sentence): void {
		$this->signIn(true);
		$this->offers->method('accept')->willReturn(['refused' => $rule]);

		$response = $this->controller()->accept('case-1', 'task-1');

		$this->assertSame($status, $response->getStatus());
		$this->assertSame(['code' => $rule, 'error' => $sentence], $response->getData());
	}

	public function testDeclineRefusesSomeoneWhoDoesNotHandleTheCase(): void {
		$this->signIn(false);
		$this->offers->expects($this->never())->method('decline');

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller()->decline('case-1', 'task-1')->getStatus());
	}

	public function testDeclineReportsAGoneOfferAsNotFound(): void {
		$this->signIn(true);
		$this->offers->expects($this->once())->method('decline')->with('task-1', 'handler')->willReturn(false);

		$response = $this->controller()->decline('case-1', 'task-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['code' => 'not-a-term-follow-task', 'error' => 'That offer no longer exists.'], $response->getData());
	}

	public function testDeclineConfirmsTheDecline(): void {
		$this->signIn(true);
		$this->offers->method('decline')->willReturn(true);

		$response = $this->controller()->decline('case-1', 'task-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['declined' => true], $response->getData());
	}
}
