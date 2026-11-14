<?php

/**
 * Unit tests for CaseRebindController.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\CaseRebindController;
use OCA\Dossiq\Controller\Support\CaseCaller;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\CaseRebindService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Who may change a running case's type, and what the endpoints answer.
 */
#[CoversClass(CaseRebindController::class)]
#[UsesClass(CaseCaller::class)]
#[UsesClass(RefusedException::class)]
final class CaseRebindControllerTest extends TestCase {

	/**
	 * Request parameters.
	 *
	 * @var array<string,mixed>
	 */
	private array $params = [];

	/**
	 * The rebind service.
	 *
	 * @var CaseRebindService&MockObject
	 */
	private CaseRebindService&MockObject $rebind;

	/**
	 * The case access guard.
	 *
	 * @var CaseAccessGuard&MockObject
	 */
	private CaseAccessGuard&MockObject $access;

	/**
	 * The session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $session;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The controller.
	 *
	 * @var CaseRebindController
	 */
	private CaseRebindController $controller;

	/**
	 * Wire the controller.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn (string $name, mixed $default = null): mixed => ($this->params[$name] ?? $default)
		);
		$this->rebind = $this->createMock(CaseRebindService::class);
		$this->access = $this->createMock(CaseAccessGuard::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->controller = new CaseRebindController(
			appName: 'dossiq',
			request: $request,
			rebind: $this->rebind,
			accessGuard: $this->access,
			userSession: $this->session,
			logger: $this->logger,
		);
	}//end setUp()

	/**
	 * Sign in a user who may or may not write the case.
	 *
	 * @param string|null $uid      The user, or null for nobody.
	 * @param bool        $mayWrite Whether the user may change the case.
	 *
	 * @return void
	 */
	private function signIn(?string $uid, bool $mayWrite = true): void {
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$this->session->method('getUser')->willReturn($user);
		$this->access->method('hasCaseMutationAccess')->willReturn($mayWrite);
	}//end signIn()

	/**
	 * permission asks the service about the caller, or about nobody.
	 *
	 * @return void
	 */
	public function testPermissionAsksAboutTheCaller(): void {
		$this->rebind->method('mayRebind')->willReturnCallback(static fn (string $uid): bool => $uid === 'coord');

		$this->signIn('coord');
		self::assertSame(['mayRebind' => true], $this->controller->permission()->getData());

		$this->setUp();
		$this->rebind->expects(self::once())->method('mayRebind')->with(uid: '')->willReturn(false);
		$this->signIn(null);
		self::assertSame(['mayRebind' => false], $this->controller->permission()->getData());
	}//end testPermissionAsksAboutTheCaller()

	/**
	 * Without write access to the case both endpoints answer 403 and touch nothing.
	 *
	 * @return void
	 */
	public function testACallerWhoCannotWriteTheCaseIsRefused(): void {
		$this->signIn('anna', mayWrite: false);
		$this->rebind->expects(self::never())->method('options');
		$this->rebind->expects(self::never())->method('rebind');

		foreach ([$this->controller->options('case-1'), $this->controller->rebind('case-1')] as $response) {
			self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
			self::assertSame('case-access-denied', $response->getData()['error']);
		}

		$this->setUp();
		$this->signIn(null);
		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller->options('case-1')->getStatus());
	}//end testACallerWhoCannotWriteTheCaseIsRefused()

	/**
	 * options is for coordinators only.
	 *
	 * @return void
	 */
	public function testOptionsIsForCoordinators(): void {
		$this->signIn('anna');
		$this->rebind->method('mayRebind')->willReturn(false);
		$this->rebind->expects(self::never())->method('options');

		$response = $this->controller->options('case-1');

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame('rebind-is-for-coordinators', $response->getData()['error']);
	}//end testOptionsIsForCoordinators()

	/**
	 * options lists the targets, and adds a preview when a target is asked for.
	 *
	 * @return void
	 */
	public function testOptionsAddsAPreviewForATarget(): void {
		$this->signIn('coord');
		$this->rebind->method('mayRebind')->willReturn(true);
		$this->rebind->method('options')->with(caseId: 'case-1')->willReturn(['targets' => ['t-1']]);

		self::assertSame(['targets' => ['t-1']], $this->controller->options('case-1')->getData());

		$this->params = ['target' => ' t-1 ', 'status' => ' s-2 ', 'remap' => ['a' => 'b'], 'properties' => 'not-a-list'];
		$this->rebind->expects(self::once())->method('preview')
			->with(caseId: 'case-1', targetCaseTypeId: 't-1', targetStatusId: 's-2', remap: ['a' => 'b'], properties: [])
			->willReturn(['dropped' => []]);

		self::assertSame(['targets' => ['t-1'], 'preview' => ['dropped' => []]], $this->controller->options('case-1')->getData());
	}//end testOptionsAddsAPreviewForATarget()

	/**
	 * rebind passes every parameter on, trimmed, with the caller as actor.
	 *
	 * @return void
	 */
	public function testRebindPassesTheRequestOn(): void {
		$this->signIn('anna');
		$this->params = [
			'target'         => ' t-9 ',
			'status'         => '',
			'reason'         => 'Wrong type chosen at intake',
			'properties'     => ['x' => 1],
			'remap'          => ['old' => 'new'],
			'confirmDropped' => ['b' => 'field-b'],
		];
		$this->rebind->expects(self::once())->method('rebind')->with(
			caseId: 'case-1',
			targetCaseTypeId: 't-9',
			targetStatusId: '',
			reason: 'Wrong type chosen at intake',
			properties: ['x' => 1],
			actorUid: 'anna',
			remap: ['old' => 'new'],
			confirmDropped: ['field-b'],
		)->willReturn(['rebound' => true]);

		$response = $this->controller->rebind('case-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['rebound' => true], $response->getData());
	}//end testRebindPassesTheRequestOn()

	/**
	 * A refusal answers its own status, rule and sentence.
	 *
	 * @return void
	 */
	public function testARefusalAnswersItsRuleAndSentence(): void {
		$this->signIn('anna');
		$this->rebind->method('rebind')->willThrowException(
			new RefusedException(rule: 'case-closed', sentence: 'A closed case keeps its type.')
		);
		$this->logger->expects(self::once())->method('info');

		$response = $this->controller->rebind('case-1');

		self::assertSame(RefusedException::STATUS_REFUSED, $response->getStatus());
		self::assertSame('case-closed', $response->getData()['error']);
		self::assertSame('case_closed', $response->getData()['code']);
	}//end testARefusalAnswersItsRuleAndSentence()

	/**
	 * Any other failure is logged and answers 500 without its message.
	 *
	 * @return void
	 */
	public function testAFailureIsLoggedAndAnswers500(): void {
		$this->signIn('coord');
		$this->rebind->method('mayRebind')->willReturn(true);
		$this->rebind->method('options')->willThrowException(new RuntimeException('database gone'));
		$this->logger->expects(self::once())->method('error')
			->with('CaseRebindController: rebind-options failed', ['exception' => 'database gone']);

		$response = $this->controller->options('case-1');

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame(['message' => 'The case was not rebound.', 'error' => 'rebind-failed'], $response->getData());
	}//end testAFailureIsLoggedAndAnswers500()
}//end class
