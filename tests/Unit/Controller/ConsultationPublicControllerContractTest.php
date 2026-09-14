<?php

/**
 * ConsultationPublicController Wire-Contract Tests
 *
 * Contract coverage for `POST /api/public/consultations/{token}` (gate-25) —
 * the endpoint an EXTERNAL advisory body posts its Awb 3:5-3:9 advice to. It
 * is `@PublicPage` + `@NoCSRFRequired`: there is no Nextcloud session and no
 * CSRF token, so the secure token is the entire authorization system and the
 * controller body is the only thing enforcing it. These tests pin:
 *
 *  - an empty token is a 400 raised BEFORE the token store is queried, so a
 *    blank token can never be matched against anything;
 *  - a token that resolves to nothing is a 404 and NO response is submitted —
 *    this is the branch that stops an anonymous caller stuffing an arbitrary
 *    consultation with fabricated advice, and it is asserted with a
 *    `never()` on `submitResponse()` rather than only on the status;
 *  - the advice is filed against the CONSULTATION ID the token resolved to,
 *    never against the token itself — the token is a caller-supplied string
 *    and using it as the record key would be an injection point;
 *  - a domain `RuntimeException` (e.g. a closed consultation) becomes a 400
 *    carrying the domain message, not a 500.
 *
 * NOTE ON THE ROUTE'S LIVENESS: dossiq's board records that the public
 * consultation-response PAGE renders "This page is empty" because its Vue
 * component is never registered. That is a frontend registration gap; the
 * BACKEND route is present in appinfo/routes.php and dispatches to this
 * method, so the endpoint is live and testable at the controller level.
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

use OCA\Dossiq\Controller\ConsultationPublicController;
use OCA\Dossiq\Service\ConsultationService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Concrete IRequest stub serving a raw body for the ConsultationPublic tests.
 *
 * `getContent()` is NOT declared on the OCP\IRequest interface, and it is NOT a
 * magic accessor either: `OC\AppFramework\Http\Request` declares it
 * `protected` and implements `__get`, not `__call`. So an outside call on the
 * real request is `Error: Call to protected method`, which is what made this
 * public endpoint answer 500 for every caller while this suite stayed green
 * over a stub whose `getContent()` is public. The controller now reads
 * `php://input` unless `is_callable()` says the method can really be called;
 * this stub keeps its public one so the decoding path stays drivable from a
 * test. The class name is prefixed with the controller name because sibling
 * contract suites define their own stubs in this same namespace.
 */
class ConsultationPublicControllerContractRequestStub implements IRequest {

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
		return 'consultation-public-contract-test';
	}//end getId()

	/**
	 * Return the remote address.
	 *
	 * @return string
	 */
	public function getRemoteAddress(): string {
		return '198.51.100.7';
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
		return '/apps/dossiq/api/public/consultations/tok';
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
 * Wire-contract tests for ConsultationPublicController::publicResponsePost().
 *
 * @covers \OCA\Dossiq\Controller\ConsultationPublicController
 */
class ConsultationPublicControllerContractTest extends TestCase {

	/**
	 * The consultation domain service.
	 *
	 * @var ConsultationService|MockObject
	 */
	private ConsultationService $consultationService;

	/**
	 * The BIO audit logger.
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

		$this->consultationService = $this->createMock(ConsultationService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Build the controller with the given raw request body.
	 *
	 * @param string $body The raw JSON body an external body would post.
	 *
	 * @return ConsultationPublicController
	 */
	private function controller(string $body = ''): ConsultationPublicController {
		return new ConsultationPublicController(
			appName: 'dossiq',
			request: new ConsultationPublicControllerContractRequestStub(content: $body),
			consultationService: $this->consultationService,
			logger: $this->logger,
		);
	}//end controller()

	/**
	 * An empty token is a 400 and the token store is never queried.
	 *
	 * @return void
	 */
	public function testPublicResponsePostRejectsAnEmptyTokenBeforeQueryingTheTokenStore(): void {
		$this->consultationService->expects($this->never())->method('findBySecureToken');
		$this->consultationService->expects($this->never())->method('submitResponse');

		$response = $this->controller()->publicResponsePost(token: '');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'Token is required'], $response->getData());
	}//end testPublicResponsePostRejectsAnEmptyTokenBeforeQueryingTheTokenStore()

	/**
	 * An unresolvable token is a 404 and NOTHING is submitted.
	 *
	 * This is the guard that keeps an anonymous caller from writing advice
	 * into a consultation it holds no token for.
	 *
	 * @return void
	 */
	public function testPublicResponsePostRefusesAnInvalidTokenAndSubmitsNothing(): void {
		$this->consultationService->expects($this->once())
			->method('findBySecureToken')
			->with('tok-vervalst')
			->willReturn(null);

		$this->consultationService->expects($this->never())->method('submitResponse');

		$response = $this->controller('{"advies":"positief"}')->publicResponsePost(token: 'tok-vervalst');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'Invalid or expired token'], $response->getData());
	}//end testPublicResponsePostRefusesAnInvalidTokenAndSubmitsNothing()

	/**
	 * The advice is filed against the consultation the token RESOLVED to, and
	 * the decoded body is forwarded as the response payload.
	 *
	 * @return void
	 */
	public function testPublicResponsePostFilesTheAdviceAgainstTheResolvedConsultationNotTheToken(): void {
		$this->consultationService->method('findBySecureToken')
			->willReturn(['id' => 'cn-88', 'secureToken' => 'tok-geldig', 'onderwerp' => 'Bestemmingsplan']);

		$stored = ['id' => 'cn-88', 'status' => 'advised'];

		$this->consultationService->expects($this->once())
			->method('submitResponse')
			->with('cn-88', ['advies' => 'positief', 'toelichting' => 'geen bezwaar'])
			->willReturn($stored);

		$response = $this->controller('{"advies":"positief","toelichting":"geen bezwaar"}')
			->publicResponsePost(token: 'tok-geldig');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($stored, $response->getData());
	}//end testPublicResponsePostFilesTheAdviceAgainstTheResolvedConsultationNotTheToken()

	/**
	 * An empty or unparseable body is forwarded as an empty array, never as
	 * a string or null, into the service's typed `array $response` parameter.
	 *
	 * @return void
	 */
	public function testPublicResponsePostForwardsAnEmptyArrayForAnUnparseableBody(): void {
		$this->consultationService->method('findBySecureToken')->willReturn(['id' => 'cn-88']);

		$this->consultationService->expects($this->once())
			->method('submitResponse')
			->with('cn-88', $this->identicalTo([]))
			->willReturn([]);

		$response = $this->controller('not-json-at-all')->publicResponsePost(token: 'tok-geldig');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testPublicResponsePostForwardsAnEmptyArrayForAnUnparseableBody()

	/**
	 * A domain refusal (a closed or already-answered consultation) is a 400
	 * carrying the domain message, not a 500.
	 *
	 * @return void
	 */
	public function testPublicResponsePostMapsADomainRefusalToA400WithItsMessage(): void {
		$this->consultationService->method('findBySecureToken')->willReturn(['id' => 'cn-88']);
		$this->consultationService->method('submitResponse')
			->willThrowException(new \RuntimeException('Consultation is already closed'));

		$response = $this->controller('{"advies":"positief"}')->publicResponsePost(token: 'tok-geldig');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'Consultation is already closed'], $response->getData());
	}//end testPublicResponsePostMapsADomainRefusalToA400WithItsMessage()

	/**
	 * A protected getContent(), which is what every real request has, must not
	 * look callable: that is the test the production path now makes before
	 * calling it, and the reason this endpoint answered 500 before.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-04
	 */
	public function testAProtectedGetContentIsNotCallableFromOutside(): void {
		$realShape = new class {
			/**
			 * Mirrors OC\AppFramework\Http\Request, which declares this protected.
			 *
			 * @return string The body.
			 */
			protected function getContent(): string {
				return '{"never":"reached"}';
			}
		};

		$this->assertFalse(
			is_callable([$realShape, 'getContent']),
			'a protected getContent() must not look callable, or the guard lets the 500 back in',
		);
		// And the stub this suite drives the controller with is public, which
		// is why the decoding path stays reachable from a test at all.
		$this->assertTrue(
			is_callable([new ConsultationPublicControllerContractRequestStub('{}'), 'getContent']),
		);
	}//end testAProtectedGetContentIsNotCallableFromOutside()

	/**
	 * A real request, whose getContent() cannot be called from outside, leaves
	 * the controller reading the stream: in a test that is empty, so the body
	 * decodes to nothing and the endpoint answers on its own terms rather than
	 * throwing.
	 *
	 * This is the path every production caller takes, and the one that used to
	 * be an Error before the fix. The suite's own stub cannot exercise it,
	 * because its getContent() is public.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-04
	 */
	public function testARequestWhoseGetContentIsUnreachableStillAnswers(): void {
		$controller = new ConsultationPublicController(
			appName: 'dossiq',
			request: $this->createMock(originalClassName: IRequest::class),
			consultationService: $this->consultationService,
			logger: $this->logger,
		);
		$this->consultationService->expects($this->never())->method('findBySecureToken');

		$response = $controller->publicResponsePost(token: '');

		// No Error, no 500: the empty token is refused on its own merits.
		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus());
		$this->assertSame(expected: ['error' => 'Token is required'], actual: $response->getData());
	}//end testARequestWhoseGetContentIsUnreachableStillAnswers()
}//end class
