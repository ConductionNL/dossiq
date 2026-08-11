<?php

/**
 * DsoController Unit Tests
 *
 * Tests for the DSO Omgevingsloket controller. Covers happy-path responses
 * and authorization-failure handling for the key workflow endpoints.
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
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T06
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Controller;

use OCA\Procest\Controller\DsoController;
use OCA\Procest\Service\BeschikkingGenerationService;
use OCA\Procest\Service\Dso\DsoDoorsturenNotifier;
use OCA\Procest\Service\Dso\DsoObjectRepository;
use OCA\Procest\Service\DsoCaseService;
use OCA\Procest\Service\SamenwerkverzoekService;
use OCA\Procest\Service\SettingsService;
use OCP\AppFramework\Http;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Minimal ObjectService stub with named-parameter signatures for DsoController tests.
 * Using named arguments on a \stdClass-based mock would fail at call time.
 */
interface DsoControllerObjectServiceStub
{
    /**
     * Find a single object by ID (real ObjectService::find()).
     *
     * @param int|string $id     Object UUID
     * @param mixed      ...$args Remaining find() args (extend/files/register/schema).
     *
     * @return mixed
     */
    public function find(int | string $id, ...$args): mixed;

    /**
     * Save or update an object.
     *
     * @param string              $register Register slug
     * @param string              $schema   Schema slug
     * @param array<string,mixed> $object   Object data
     *
     * @return array<string,mixed>
     */
    public function saveObject(string $register, string $schema, array $object): array;
}//end interface

/**
 * Concrete IRequest stub for the DsoController tests. The OCP IRequest mock
 * cannot configure getContent() (it is a magic property on the real NC request),
 * so a concrete stub is used to exercise the controller body. An anonymous class
 * would violate PHPCS doc-comment rules.
 */
class DsoControllerRequestStub implements IRequest
{

    /**
     * Optional override for getParam() return values, keyed by parameter name.
     *
     * @var array<string,mixed>
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
     * @param array<string,mixed> $params  Optional getParam overrides.
     * @param string              $content Raw request body for getContent().
     */
    public function __construct(array $params=[], string $content='')
    {
        $this->params  = $params;
        $this->content = $content;
    }//end __construct()

    /**
     * Return a query/body parameter.
     *
     * @param string $key     Parameter name
     * @param mixed  $default Default when absent
     *
     * @return mixed
     */
    public function getParam(string $key, mixed $default=null): mixed
    {
        if (array_key_exists($key, $this->params) === true) {
            return $this->params[$key];
        }

        return match ($key) {
            'newStatus'    => 'in_behandeling',
            'besluitdatum' => null,
            'toelichting'  => null,
            default        => $default,
        };
    }//end getParam()

    /**
     * Return a request header value by name.
     *
     * @param string $name Header name
     *
     * @return string
     */
    public function getHeader(string $name): string
    {
        return '';
    }//end getHeader()

    /**
     * Return all request parameters.
     *
     * @return array<string,mixed>
     */
    public function getParams(): array
    {
        return [];
    }//end getParams()

    /**
     * Return the HTTP method.
     *
     * @return string
     */
    public function getMethod(): string
    {
        return 'POST';
    }//end getMethod()

    /**
     * Return an uploaded file by key.
     *
     * @param string $key File field name
     *
     * @return mixed
     */
    public function getUploadedFile(string $key): mixed
    {
        return null;
    }//end getUploadedFile()

    /**
     * Return a server environment variable.
     *
     * @param string $key Variable name
     *
     * @return mixed
     */
    public function getEnv(string $key): mixed
    {
        return null;
    }//end getEnv()

    /**
     * Return a cookie value by name.
     *
     * @param string $key Cookie name
     *
     * @return mixed
     */
    public function getCookie(string $key): mixed
    {
        return null;
    }//end getCookie()

    /**
     * Return whether this request passes a CSRF check.
     *
     * @return bool
     */
    public function passesCSRFCheck(): bool
    {
        return true;
    }//end passesCSRFCheck()

    /**
     * Return whether this request passes a strict cookie check.
     *
     * @return bool
     */
    public function passesStrictCookieCheck(): bool
    {
        return true;
    }//end passesStrictCookieCheck()

    /**
     * Return whether this request passes a lax cookie check.
     *
     * @return bool
     */
    public function passesLaxCookieCheck(): bool
    {
        return true;
    }//end passesLaxCookieCheck()

    /**
     * Return the unique request ID.
     *
     * @return string
     */
    public function getId(): string
    {
        return 'test-request';
    }//end getId()

    /**
     * Return the remote IP address.
     *
     * @return string
     */
    public function getRemoteAddress(): string
    {
        return '127.0.0.1';
    }//end getRemoteAddress()

    /**
     * Return the server protocol (e.g. HTTP/1.1).
     *
     * @return string
     */
    public function getServerProtocol(): string
    {
        return 'HTTP/1.1';
    }//end getServerProtocol()

    /**
     * Return the HTTP scheme (http or https).
     *
     * @return string
     */
    public function getHttpProtocol(): string
    {
        return 'http';
    }//end getHttpProtocol()

    /**
     * Return the full request URI.
     *
     * @return string
     */
    public function getRequestUri(): string
    {
        return '/apps/procest/api/dso/cases/case-123/transition';
    }//end getRequestUri()

    /**
     * Return the raw path info segment.
     *
     * @return string
     */
    public function getRawPathInfo(): string
    {
        return '';
    }//end getRawPathInfo()

    /**
     * Return the decoded path info segment.
     *
     * @return mixed
     */
    public function getPathInfo(): mixed
    {
        return '';
    }//end getPathInfo()

    /**
     * Return the script name.
     *
     * @return string
     */
    public function getScriptName(): string
    {
        return '';
    }//end getScriptName()

    /**
     * Return whether the request originates from the given user agent(s).
     *
     * @param array<int,string> $agent Agent strings to match
     *
     * @return bool
     */
    public function isUserAgent(array $agent): bool
    {
        return false;
    }//end isUserAgent()

    /**
     * Return the insecure (HTTP) server host.
     *
     * @return string
     */
    public function getInsecureServerHost(): string
    {
        return 'localhost';
    }//end getInsecureServerHost()

    /**
     * Return the server host (potentially HTTPS).
     *
     * @return string
     */
    public function getServerHost(): string
    {
        return 'localhost';
    }//end getServerHost()

    /**
     * Throw if a JSON decode error occurred during body parsing.
     *
     * @return void
     */
    public function throwDecodingExceptionIfAny(): void
    {
    }//end throwDecodingExceptionIfAny()

    /**
     * Return the requested response format.
     *
     * @return string|null
     */
    public function getFormat(): ?string
    {
        return null;
    }//end getFormat()

    /**
     * Return the raw request body content.
     *
     * @return string
     */
    public function getContent(): string
    {
        return $this->content;
    }//end getContent()
}//end class

/**
 * Unit tests for DsoController.
 *
 * @covers \OCA\Procest\Controller\DsoController
 *
 * @uses \OCA\Procest\Service\Dso\DsoDoorsturenNotifier
 * @uses \OCA\Procest\Service\Dso\DsoObjectRepository
 * @uses \OCA\Procest\Service\Support\SearchesObjects
 */
class DsoControllerTest extends TestCase
{

    /**
     * The IRequest mock.
     *
     * @var IRequest|MockObject
     */
    private IRequest $request;

    /**
     * The DsoCaseService mock.
     *
     * @var DsoCaseService|MockObject
     */
    private DsoCaseService $dsoCaseService;

    /**
     * The BeschikkingGenerationService mock.
     *
     * @var BeschikkingGenerationService|MockObject
     */
    private BeschikkingGenerationService $beschikkingService;

    /**
     * The SamenwerkverzoekService mock.
     *
     * @var SamenwerkverzoekService|MockObject
     */
    private SamenwerkverzoekService $samenwerkService;

    /**
     * The IUserSession mock.
     *
     * @var IUserSession|MockObject
     */
    private IUserSession $userSession;

    /**
     * The SettingsService mock.
     *
     * @var SettingsService|MockObject
     */
    private SettingsService $settingsService;

    /**
     * The DsoLvAuthService mock.
     *
     * @var \OCA\Procest\Service\DsoLvAuthService|MockObject
     */
    private \OCA\Procest\Service\DsoLvAuthService $lvAuthService;

    /**
     * The IEventDispatcher mock.
     *
     * @var IEventDispatcher|MockObject
     */
    private IEventDispatcher $eventDispatcher;

    /**
     * The LoggerInterface mock.
     *
     * @var LoggerInterface|MockObject
     */
    private LoggerInterface $logger;

    /**
     * The controller under test.
     *
     * @var DsoController
     */
    private DsoController $controller;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->request            = new DsoControllerRequestStub();
        $this->dsoCaseService     = $this->createMock(DsoCaseService::class);
        $this->beschikkingService = $this->createMock(BeschikkingGenerationService::class);
        $this->samenwerkService   = $this->createMock(SamenwerkverzoekService::class);
        $this->userSession        = $this->createMock(IUserSession::class);
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->lvAuthService   = $this->createMock(\OCA\Procest\Service\DsoLvAuthService::class);
        $this->eventDispatcher = $this->createMock(IEventDispatcher::class);
        $this->logger          = $this->createMock(LoggerInterface::class);

        $this->controller = new DsoController(
            appName: 'procest',
            request: $this->request,
            dsoCaseService: $this->dsoCaseService,
            beschikkingService: $this->beschikkingService,
            samenwerkService: $this->samenwerkService,
            repository: new DsoObjectRepository(
                settingsService: $this->settingsService,
                logger: $this->logger,
            ),
            doorsturenNotifier: new DsoDoorsturenNotifier(eventDispatcher: $this->eventDispatcher),
            userSession: $this->userSession,
            logger: $this->logger,
        );
    }//end setUp()

    /**
     * Test that transitionStatus returns 401 when user is not authenticated.
     *
     * @return void
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#T06
     */
    public function testTransitionStatusReturns401WhenUnauthenticated(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $response = $this->controller->transitionStatus(caseId: 'case-123');

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }//end testTransitionStatusReturns401WhenUnauthenticated()

    /**
     * Test that transitionStatus returns 400 when newStatus is missing.
     *
     * @return void
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#T06
     */
    public function testTransitionStatusReturns400WhenStatusMissing(): void
    {
        $userMock = $this->createMock(IUser::class);
        $userMock->method('getUID')->willReturn('user1');
        $this->userSession->method('getUser')->willReturn($userMock);

        // getParam('newStatus') returns null (default mock) → empty string → 400.
        $response = $this->controller->transitionStatus(caseId: 'case-123');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }//end testTransitionStatusReturns400WhenStatusMissing()

    /**
     * Test that transitionStatus returns 403 when authorization check fails.
     *
     * Uses a concrete anonymous IRequest so the container-backed server array
     * is accessible. The controller resolves ObjectService from the container
     * to load the zaak; the mock authorizeZaakMutation then throws 'Not authorized'.
     *
     * @return void
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#T06
     */
    public function testTransitionStatusReturns403WhenNotAuthorized(): void
    {
        $userMock = $this->createMock(IUser::class);
        $userMock->method('getUID')->willReturn('user1');
        $this->userSession->method('getUser')->willReturn($userMock);

        $mockObjectService = $this->createMock(DsoControllerObjectServiceStub::class);
        $mockObjectService->method('find')
            ->willReturn(['id' => 'case-123', 'assigneeUserId' => 'other_user']);

        $settingsServiceMock = $this->createMock(SettingsService::class);
        $settingsServiceMock->method('getObjectService')->willReturn($mockObjectService);
        // phpcs:disable CustomSn.Functions.NamedParameters
        $settingsServiceMock->method('getConfigValue')->willReturn('procest-register');
        // phpcs:enable CustomSn.Functions.NamedParameters

        $requestStub = new DsoControllerRequestStub(content: '{"newStatus":"in_behandeling"}');

        $controller = new DsoController(
            appName: 'procest',
            request: $requestStub,
            dsoCaseService: $this->dsoCaseService,
            beschikkingService: $this->beschikkingService,
            samenwerkService: $this->samenwerkService,
            repository: new DsoObjectRepository(
                settingsService: $settingsServiceMock,
                logger: $this->logger,
            ),
            doorsturenNotifier: new DsoDoorsturenNotifier(eventDispatcher: $this->eventDispatcher),
            userSession: $this->userSession,
            logger: $this->logger,
        );

        $this->dsoCaseService
            ->method('authorizeZaakMutation')
            ->willThrowException(new \Exception('Not authorized'));

        $response = $controller->transitionStatus(caseId: 'case-123');

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }//end testTransitionStatusReturns403WhenNotAuthorized()

    /**
     * Test that generateBeschikking returns 400 when outcome is missing.
     *
     * @return void
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#T06
     */
    public function testGenerateBeschikkingReturns400WhenOutcomeMissing(): void
    {
        $userMock = $this->createMock(IUser::class);
        $userMock->method('getUID')->willReturn('user1');
        $this->userSession->method('getUser')->willReturn($userMock);

        $requestStub = new DsoControllerRequestStub(content: '{"motivation":"test"}');

        $controller = new DsoController(
            appName: 'procest',
            request: $requestStub,
            dsoCaseService: $this->dsoCaseService,
            beschikkingService: $this->beschikkingService,
            samenwerkService: $this->samenwerkService,
            repository: new DsoObjectRepository(
                settingsService: $this->settingsService,
                logger: $this->logger,
            ),
            doorsturenNotifier: new DsoDoorsturenNotifier(eventDispatcher: $this->eventDispatcher),
            userSession: $this->userSession,
            logger: $this->logger,
        );

        $response = $controller->generateBeschikking(caseId: 'case-123');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }//end testGenerateBeschikkingReturns400WhenOutcomeMissing()
}//end class
