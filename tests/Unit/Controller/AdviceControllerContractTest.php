<?php

/**
 * AdviceController Wire-Contract Tests
 *
 * Contract coverage for the three advice endpoints that had no automated proof
 * of their wire behaviour (gate-25). The three do NOT share one auth posture,
 * and that difference is the contract these tests pin:
 *
 *  - `createForCase` and `getForCase` THROW `OCSForbiddenException` for an
 *    anonymous caller, while `dispatchReminder` answers a plain 401 JSON body —
 *    a client cannot handle both the same way, so the shape of each refusal is
 *    asserted rather than "some error came back";
 *  - `getForCase` is the only one of the three behind `CaseAccessGuard`, and it
 *    must consult it with the CASE uuid from the route and the SESSION user —
 *    passing the wrong id or a different user is the realistic defect;
 *  - `createForCase` writes `requestedBy`, `caseRef` and `status` server-side.
 *    A body that claims all three must not win: identity supplied by the
 *    requester is not identity;
 *  - `dispatchReminder` distinguishes an authorization refusal (403, message
 *    carried through) from an unexpected failure (500, message withheld) — a
 *    single catch-all would either leak internals or hide the refusal.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://procest.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Controller;

use LogicException;
use OCA\Procest\Controller\AdviceController;
use OCA\Procest\Service\AdviceService;
use OCA\Procest\Service\CaseAccessGuard;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Concrete IRequest stub carrying a raw JSON body.
 *
 * `AdviceController::readJsonBody()` prefers `$request->getContent()` and only
 * falls back to `php://input`. `OCP\IRequest` does not declare `getContent()`
 * (it is a magic property on the concrete Nextcloud request), so a createMock
 * of the interface cannot serve a body at all. This stub is the seam that lets
 * the "the body must not override server-derived identity" test actually POST
 * something.
 */
class AdviceControllerRequestStub implements IRequest {

	/**
	 * Raw request body returned by getContent().
	 *
	 * @var string
	 */
	private string $content;

	/**
	 * Constructor.
	 *
	 * @param string $content Raw JSON request body.
	 */
	public function __construct(string $content = '') {
		$this->content = $content;
	}//end __construct()

	/**
	 * Return the raw request body content.
	 *
	 * @return string
	 */
	public function getContent(): string {
		return $this->content;
	}//end getContent()

	/**
	 * Return a query/body parameter.
	 *
	 * @param string $key Parameter name.
	 * @param mixed $default Default when absent.
	 *
	 * @return mixed
	 */
	public function getParam(string $key, mixed $default = null): mixed {
		return $default;
	}//end getParam()

	/**
	 * Return a request header value by name.
	 *
	 * @param string $name Header name.
	 *
	 * @return string
	 */
	public function getHeader(string $name): string {
		return '';
	}//end getHeader()

	/**
	 * Return all request parameters.
	 *
	 * @return array<string,mixed>
	 */
	public function getParams(): array {
		return [];
	}//end getParams()

	/**
	 * Return the HTTP method.
	 *
	 * @return string
	 */
	public function getMethod(): string {
		return 'POST';
	}//end getMethod()

	/**
	 * Return an uploaded file by key.
	 *
	 * @param string $key File field name.
	 *
	 * @return mixed
	 */
	public function getUploadedFile(string $key): mixed {
		return null;
	}//end getUploadedFile()

	/**
	 * Return a server environment variable.
	 *
	 * @param string $key Variable name.
	 *
	 * @return mixed
	 */
	public function getEnv(string $key): mixed {
		return null;
	}//end getEnv()

	/**
	 * Return a cookie value by name.
	 *
	 * @param string $key Cookie name.
	 *
	 * @return mixed
	 */
	public function getCookie(string $key): mixed {
		return null;
	}//end getCookie()

	/**
	 * Return whether this request passes a CSRF check.
	 *
	 * @return bool
	 */
	public function passesCSRFCheck(): bool {
		return true;
	}//end passesCSRFCheck()

	/**
	 * Return whether this request passes a strict cookie check.
	 *
	 * @return bool
	 */
	public function passesStrictCookieCheck(): bool {
		return true;
	}//end passesStrictCookieCheck()

	/**
	 * Return whether this request passes a lax cookie check.
	 *
	 * @return bool
	 */
	public function passesLaxCookieCheck(): bool {
		return true;
	}//end passesLaxCookieCheck()

	/**
	 * Return the unique request ID.
	 *
	 * @return string
	 */
	public function getId(): string {
		return 'test-request';
	}//end getId()

	/**
	 * Return the remote IP address.
	 *
	 * @return string
	 */
	public function getRemoteAddress(): string {
		return '127.0.0.1';
	}//end getRemoteAddress()

	/**
	 * Return the server protocol.
	 *
	 * @return string
	 */
	public function getServerProtocol(): string {
		return 'HTTP/1.1';
	}//end getServerProtocol()

	/**
	 * Return the HTTP scheme.
	 *
	 * @return string
	 */
	public function getHttpProtocol(): string {
		return 'http';
	}//end getHttpProtocol()

	/**
	 * Return the full request URI.
	 *
	 * @return string
	 */
	public function getRequestUri(): string {
		return '/apps/procest/api/vth/cases/case-1/advice-requests';
	}//end getRequestUri()

	/**
	 * Return the raw path info segment.
	 *
	 * @return string
	 */
	public function getRawPathInfo(): string {
		return '';
	}//end getRawPathInfo()

	/**
	 * Return the decoded path info segment.
	 *
	 * @return mixed
	 */
	public function getPathInfo(): mixed {
		return '';
	}//end getPathInfo()

	/**
	 * Return the script name.
	 *
	 * @return string
	 */
	public function getScriptName(): string {
		return '';
	}//end getScriptName()

	/**
	 * Return whether the request originates from the given user agent(s).
	 *
	 * @param array<int,string> $agent Agent strings to match.
	 *
	 * @return bool
	 */
	public function isUserAgent(array $agent): bool {
		return false;
	}//end isUserAgent()

	/**
	 * Return the insecure (HTTP) server host.
	 *
	 * @return string
	 */
	public function getInsecureServerHost(): string {
		return 'localhost';
	}//end getInsecureServerHost()

	/**
	 * Return the server host.
	 *
	 * @return string
	 */
	public function getServerHost(): string {
		return 'localhost';
	}//end getServerHost()

	/**
	 * Throw if a JSON decode error occurred during body parsing.
	 *
	 * @return void
	 */
	public function throwDecodingExceptionIfAny(): void {
	}//end throwDecodingExceptionIfAny()

	/**
	 * Return the requested response format.
	 *
	 * @return string|null
	 */
	public function getFormat(): ?string {
		return null;
	}//end getFormat()
}//end class

/**
 * Wire-contract tests for AdviceController.
 *
 * @covers \OCA\Procest\Controller\AdviceController
 */
class AdviceControllerContractTest extends TestCase {

	/**
	 * The advice service mock — the controller's only delegate.
	 *
	 * @var AdviceService|MockObject
	 */
	private AdviceService $adviceService;

	/**
	 * The session mock.
	 *
	 * @var IUserSession|MockObject
	 */
	private IUserSession $userSession;

	/**
	 * The per-case read guard mock.
	 *
	 * @var CaseAccessGuard|MockObject
	 */
	private CaseAccessGuard $caseAccessGuard;

	/**
	 * The logger mock.
	 *
	 * @var LoggerInterface|MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Build the collaborator mocks. The controller itself is built per test so
	 * a body-carrying request stub can be swapped in.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->adviceService = $this->createMock(AdviceService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->caseAccessGuard = $this->createMock(CaseAccessGuard::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Build the controller over the supplied request.
	 *
	 * @param IRequest $request The request to hand the controller.
	 *
	 * @return AdviceController
	 */
	private function controller(IRequest $request): AdviceController {
		return new AdviceController(
			appName: 'procest',
			request: $request,
			adviceService: $this->adviceService,
			userSession: $this->userSession,
			logger: $this->logger,
			caseAccessGuard: $this->caseAccessGuard,
		);
	}//end controller()

	/**
	 * Build the controller over a request carrying an empty JSON object.
	 *
	 * An empty string would make `readJsonBody()` fall through to `php://input`,
	 * which a unit test cannot drive; `{}` keeps the read on the stub.
	 *
	 * @return AdviceController
	 */
	private function bodylessController(): AdviceController {
		return $this->controller(request: new AdviceControllerRequestStub(content: '{}'));
	}//end bodylessController()

	/**
	 * Mark the session as authenticated for user `alice`.
	 *
	 * @return IUser The authenticated user.
	 */
	private function authenticate(): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);

		return $user;
	}//end authenticate()

	/**
	 * createForCase refuses an anonymous caller by THROWING, and never reaches
	 * the service. The throw (rather than a JSONResponse) is the contract: the
	 * OCS exception is what the caller sees.
	 *
	 * @return void
	 */
	public function testCreateForCaseThrowsForbiddenForAnAnonymousCaller(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->adviceService->expects($this->never())->method('requestAdvice');

		$this->expectException(OCSForbiddenException::class);
		$this->expectExceptionMessage('Not authenticated');

		$this->bodylessController()->createForCase(id: 'case-1');
	}//end testCreateForCaseThrowsForbiddenForAnAnonymousCaller()

	/**
	 * createForCase derives `caseRef` from the ROUTE, `requestedBy` from the
	 * SESSION and pins `status` to `open` — a body claiming all three must lose.
	 *
	 * A caller that could set `requestedBy` would attribute its own advice
	 * request to somebody else, and one that could set `status` would skip the
	 * workflow entirely.
	 *
	 * @return void
	 */
	public function testCreateForCaseOverridesCaseRefRequestedByAndStatusFromTheBody(): void {
		$this->authenticate();

		$body = json_encode(
			[
				'caseRef' => 'some-other-case',
				'requestedBy' => 'mallory',
				'status' => 'received',
				'question' => 'Is dit vergunningplichtig?',
			]
		);

		$created = ['id' => 'advice-1', 'status' => 'open'];

		$this->adviceService->expects($this->once())
			->method('requestAdvice')
			->with(
				'case-1',
				$this->callback(
					static function (array $data): bool {
						return $data['caseRef'] === 'case-1'
							&& $data['requestedBy'] === 'alice'
							&& $data['status'] === 'open'
							&& $data['question'] === 'Is dit vergunningplichtig?';
					}
				),
				'alice'
			)
			->willReturn($created);

		$controller = $this->controller(request: new AdviceControllerRequestStub(content: (string)$body));
		$response = $controller->createForCase(id: 'case-1');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame($created, $response->getData());
	}//end testCreateForCaseOverridesCaseRefRequestedByAndStatusFromTheBody()

	/**
	 * A service failure on createForCase is a 500, not a 201 with a half-made
	 * record.
	 *
	 * @return void
	 */
	public function testCreateForCaseReturns500WhenTheServiceFails(): void {
		$this->authenticate();
		$this->adviceService->method('requestAdvice')
			->willThrowException(new RuntimeException('OpenRegister is not available'));

		$response = $this->bodylessController()->createForCase(id: 'case-1');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertArrayHasKey('error', $response->getData());
	}//end testCreateForCaseReturns500WhenTheServiceFails()

	/**
	 * getForCase refuses an anonymous caller by throwing, before the guard or
	 * the service is consulted.
	 *
	 * @return void
	 */
	public function testGetForCaseThrowsForbiddenForAnAnonymousCaller(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->caseAccessGuard->expects($this->never())->method('hasCaseReadAccess');
		$this->adviceService->expects($this->never())->method('getAdviceForCase');

		$this->expectException(OCSForbiddenException::class);

		$this->bodylessController()->getForCase(id: 'case-1');
	}//end testGetForCaseThrowsForbiddenForAnAnonymousCaller()

	/**
	 * getForCase consults CaseAccessGuard with the CASE uuid from the route and
	 * the SESSION user, and answers 403 without listing anything when the guard
	 * denies. Advice requests carry the substance of a permit assessment, so a
	 * list served past the guard is the disclosure.
	 *
	 * @return void
	 */
	public function testGetForCaseRefusesWithoutCaseReadAccessAndNeverLists(): void {
		$user = $this->authenticate();

		$this->caseAccessGuard->expects($this->once())
			->method('hasCaseReadAccess')
			->with('case-1', $user)
			->willReturn(false);

		$this->adviceService->expects($this->never())->method('getAdviceForCase');

		$response = $this->bodylessController()->getForCase(id: 'case-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['error' => 'Not authorized'], $response->getData());
	}//end testGetForCaseRefusesWithoutCaseReadAccessAndNeverLists()

	/**
	 * With read access granted, getForCase returns the service's list verbatim
	 * at 200, keyed on the same case uuid the guard approved.
	 *
	 * @return void
	 */
	public function testGetForCaseReturnsTheListForTheApprovedCase(): void {
		$this->authenticate();
		$this->caseAccessGuard->method('hasCaseReadAccess')->willReturn(true);

		$rows = [['id' => 'advice-1', 'status' => 'requested']];

		$this->adviceService->expects($this->once())
			->method('getAdviceForCase')
			->with('case-1')
			->willReturn($rows);

		$response = $this->bodylessController()->getForCase(id: 'case-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($rows, $response->getData());
	}//end testGetForCaseReturnsTheListForTheApprovedCase()

	/**
	 * dispatchReminder answers a JSON 401 for an anonymous caller — NOT the
	 * OCS throw its two neighbours use — and does not notify anybody.
	 *
	 * @return void
	 */
	public function testDispatchReminderReturns401ForAnAnonymousCallerAndSendsNothing(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->adviceService->expects($this->never())->method('dispatchReminderAsUser');

		$response = $this->bodylessController()->dispatchReminder(id: 'advice-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'Not authenticated'], $response->getData());
	}//end testDispatchReminderReturns401ForAnAnonymousCallerAndSendsNothing()

	/**
	 * The authorized seam is `dispatchReminderAsUser()`, not the cron's
	 * unguarded `dispatchReminder()`. On success the wire answer is the fixed
	 * `{"status":"reminded"}` acknowledgement.
	 *
	 * @return void
	 */
	public function testDispatchReminderUsesTheAuthorizedSeamAndAcknowledges(): void {
		$this->authenticate();

		$this->adviceService->expects($this->once())
			->method('dispatchReminderAsUser')
			->with('advice-1');

		$response = $this->bodylessController()->dispatchReminder(id: 'advice-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['status' => 'reminded'], $response->getData());
	}//end testDispatchReminderUsesTheAuthorizedSeamAndAcknowledges()

	/**
	 * The service's authorization refusal (a RuntimeException) becomes a 403
	 * carrying the refusal message — not a 500, which a client would retry.
	 *
	 * @return void
	 */
	public function testDispatchReminderMapsAServiceRefusalTo403(): void {
		$this->authenticate();
		$this->adviceService->method('dispatchReminderAsUser')
			->willThrowException(new RuntimeException('Advice request not accessible'));

		$response = $this->bodylessController()->dispatchReminder(id: 'advice-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['error' => 'Advice request not accessible'], $response->getData());
	}//end testDispatchReminderMapsAServiceRefusalTo403()

	/**
	 * An unexpected failure becomes a 500 whose body does NOT carry the
	 * internal message — only the RuntimeException arm is a caller-facing
	 * message.
	 *
	 * @return void
	 */
	public function testDispatchReminderWithholdsTheInternalMessageOn500(): void {
		$this->authenticate();
		$this->adviceService->method('dispatchReminderAsUser')
			->willThrowException(new LogicException('SQLSTATE[42S02] table procest_advice missing'));

		$response = $this->bodylessController()->dispatchReminder(id: 'advice-1');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'Could not send reminder'], $response->getData());
	}//end testDispatchReminderWithholdsTheInternalMessageOn500()
}//end class
