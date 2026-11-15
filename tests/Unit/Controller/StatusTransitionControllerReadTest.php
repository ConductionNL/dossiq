<?php

/**
 * StatusTransitionController unit tests: the two read endpoints, available transitions and history.
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
 * @spec openspec/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\StatusTransitionController;
use OCA\Dossiq\Kernel\Contracts\CaseAccessGuardInterface;
use OCA\Dossiq\Kernel\Contracts\StatusTransitionServiceInterface;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Both reads need a session and read access on the case; an engine failure is a logged 500.
 *
 * @covers \OCA\Dossiq\Controller\StatusTransitionController
 */
class StatusTransitionControllerReadTest extends TestCase {

	/**
	 * The engine double.
	 *
	 * @var StatusTransitionServiceInterface&MockObject
	 */
	private StatusTransitionServiceInterface $engine;

	/**
	 * The session double.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession $session;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The guard double.
	 *
	 * @var CaseAccessGuardInterface&MockObject
	 */
	private CaseAccessGuardInterface $guard;

	/**
	 * Fresh doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->engine = $this->createMock(StatusTransitionServiceInterface::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->guard = $this->createMock(CaseAccessGuardInterface::class);
	}//end setUp()

	/**
	 * Build the controller.
	 *
	 * @return StatusTransitionController
	 */
	private function controller(): StatusTransitionController {
		return new StatusTransitionController(
			appName: 'dossiq',
			request: $this->createMock(IRequest::class),
			transitionEngine: $this->engine,
			userSession: $this->session,
			logger: $this->logger,
			caseAccessGuard: $this->guard,
		);
	}//end controller()

	/**
	 * Sign sofie in; she may read the case when $mayRead.
	 *
	 * @param bool $mayRead Whether the guard grants read access.
	 *
	 * @return void
	 */
	private function signIn(bool $mayRead): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('sofie');
		$this->session->method('getUser')->willReturn($user);
		$this->guard->method('hasCaseReadAccess')->with('case-1', $user)->willReturn($mayRead);
	}//end signIn()

	/**
	 * Without a session both reads are a 401.
	 *
	 * @return void
	 */
	public function testWithoutASessionBothReadsAreUnauthorised(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->guard->expects($this->never())->method('hasCaseReadAccess');
		$this->engine->expects($this->never())->method('getAvailableTransitions');
		$this->engine->expects($this->never())->method('replay');

		$available = $this->controller()->available(caseId: 'case-1');
		$history = $this->controller()->history(caseId: 'case-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $available->getStatus());
		$this->assertSame(['error' => 'Not authenticated'], $available->getData());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $history->getStatus());
	}//end testWithoutASessionBothReadsAreUnauthorised()

	/**
	 * Without read access both reads are a 403.
	 *
	 * @return void
	 */
	public function testWithoutReadAccessBothReadsAreForbidden(): void {
		$this->signIn(mayRead: false);
		$this->engine->expects($this->never())->method('getAvailableTransitions');
		$this->engine->expects($this->never())->method('replay');

		$available = $this->controller()->available(caseId: 'case-1');
		$history = $this->controller()->history(caseId: 'case-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $available->getStatus());
		$this->assertSame(['error' => 'Not authorized'], $available->getData());
		$this->assertSame(Http::STATUS_FORBIDDEN, $history->getStatus());
		$this->assertSame(['error' => 'Not authorized'], $history->getData());
	}//end testWithoutReadAccessBothReadsAreForbidden()

	/**
	 * A reader gets the engine's available transitions and its replayed history.
	 *
	 * @return void
	 */
	public function testAReaderGetsTransitionsAndHistory(): void {
		$this->signIn(mayRead: true);
		$this->engine->expects($this->once())->method('getAvailableTransitions')->with('case-1')
			->willReturn([['to' => 'in-behandeling']]);
		$this->engine->expects($this->once())->method('replay')->with('case-1')
			->willReturn([['from' => 'ontvangen', 'to' => 'in-behandeling']]);

		$this->assertSame([['to' => 'in-behandeling']], $this->controller()->available(caseId: 'case-1')->getData());
		$this->assertSame(
			[['from' => 'ontvangen', 'to' => 'in-behandeling']],
			$this->controller()->history(caseId: 'case-1')->getData()
		);
	}//end testAReaderGetsTransitionsAndHistory()

	/**
	 * An engine failure is logged with the case and answered 500 without its message.
	 *
	 * @return void
	 */
	public function testAnEngineFailureIsALogged500(): void {
		$this->signIn(mayRead: true);
		$this->engine->method('getAvailableTransitions')->willThrowException(new RuntimeException('schema gone'));
		$this->engine->method('replay')->willThrowException(new RuntimeException('audit gone'));
		$logged = [];
		$this->logger->expects($this->exactly(2))->method('error')
			->willReturnCallback(function (string $message, array $context) use (&$logged): void {
				$logged[] = [$message, $context];
			});

		$available = $this->controller()->available(caseId: 'case-1');
		$history = $this->controller()->history(caseId: 'case-1');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $available->getStatus());
		$this->assertSame(['error' => 'Could not load available transitions'], $available->getData());
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $history->getStatus());
		$this->assertSame(['error' => 'Could not load transition history'], $history->getData());
		$this->assertSame(
			[
				['StatusTransitionController: available failed', ['exception' => 'schema gone', 'caseId' => 'case-1']],
				['StatusTransitionController: history failed', ['exception' => 'audit gone', 'caseId' => 'case-1']],
			],
			$logged
		);
	}//end testAnEngineFailureIsALogged500()
}//end class
