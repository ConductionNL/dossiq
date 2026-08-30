<?php

/**
 * DsoController Wire-Contract Tests
 *
 * Contract coverage for the three DSO Omgevingsloket endpoints that had no
 * automated proof of their wire behaviour (gate-25): the vergunningen
 * dashboard, `doorsturen` (forwarding a permit case to another bevoegd gezag)
 * and `respondSamenwerking` (answering a samenwerkverzoek). All three are
 * `#[NoAdminRequired]`.
 *
 * The contract pinned here:
 *
 *  - no session answers 401 on all three, before any OpenRegister read;
 *  - the dashboard query is SCOPED to `caseType: omgevingsvergunning` and
 *    paginated — an unscoped dashboard would list every case in the register
 *    while still rendering perfectly;
 *  - an OpenRegister outage on the dashboard is a 503, and any other failure a
 *    masked 500 — the two are distinct branches with distinct meanings;
 *  - `doorsturen` demands a target bevoegd gezag (400), 404s an unknown case,
 *    and — the load-bearing one — dispatches NO VergunningDoorgestuurd event
 *    when the mutation guard refuses. That event is what downstream listeners
 *    act on, so an event emitted on a refused forward is a real handover of a
 *    permit case by an unauthorized caller;
 *  - `respondSamenwerking` likewise 404s an unknown verzoek and records NO
 *    answer when the guard refuses, and it maps the `advice` body key onto the
 *    service's `advies` argument.
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

use OCA\Dossiq\Controller\DsoController;
use OCA\Dossiq\Service\BeschikkingGenerationService;
use OCA\Dossiq\Service\Dso\DsoDoorsturenNotifier;
use OCA\Dossiq\Service\Dso\DsoObjectRepository;
use OCA\Dossiq\Service\DsoCaseService;
use OCA\Dossiq\Service\SamenwerkverzoekService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Concrete IRequest stub for the DsoController contract tests.
 *
 * `DsoController::readJsonBody()` reads the raw body through
 * `IRequest::getContent()`, which is a magic property on the real Nextcloud
 * request and therefore cannot be configured on a `createMock(IRequest::class)`.
 * Uniquely named (`DsoContract…`) because several contract-test files declare
 * their own request stubs in this same namespace.
 */
class DsoContractRequestStub implements IRequest {

	/**
	 * Query/body parameters answered by getParam().
	 *
	 * @var array<string, mixed>
	 */
	private array $params;

	/**
	 * Raw request body returned by getContent().
	 *
	 * @var string
	 */
	private string $content;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $params Parameters for getParam()/getParams().
	 * @param string $content Raw JSON request body.
	 */
	public function __construct(array $params = [], string $content = '') {
		$this->params = $params;
		$this->content = $content;
	}//end __construct()

	/**
	 * Return a query/body parameter.
	 *
	 * @param string $key Parameter name.
	 * @param mixed $default Default when absent.
	 *
	 * @return mixed
	 */
	public function getParam(string $key, mixed $default = null): mixed {
		return ($this->params[$key] ?? $default);
	}//end getParam()

	/**
	 * Return all request parameters.
	 *
	 * @return array<string, mixed>
	 */
	public function getParams(): array {
		return $this->params;
	}//end getParams()

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
		return 'dso-contract-request';
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
		return '/apps/dossiq/api/dso/dashboard';
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
	 * @param array<int, string> $agent Agent strings to match.
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

	/**
	 * Return the raw request body content.
	 *
	 * @return string
	 */
	public function getContent(): string {
		return $this->content;
	}//end getContent()
}//end class

/**
 * Wire-contract tests for DsoController's dashboard/doorsturen/respond endpoints.
 *
 * @covers \OCA\Dossiq\Controller\DsoController
 */
class DsoControllerContractTest extends TestCase {

	/**
	 * The DsoCaseService mock (owner of the zaak mutation guard).
	 *
	 * @var DsoCaseService|MockObject
	 */
	private DsoCaseService $dsoCaseService;

	/**
	 * The BeschikkingGenerationService mock.
	 *
	 * @var BeschikkingGenerationService|MockObject
	 */
	private BeschikkingGenerationService $decisionService;

	/**
	 * The SamenwerkverzoekService mock.
	 *
	 * @var SamenwerkverzoekService|MockObject
	 */
	private SamenwerkverzoekService $samenwerkService;

	/**
	 * The OpenRegister read collaborator mock.
	 *
	 * @var DsoObjectRepository|MockObject
	 */
	private DsoObjectRepository $repository;

	/**
	 * The doorsturen event dispatcher mock.
	 *
	 * @var DsoDoorsturenNotifier|MockObject
	 */
	private DsoDoorsturenNotifier $doorsturenNotifier;

	/**
	 * The IUserSession mock.
	 *
	 * @var IUserSession|MockObject
	 */
	private IUserSession $userSession;

	/**
	 * The LoggerInterface mock.
	 *
	 * @var LoggerInterface|MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Build the shared collaborator mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->dsoCaseService = $this->createMock(DsoCaseService::class);
		$this->decisionService = $this->createMock(BeschikkingGenerationService::class);
		$this->samenwerkService = $this->createMock(SamenwerkverzoekService::class);
		$this->repository = $this->createMock(DsoObjectRepository::class);
		$this->doorsturenNotifier = $this->createMock(DsoDoorsturenNotifier::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Build the controller over a request carrying the supplied body/params.
	 *
	 * @param array<string, mixed> $params Query parameters.
	 * @param string $content Raw JSON body.
	 *
	 * @return DsoController The controller under test.
	 */
	private function controllerWith(array $params = [], string $content = ''): DsoController {
		return new DsoController(
			appName: 'dossiq',
			request: new DsoContractRequestStub(params: $params, content: $content),
			dsoCaseService: $this->dsoCaseService,
			decisionService: $this->decisionService,
			samenwerkService: $this->samenwerkService,
			repository: $this->repository,
			doorsturenNotifier: $this->doorsturenNotifier,
			userSession: $this->userSession,
			logger: $this->logger,
		);
	}//end controllerWith()

	/**
	 * Put a signed-in user on the session.
	 *
	 * @param string $uid The UID of the signed-in user.
	 *
	 * @return void
	 */
	private function signIn(string $uid = 'alice'): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}//end signIn()

	/**
	 * `dashboard` refuses an anonymous caller with 401 and reads nothing.
	 *
	 * @return void
	 */
	public function testDashboardRefusesAnUnauthenticatedCallerWith401(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->repository->expects($this->never())->method('fetchDashboard');

		$response = $this->controllerWith()->dashboard();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'Not authenticated'], $response->getData());
	}//end testDashboardRefusesAnUnauthenticatedCallerWith401()

	/**
	 * The dashboard query is scoped to omgevingsvergunning cases and paginated,
	 * and the caller's filters are passed through. An unscoped query would list
	 * the whole register while still rendering a plausible dashboard.
	 *
	 * @return void
	 */
	public function testDashboardScopesTheQueryToOmgevingsvergunningCases(): void {
		$this->signIn();
		$captured = [];

		$this->repository->expects($this->once())
			->method('fetchDashboard')
			->willReturnCallback(
				static function (
					array $params,
					string $activiteitgroep,
					string $regelkwalificatie,
					string $location,
				) use (&$captured): array {
					$captured = [
						'params' => $params,
						'activiteitgroep' => $activiteitgroep,
						'regelkwalificatie' => $regelkwalificatie,
						'location' => $location,
					];

					return ['error' => null, 'results' => [['id' => 'zaak-1'], ['id' => 'zaak-2']]];
				}
			);

		$response = $this->controllerWith(params: [
			'status' => 'in_handling',
			'activiteitgroep' => 'bouwen',
		])->dashboard();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(2, $response->getData()['count']);
		$this->assertSame('omgevingsvergunning', $captured['params']['caseType']);
		$this->assertSame('in_handling', $captured['params']['status']);
		$this->assertSame(100, $captured['params']['_limit']);
		$this->assertSame('bouwen', $captured['activiteitgroep']);
	}//end testDashboardScopesTheQueryToOmgevingsvergunningCases()

	/**
	 * An OpenRegister outage answers 503 with the repository's reason — not an
	 * empty 200 the operator would read as "no permits in progress".
	 *
	 * @return void
	 */
	public function testDashboardReturns503WhenOpenRegisterIsUnavailable(): void {
		$this->signIn();
		$this->repository->method('fetchDashboard')
			->willReturn(['error' => 'OpenRegister not available', 'results' => []]);

		$response = $this->controllerWith()->dashboard();

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame(['error' => 'OpenRegister not available'], $response->getData());
	}//end testDashboardReturns503WhenOpenRegisterIsUnavailable()

	/**
	 * Any other failure is logged and masked as a 500 — distinct from the 503
	 * above, which is an actionable deployment fact.
	 *
	 * @return void
	 */
	public function testDashboardMasksAnUnexpectedFailureAs500AndLogsIt(): void {
		$this->signIn();
		$this->repository->method('fetchDashboard')
			->willThrowException(new \RuntimeException('index corruption on zaken'));
		$this->logger->expects($this->once())->method('error');

		$response = $this->controllerWith()->dashboard();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'Could not load dashboard'], $response->getData());
	}//end testDashboardMasksAnUnexpectedFailureAs500AndLogsIt()

	/**
	 * `doorsturen` refuses an anonymous caller with 401 and dispatches nothing.
	 *
	 * @return void
	 */
	public function testDoorsturenRefusesAnUnauthenticatedCallerWith401(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->doorsturenNotifier->expects($this->never())->method('dispatchDoorgestuurd');

		$response = $this->controllerWith(content: '{"targetBevoegdGezag":"gm0518"}')
			->doorsturen(caseId: 'zaak-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'Not authenticated'], $response->getData());
	}//end testDoorsturenRefusesAnUnauthenticatedCallerWith401()

	/**
	 * A forward with no destination is a 400 — the case must not be marked
	 * doorgestuurd to nowhere.
	 *
	 * @return void
	 */
	public function testDoorsturenRejectsAMissingTargetBevoegdGezagWith400(): void {
		$this->signIn();
		$this->repository->expects($this->never())->method('findZaak');
		$this->doorsturenNotifier->expects($this->never())->method('dispatchDoorgestuurd');

		$response = $this->controllerWith(content: '{"reason":"niet bevoegd"}')
			->doorsturen(caseId: 'zaak-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'targetBevoegdGezag is required'], $response->getData());
	}//end testDoorsturenRejectsAMissingTargetBevoegdGezagWith400()

	/**
	 * An unknown case answers 404 and dispatches nothing.
	 *
	 * @return void
	 */
	public function testDoorsturenReturns404ForAnUnknownCase(): void {
		$this->signIn();
		$this->repository->method('findZaak')->willReturn(null);
		$this->doorsturenNotifier->expects($this->never())->method('dispatchDoorgestuurd');

		$response = $this->controllerWith(content: '{"targetBevoegdGezag":"gm0518"}')
			->doorsturen(caseId: 'does-not-exist');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'Case not found'], $response->getData());
	}//end testDoorsturenReturns404ForAnUnknownCase()

	/**
	 * A refused mutation answers 403 and — the load-bearing half — dispatches NO
	 * VergunningDoorgestuurd event. Downstream listeners act on that event, so
	 * emitting it on a refused forward would hand the permit case over anyway.
	 *
	 * @return void
	 */
	public function testDoorsturenDispatchesNoEventWhenTheMutationGuardRefuses(): void {
		$this->signIn(uid: 'mallory');
		$this->repository->method('findZaak')->willReturn(['id' => 'zaak-1', 'assigneeUserId' => 'alice']);
		$this->dsoCaseService->method('authorizeZaakMutation')
			->willThrowException(new \Exception('Not authorized'));
		$this->doorsturenNotifier->expects($this->never())->method('dispatchDoorgestuurd');

		$response = $this->controllerWith(content: '{"targetBevoegdGezag":"gm0518"}')
			->doorsturen(caseId: 'zaak-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['error' => 'Forbidden'], $response->getData());
	}//end testDoorsturenDispatchesNoEventWhenTheMutationGuardRefuses()

	/**
	 * An authorized forward dispatches the event with the case, destination,
	 * reason and acting user, and answers 200 with the confirmed destination.
	 *
	 * @return void
	 */
	public function testDoorsturenDispatchesTheForwardAndConfirmsTheDestination(): void {
		$this->signIn(uid: 'alice');
		$case = ['id' => 'zaak-1', 'assigneeUserId' => 'alice'];
		$this->repository->method('findZaak')->willReturn($case);

		$this->doorsturenNotifier->expects($this->once())
			->method('dispatchDoorgestuurd')
			->with($case, 'zaak-1', 'gm0518', 'niet bevoegd', 'alice');

		$response = $this->controllerWith(
			content: '{"targetBevoegdGezag":"gm0518","reason":"niet bevoegd"}'
		)->doorsturen(caseId: 'zaak-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			['status' => 'doorgestuurd', 'caseId' => 'zaak-1', 'targetBevoegdGezag' => 'gm0518'],
			$response->getData()
		);
	}//end testDoorsturenDispatchesTheForwardAndConfirmsTheDestination()

	/**
	 * A non-authorization failure is masked as a 500 with this endpoint's own
	 * message — it must not be reported as the 403 the guard uses.
	 *
	 * @return void
	 */
	public function testDoorsturenMasksAnUnexpectedFailureAs500(): void {
		$this->signIn();
		$this->repository->method('findZaak')->willReturn(['id' => 'zaak-1']);
		$this->dsoCaseService->method('authorizeZaakMutation')
			->willThrowException(new \Exception('event bus offline'));
		$this->logger->expects($this->once())->method('error');

		$response = $this->controllerWith(content: '{"targetBevoegdGezag":"gm0518"}')
			->doorsturen(caseId: 'zaak-1');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'Could not doorsturen'], $response->getData());
	}//end testDoorsturenMasksAnUnexpectedFailureAs500()

	/**
	 * `respondSamenwerking` refuses an anonymous caller with 401.
	 *
	 * @return void
	 */
	public function testRespondSamenwerkingRefusesAnUnauthenticatedCallerWith401(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->samenwerkService->expects($this->never())->method('respondToSamenwerking');

		$response = $this->controllerWith(content: '{"accept":true}')
			->respondSamenwerking(samenwerkId: 'sw-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'Not authenticated'], $response->getData());
	}//end testRespondSamenwerkingRefusesAnUnauthenticatedCallerWith401()

	/**
	 * An unknown samenwerkverzoek answers 404 and records no answer.
	 *
	 * @return void
	 */
	public function testRespondSamenwerkingReturns404ForAnUnknownVerzoek(): void {
		$this->signIn();
		$this->repository->method('findSamenwerkverzoek')->willReturn(null);
		$this->samenwerkService->expects($this->never())->method('respondToSamenwerking');

		$response = $this->controllerWith(content: '{"accept":true}')
			->respondSamenwerking(samenwerkId: 'nope');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'Samenwerkverzoek not found'], $response->getData());
	}//end testRespondSamenwerkingReturns404ForAnUnknownVerzoek()

	/**
	 * A refused mutation answers 403 and records no answer — an unauthorized
	 * "accept" would commit the organisation to a samenwerking.
	 *
	 * @return void
	 */
	public function testRespondSamenwerkingRecordsNothingWhenTheGuardRefuses(): void {
		$this->signIn(uid: 'mallory');
		$this->repository->method('findSamenwerkverzoek')->willReturn(['id' => 'sw-1']);
		$this->samenwerkService->method('authorizeSamenwerkMutation')
			->willThrowException(new \Exception('Not authorized'));
		$this->samenwerkService->expects($this->never())->method('respondToSamenwerking');

		$response = $this->controllerWith(content: '{"accept":true}')
			->respondSamenwerking(samenwerkId: 'sw-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['error' => 'Forbidden'], $response->getData());
	}//end testRespondSamenwerkingRecordsNothingWhenTheGuardRefuses()

	/**
	 * An authorized response forwards the decision and maps the `advice` body
	 * key onto the service's `advies` argument.
	 *
	 * @return void
	 */
	public function testRespondSamenwerkingForwardsTheDecisionAndTheAdviceBodyKey(): void {
		$this->signIn(uid: 'alice');
		$this->repository->method('findSamenwerkverzoek')->willReturn(['id' => 'sw-1']);

		$this->samenwerkService->expects($this->once())
			->method('respondToSamenwerking')
			->with('sw-1', true, 'Akkoord met voorwaarden')
			->willReturn(['id' => 'sw-1', 'status' => 'geaccepteerd']);

		$response = $this->controllerWith(
			content: '{"accept":true,"advice":"Akkoord met voorwaarden"}'
		)->respondSamenwerking(samenwerkId: 'sw-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['id' => 'sw-1', 'status' => 'geaccepteerd'], $response->getData());
	}//end testRespondSamenwerkingForwardsTheDecisionAndTheAdviceBodyKey()

	/**
	 * A rejection is forwarded as `accept: false` — defaulting a missing or
	 * false `accept` to true would silently approve every samenwerkverzoek.
	 *
	 * @return void
	 */
	public function testRespondSamenwerkingForwardsARejectionAsAcceptFalse(): void {
		$this->signIn(uid: 'alice');
		$this->repository->method('findSamenwerkverzoek')->willReturn(['id' => 'sw-1']);

		$this->samenwerkService->expects($this->once())
			->method('respondToSamenwerking')
			->with('sw-1', false, 'Buiten ons bevoegd gezag')
			->willReturn(['id' => 'sw-1', 'status' => 'geweigerd']);

		$response = $this->controllerWith(
			content: '{"accept":false,"advice":"Buiten ons bevoegd gezag"}'
		)->respondSamenwerking(samenwerkId: 'sw-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('geweigerd', $response->getData()['status']);
	}//end testRespondSamenwerkingForwardsARejectionAsAcceptFalse()
}//end class
