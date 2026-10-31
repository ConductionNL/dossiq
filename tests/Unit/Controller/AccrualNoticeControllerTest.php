<?php

/**
 * AccrualNoticeController tests.
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
 * @spec openspec/changes/notice-of-default-from-the-case/specs/ingebrekestelling/spec.md#requirement-a-handler-registers-a-notice-of-default-on-the-case-req-ing-case-01
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\AccrualNoticeController;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\Money\AccrualNoticeService;
use OCA\Dossiq\Service\SettingsService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Controller\AccrualNoticeController
 */
class AccrualNoticeControllerTest extends TestCase {

	private const BODY = ['caseId' => 'case-1', 'receiptDate' => '2026-03-15', 'notificationChannel' => 'post', 'sender' => ' J. de Vries '];

	private const PASSED = ['id' => 'ti-1', 'case' => 'case-1', 'status' => 'exceeded', 'endDateCurrent' => '2026-03-12'];

	/**
	 * The controller with the given service, access and body.
	 *
	 * @param AccrualNoticeService $service   The notice service.
	 * @param bool                 $mayMutate Whether the caller may change the case.
	 * @param array<string, mixed> $body      The request body.
	 * @param bool                 $signedIn  Whether a user is signed in.
	 *
	 * @return AccrualNoticeController
	 */
	private function controller(AccrualNoticeService $service, bool $mayMutate, array $body, bool $signedIn = true): AccrualNoticeController {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn ? $this->createMock(IUser::class) : null);
		$guard = $this->createMock(CaseAccessGuard::class);
		$guard->method('hasCaseMutationAccess')->willReturn($mayMutate);

		return new class('dossiq', $this->createMock(IRequest::class), $service, $this->createMock(SettingsService::class), $session, $this->createMock(LoggerInterface::class), $guard, $body) extends AccrualNoticeController {
			/**
			 * @param string               $appName     App id.
			 * @param IRequest             $request     Request.
			 * @param AccrualNoticeService $service     Service.
			 * @param SettingsService      $settings    Settings.
			 * @param IUserSession         $userSession Session.
			 * @param LoggerInterface      $logger      Logger.
			 * @param CaseAccessGuard      $caseAccess  Guard.
			 * @param array<string, mixed> $body        The body this request carries.
			 */
			public function __construct(
				string $appName,
				IRequest $request,
				AccrualNoticeService $service,
				SettingsService $settings,
				IUserSession $userSession,
				LoggerInterface $logger,
				CaseAccessGuard $caseAccess,
				private readonly array $body,
			) {
				parent::__construct($appName, $request, $service, $settings, $userSession, $logger, $caseAccess);
			}

			/**
			 * @return array<string, mixed>
			 */
			protected function jsonBody(): array {
				return $this->body;
			}
		};
	}//end controller()

	/**
	 * A notice after the term on the case's statutory term is stored with its sender (201).
	 *
	 * @return void
	 */
	public function testANoticeAfterTheTermIsRegisteredOnTheCasesTerm(): void {
		$service = $this->createMock(AccrualNoticeService::class);
		$service->method('statutoryTermOf')->with('case-1')->willReturn(self::PASSED);
		$service->method('prematureReason')->willReturn('');
		$service->expects($this->once())->method('registerNotice')
			->with('ti-1', $this->anything(), 'post', '', 'J. de Vries')
			->willReturn(['id' => 'nod-1', 'validityStatus' => 'valid']);

		$response = $this->controller($service, true, self::BODY)->register();

		self::assertSame(Http::STATUS_CREATED, $response->getStatus());
		self::assertSame('nod-1', $response->getData()['id']);
	}//end testANoticeAfterTheTermIsRegisteredOnTheCasesTerm()

	/**
	 * A notice while the term runs is refused with 409 and the reason, and nothing is stored.
	 *
	 * @spec openspec/changes/notice-of-default-from-the-case/specs/ingebrekestelling/spec.md#requirement-a-handler-registers-a-notice-of-default-on-the-case-req-ing-case-01
	 *
	 * @return void
	 */
	public function testANoticeWhileTheTermRunsIsRefused(): void {
		$running = ['id' => 'ti-1', 'case' => 'case-1', 'status' => 'lopend', 'endDateCurrent' => '2026-03-25'];
		$service = $this->createMock(AccrualNoticeService::class);
		$service->method('statutoryTermOf')->willReturn($running);
		$service->method('prematureReason')->willReturn('The decision term runs until 2026-03-25.');
		$service->expects($this->never())->method('registerNotice');

		$response = $this->controller($service, true, self::BODY)->register();

		self::assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		self::assertSame('term-running', $response->getData()['refusal']);
		self::assertSame('2026-03-25', $response->getData()['termEnds']);
		self::assertStringContainsString('2026-03-25', $response->getData()['message']);
	}//end testANoticeWhileTheTermRunsIsRefused()

	/**
	 * Without mutation access, without a user, or on a term of another case, nothing is stored.
	 *
	 * @return void
	 */
	public function testACallerWhoMayNotChangeTheCaseIsRefused(): void {
		$service = $this->createMock(AccrualNoticeService::class);
		$service->method('statutoryTermOf')->willReturn(self::PASSED);
		$service->method('termInstance')->willReturn(['id' => 'ti-9', 'case' => 'case-9'] + self::PASSED);
		$service->expects($this->never())->method('registerNotice');

		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller($service, false, self::BODY)->register()->getStatus());
		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller($service, true, self::BODY, false)->register()->getStatus());
		$byInstance = ['termijnInstanceId' => 'ti-9', 'caseId' => 'case-1'] + self::BODY;
		$service2 = $this->createMock(AccrualNoticeService::class);
		$service2->method('statutoryTermOf')->willReturn(['case' => 'case-9'] + self::PASSED);
		$service2->expects($this->never())->method('registerNotice');
		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller($service2, true, $byInstance)->register()->getStatus());
	}//end testACallerWhoMayNotChangeTheCaseIsRefused()

	/**
	 * A missing field is a 400, an unreadable date too, and a case without a term a 404.
	 *
	 * @return void
	 */
	public function testIncompleteRequestsAreRefused(): void {
		$service = $this->createMock(AccrualNoticeService::class);
		$service->method('statutoryTermOf')->willReturn(null);
		$service->expects($this->never())->method('registerNotice');

		self::assertSame(Http::STATUS_BAD_REQUEST, $this->controller($service, true, ['caseId' => 'case-1'])->register()->getStatus());
		self::assertSame(Http::STATUS_BAD_REQUEST, $this->controller($service, true, ['receiptDate' => 'not a date'] + self::BODY)->register()->getStatus());
		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller($service, true, self::BODY)->register()->getStatus());
	}//end testIncompleteRequestsAreRefused()

	/**
	 * The legacy term-instance path still registers, against the named instance.
	 *
	 * @return void
	 */
	public function testANoticeByTermInstanceStillRegisters(): void {
		$service = $this->createMock(AccrualNoticeService::class);
		$service->method('termInstance')->with('ti-1')->willReturn(self::PASSED);
		$service->method('prematureReason')->willReturn('');
		$service->expects($this->once())->method('registerNotice')->willReturn(['id' => 'nod-2']);

		$body = ['termijnInstanceId' => 'ti-1', 'receiptDate' => '2026-03-15', 'notificationChannel' => 'email'];

		self::assertSame(Http::STATUS_CREATED, $this->controller($service, true, $body)->register()->getStatus());
	}//end testANoticeByTermInstanceStillRegisters()
}//end class
