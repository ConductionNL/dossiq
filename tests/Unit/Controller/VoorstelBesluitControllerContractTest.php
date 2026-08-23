<?php

/**
 * VoorstelBesluitController Wire-Contract Tests
 *
 * Contract coverage for `POST /api/voorstellen/{voorstelId}/register-besluit`
 * (gate-25). Registering a besluit on a voorstel does NOT author a
 * dossiq-local decision: per ADR-019 / REQ-PDRD-001 it raises a decidesk
 * `report-adoption` Decision, and per REQ-PDRD-002 it must FAIL CLOSED when
 * decidesk is unavailable. These tests pin both the IDOR gate and that
 * fail-closed rule:
 *
 *  - 401 without a session, before the voorstel is even loaded;
 *  - 404 when the voorstel cannot be resolved — and, crucially, NOTHING is
 *    raised in decidesk. Raising first and checking later would create a
 *    Decision for a voorstel the caller may not touch;
 *  - 403 when the caller is neither the voorstel owner, its assignee, nor an
 *    admin — with `raiseVoorstelBesluit()` asserted NEVER called. The 403 and
 *    the 404 deliberately carry the SAME message so the endpoint is not an
 *    existence oracle, and that sameness is asserted;
 *  - the assignee arm is exercised separately from the owner arm, so a gate
 *    that only ever honoured `@self.owner` would fail;
 *  - REQ-PDRD-002: a `RuntimeException` from the delegation is a **503**, and
 *    the response carries NO locally-authored besluit — there is no fallback;
 *  - the happy path is **202 Accepted** (not 201) carrying the decidesk
 *    `decisionRef` and `status: awaiting-decidesk`, because dossiq has not
 *    decided anything yet — decidesk has yet to.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\VoorstelBesluitController;
use OCA\Dossiq\Service\AdviceDelegationService;
use OCA\Dossiq\Service\SettingsService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Minimal ObjectService surface used by VoorstelBesluitController.
 *
 * The controller resolves OpenRegister's ObjectService through SettingsService
 * as an untyped `mixed` and calls `find()` with named `register:`/`schema:`
 * arguments, so the double must carry those parameter names. Prefixed with the
 * controller name because sibling contract suites declare their own doubles in
 * this namespace.
 */
interface VoorstelBesluitControllerContractObjectService {
	/**
	 * Find a single object.
	 *
	 * @param int|string $id The object id.
	 * @param string $register The register slug.
	 * @param string $schema The schema slug.
	 *
	 * @return mixed The object.
	 */
	public function find(int|string $id, string $register = '', string $schema = ''): mixed;
}//end interface

/**
 * Concrete IRequest stub serving a raw body for the VoorstelBesluit tests.
 *
 * `getContent()` is not on the OCP\IRequest interface (it is a magic accessor
 * on the concrete OC request), so a PHPUnit mock of IRequest has no such
 * method and calling it raises an Error.
 */
class VoorstelBesluitControllerContractRequestStub implements IRequest {

	/**
	 * The raw request body returned by getContent().
	 *
	 * @var string
	 */
	private string $content;

	/**
	 * Constructor.
	 *
	 * @param string $content The raw request body.
	 */
	public function __construct(string $content = '') {
		$this->content = $content;
	}//end __construct()

	/**
	 * Return the raw request body.
	 *
	 * @return string
	 */
	public function getContent(): string {
		return $this->content;
	}//end getContent()

	/**
	 * Return a request header value.
	 *
	 * @param string $name Header name.
	 *
	 * @return string
	 */
	public function getHeader(string $name): string {
		return '';
	}//end getHeader()

	/**
	 * Return a single request parameter.
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
	 * Return an uploaded file.
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
	 * Return a cookie value.
	 *
	 * @param string $key Cookie name.
	 *
	 * @return mixed
	 */
	public function getCookie(string $key): mixed {
		return null;
	}//end getCookie()

	/**
	 * Whether the request passes the CSRF check.
	 *
	 * @return bool
	 */
	public function passesCSRFCheck(): bool {
		return true;
	}//end passesCSRFCheck()

	/**
	 * Whether the request passes the strict cookie check.
	 *
	 * @return bool
	 */
	public function passesStrictCookieCheck(): bool {
		return true;
	}//end passesStrictCookieCheck()

	/**
	 * Whether the request passes the lax cookie check.
	 *
	 * @return bool
	 */
	public function passesLaxCookieCheck(): bool {
		return true;
	}//end passesLaxCookieCheck()

	/**
	 * Return the unique request id.
	 *
	 * @return string
	 */
	public function getId(): string {
		return 'voorstel-besluit-contract-test';
	}//end getId()

	/**
	 * Return the remote address.
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
		return 'https';
	}//end getHttpProtocol()

	/**
	 * Return the request URI.
	 *
	 * @return string
	 */
	public function getRequestUri(): string {
		return '/apps/dossiq/api/voorstellen/voorstel-1/register-besluit';
	}//end getRequestUri()

	/**
	 * Return the raw path info.
	 *
	 * @return string
	 */
	public function getRawPathInfo(): string {
		return '';
	}//end getRawPathInfo()

	/**
	 * Return the decoded path info.
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
	 * Whether the request comes from one of the given user agents.
	 *
	 * @param array<int,string> $agent Agent patterns.
	 *
	 * @return bool
	 */
	public function isUserAgent(array $agent): bool {
		return false;
	}//end isUserAgent()

	/**
	 * Return the insecure server host.
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
	 * Rethrow a body-decoding failure, if any.
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
 * Wire-contract tests for VoorstelBesluitController::registerBesluit().
 *
 * @covers \OCA\Dossiq\Controller\VoorstelBesluitController
 */
class VoorstelBesluitControllerContractTest extends TestCase {

	/**
	 * The decidesk delegation service.
	 *
	 * @var AdviceDelegationService|MockObject
	 */
	private AdviceDelegationService $adviceDelegation;

	/**
	 * The settings service (register/schema + ObjectService resolver).
	 *
	 * @var SettingsService|MockObject
	 */
	private SettingsService $settingsService;

	/**
	 * The user session.
	 *
	 * @var IUserSession|MockObject
	 */
	private IUserSession $userSession;

	/**
	 * The group manager (admin bypass on the IDOR gate).
	 *
	 * @var IGroupManager|MockObject
	 */
	private IGroupManager $groupManager;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface|MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Build the shared collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->adviceDelegation = $this->createMock(AdviceDelegationService::class);
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->settingsService->method('getConfigValue')->willReturnCallback(
			static function (string $key): string {
				return match ($key) {
					'register' => 'procest',
					'voorstel_schema' => 'voorstel',
					default => '',
				};
			}
		);
	}//end setUp()

	/**
	 * Build the controller with the given request body.
	 *
	 * @param string $body The raw JSON body.
	 *
	 * @return VoorstelBesluitController
	 */
	private function controller(string $body = ''): VoorstelBesluitController {
		return new VoorstelBesluitController(
			request: new VoorstelBesluitControllerContractRequestStub(content: $body),
			adviceDelegation: $this->adviceDelegation,
			settingsService: $this->settingsService,
			userSession: $this->userSession,
			groupManager: $this->groupManager,
			logger: $this->logger,
		);
	}//end controller()

	/**
	 * Put a signed-in, non-admin user on the session.
	 *
	 * @param string $uid The user id.
	 *
	 * @return void
	 */
	private function signIn(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->willReturn(false);
	}//end signIn()

	/**
	 * Install an ObjectService that resolves the voorstel to the given record.
	 *
	 * @param array<string,mixed>|null $proposal The voorstel record, or null.
	 *
	 * @return void
	 */
	private function withProposal(?array $proposal): void {
		$objectService = $this->createMock(VoorstelBesluitControllerContractObjectService::class);
		$objectService->method('find')->willReturn($proposal);
		$this->settingsService->method('getObjectService')->willReturn($objectService);
	}//end withProposal()

	/**
	 * A session-less caller is refused 401 and nothing is raised in decidesk.
	 *
	 * @return void
	 */
	public function testRegisterBesluitRefusesASessionLessCallerAndRaisesNothing(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->adviceDelegation->expects($this->never())->method('raiseVoorstelBesluit');

		$response = $this->controller()->registerBesluit(proposalId: 'voorstel-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'Authenticatie vereist'], $response->getData());
	}//end testRegisterBesluitRefusesASessionLessCallerAndRaisesNothing()

	/**
	 * An unresolvable voorstel is a 404, raised BEFORE any decidesk Decision.
	 *
	 * @return void
	 */
	public function testRegisterBesluitReturns404ForAnUnresolvableVoorstelWithoutRaising(): void {
		$this->signIn('behandelaar');
		$this->settingsService->method('getObjectService')->willReturn(null);
		$this->adviceDelegation->expects($this->never())->method('raiseVoorstelBesluit');

		$response = $this->controller()->registerBesluit(proposalId: 'voorstel-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'Voorstel niet toegankelijk'], $response->getData());
	}//end testRegisterBesluitReturns404ForAnUnresolvableVoorstelWithoutRaising()

	/**
	 * A caller who is neither owner, assignee nor admin is refused 403 and no
	 * Decision is raised — and the message matches the 404 exactly, so the
	 * endpoint cannot be used to probe which voorstellen exist.
	 *
	 * @return void
	 */
	public function testRegisterBesluitRefusesAnUnrelatedCallerWithoutBecomingAnExistenceOracle(): void {
		$this->signIn('buitenstaander');
		$this->withProposal(['@self' => ['owner' => 'eigenaar'], 'assignee' => 'behandelaar']);
		$this->adviceDelegation->expects($this->never())->method('raiseVoorstelBesluit');

		$response = $this->controller()->registerBesluit(proposalId: 'voorstel-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(
			['error' => 'Voorstel niet toegankelijk'],
			$response->getData(),
			'the 403 body must be identical to the 404 body, or the status pair leaks existence'
		);
	}//end testRegisterBesluitRefusesAnUnrelatedCallerWithoutBecomingAnExistenceOracle()

	/**
	 * The recorded ASSIGNEE may register, not only the owner — a gate wired
	 * solely to `@self.owner` would lock out the actual handler.
	 *
	 * @return void
	 */
	public function testRegisterBesluitAdmitsTheRecordedAssigneeAndNotOnlyTheOwner(): void {
		$this->signIn('behandelaar');
		$this->withProposal([
			'@self' => ['owner' => 'iemand-anders'],
			'assignee' => 'behandelaar',
			'case' => 'zaak-5',
			'onderwerp' => 'Vaststelling jaarrekening',
		]);

		$this->adviceDelegation->expects($this->once())
			->method('raiseVoorstelBesluit')
			->willReturn('decidesk:decision:abc');

		$response = $this->controller()->registerBesluit(proposalId: 'voorstel-1');

		$this->assertSame(Http::STATUS_ACCEPTED, $response->getStatus());
	}//end testRegisterBesluitAdmitsTheRecordedAssigneeAndNotOnlyTheOwner()

	/**
	 * REQ-PDRD-002: when decidesk is unavailable the endpoint FAILS CLOSED
	 * with 503 and authors no local besluit as a fallback.
	 *
	 * @return void
	 */
	public function testRegisterBesluitFailsClosedWith503WhenDecideskIsUnavailable(): void {
		$this->signIn('eigenaar');
		$this->withProposal(['@self' => ['owner' => 'eigenaar']]);

		$this->adviceDelegation->method('raiseVoorstelBesluit')
			->willThrowException(new \RuntimeException('decidesk not reachable'));

		$response = $this->controller()->registerBesluit(proposalId: 'voorstel-1');
		$data = $response->getData();

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame('Besluitdienst niet beschikbaar: decidesk not reachable', $data['error']);
		$this->assertArrayNotHasKey(
			'decisionRef',
			$data,
			'a fail-closed response must not carry a locally-authored besluit'
		);
	}//end testRegisterBesluitFailsClosedWith503WhenDecideskIsUnavailable()

	/**
	 * The happy path is 202 Accepted with the decidesk reference and an
	 * `awaiting-decidesk` status — dossiq has NOT decided anything yet, so a
	 * 201 Created would misstate the outcome. The payload sent to decidesk
	 * carries the voorstel's case as the external reference and the body's
	 * title.
	 *
	 * @return void
	 */
	public function testRegisterBesluitAnswers202AwaitingDecideskWithTheDecisionReference(): void {
		$this->signIn('eigenaar');
		$this->withProposal([
			'@self' => ['owner' => 'eigenaar'],
			'case' => 'zaak-5',
			'onderwerp' => 'Vaststelling jaarrekening',
		]);

		$this->adviceDelegation->expects($this->once())
			->method('raiseVoorstelBesluit')
			->with(
				'voorstel-1',
				[
					'externalReference' => 'zaak-5',
					'subjectLabel' => 'Jaarrekening 2026',
					'title' => 'Jaarrekening 2026',
					'governingBody' => 'college',
					'explanation' => 'conform advies',
				]
			)
			->willReturn('decidesk:decision:abc');

		$body = '{"title":"Jaarrekening 2026","governingBody":"college","explanation":"conform advies"}';
		$response = $this->controller($body)->registerBesluit(proposalId: 'voorstel-1');

		$this->assertSame(Http::STATUS_ACCEPTED, $response->getStatus());
		$this->assertSame(
			[
				'voorstelId' => 'voorstel-1',
				'decisionRef' => 'decidesk:decision:abc',
				'status' => 'awaiting-decidesk',
			],
			$response->getData()
		);
	}//end testRegisterBesluitAnswers202AwaitingDecideskWithTheDecisionReference()
}//end class
