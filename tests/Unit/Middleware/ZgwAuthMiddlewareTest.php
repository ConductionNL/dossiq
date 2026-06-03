<?php

/**
 * ZgwAuthMiddleware Unit Tests
 *
 * Tests for the ZGW authentication middleware.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Middleware
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Middleware;

use OCA\Procest\Middleware\ZgwAuthMiddleware;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for the ZgwAuthMiddleware class.
 *
 * @covers \OCA\Procest\Middleware\ZgwAuthMiddleware
 * @uses \OCA\Procest\Middleware\ZgwAuthException
 */
class ZgwAuthMiddlewareTest extends TestCase
{

    /**
     * The mocked request interface.
     *
     * @var IRequest|\PHPUnit\Framework\MockObject\MockObject
     */
    private IRequest $request;

    /**
     * The mocked logger interface.
     *
     * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private LoggerInterface $logger;

    /**
     * The middleware under test.
     *
     * @var ZgwAuthMiddleware
     */
    private ZgwAuthMiddleware $middleware;


    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->request = $this->createMock(IRequest::class);
        $this->logger  = $this->createMock(LoggerInterface::class);

        $this->middleware = new ZgwAuthMiddleware(
            $this->request,
            $this->logger,
        );

    }//end setUp()


    /**
     * Test that isConfidentialityAllowed returns true for equal levels.
     *
     * @return void
     */
    public function testConfidentialityEqualLevelAllowed(): void
    {
        $this->assertTrue(
            $this->middleware->isConfidentialityAllowed('openbaar', 'openbaar')
        );

    }//end testConfidentialityEqualLevelAllowed()


    /**
     * Test that isConfidentialityAllowed returns true when actual is below max.
     *
     * @return void
     */
    public function testConfidentialityBelowMaxAllowed(): void
    {
        $this->assertTrue(
            $this->middleware->isConfidentialityAllowed('openbaar', 'vertrouwelijk')
        );

    }//end testConfidentialityBelowMaxAllowed()


    /**
     * Test that isConfidentialityAllowed returns false when actual exceeds max.
     *
     * @return void
     */
    public function testConfidentialityAboveMaxDenied(): void
    {
        $this->assertFalse(
            $this->middleware->isConfidentialityAllowed('zeer_geheim', 'vertrouwelijk')
        );

    }//end testConfidentialityAboveMaxDenied()


    /**
     * Test that isConfidentialityAllowed returns false for unknown levels.
     *
     * @return void
     */
    public function testConfidentialityUnknownLevelDenied(): void
    {
        $this->assertFalse(
            $this->middleware->isConfidentialityAllowed('unknown', 'openbaar')
        );

    }//end testConfidentialityUnknownLevelDenied()


    /**
     * Test that beforeController skips non-ZgwController instances.
     *
     * @return void
     */
    public function testBeforeControllerSkipsNonZgwController(): void
    {
        $controller = $this->createMock(\OCP\AppFramework\Controller::class);

        // Should not throw — non-ZGW controllers are skipped.
        $this->middleware->beforeController($controller, 'index');
        $this->assertTrue(true);

    }//end testBeforeControllerSkipsNonZgwController()


    /**
     * Test that ZgwController is abstract and cannot be instantiated directly.
     *
     * C3: verifies the marker class exists and is abstract so concrete ZGW
     * controllers can extend it and the instanceof check in the middleware fires.
     *
     * @return void
     */
    public function testZgwControllerIsAbstract(): void
    {
        $reflection = new \ReflectionClass(\OCA\Procest\Controller\ZgwController::class);
        $this->assertTrue(
            $reflection->isAbstract(),
            'ZgwController must be abstract — it is an instanceof marker only'
        );

    }//end testZgwControllerIsAbstract()


    /**
     * Test that concrete ZGW controllers extend ZgwController.
     *
     * C3: The middleware fires only for instanceof ZgwController. Verifying
     * the inheritance chain ensures no ZGW endpoint can accidentally bypass it.
     *
     * @return void
     */
    public function testZgwControllersExtendBase(): void
    {
        $zgwControllers = [
            \OCA\Procest\Controller\AcController::class,
            \OCA\Procest\Controller\ZrcController::class,
            \OCA\Procest\Controller\ZtcController::class,
            \OCA\Procest\Controller\DrcController::class,
            \OCA\Procest\Controller\BrcController::class,
            \OCA\Procest\Controller\NrcController::class,
        ];

        foreach ($zgwControllers as $class) {
            $this->assertTrue(
                is_subclass_of($class, \OCA\Procest\Controller\ZgwController::class),
                "$class must extend ZgwController so middleware applies"
            );
        }

    }//end testZgwControllersExtendBase()


    /**
     * Test that deriveComponentFromUrl extracts the correct component from each
     * ZGW API group URL (SB1 regression: getParam('zgwApi') was unreachable dead code).
     *
     * @return void
     *
     * @dataProvider provideZgwUriToComponent
     */
    public function testDeriveComponentFromUrlCoversAllApiGroups(
        string $uri,
        ?string $expectedComponent
    ): void {
        // Access the private method via reflection.
        $reflMethod = new \ReflectionMethod(ZgwAuthMiddleware::class, 'deriveComponentFromUrl');
        $reflMethod->setAccessible(true);

        $this->request
            ->method('getRequestUri')
            ->willReturn($uri);

        $component = $reflMethod->invoke($this->middleware);
        $this->assertSame($expectedComponent, $component);

    }//end testDeriveComponentFromUrlCoversAllApiGroups()


    /**
     * Data provider: URI path → expected component code (or null for unknown).
     *
     * @return array<string, array{0: string, 1: string|null}>
     */
    public static function provideZgwUriToComponent(): array
    {
        return [
            'zrc (zaken)'       => ['/index.php/apps/procest/api/zgw/zaken/v1/zaken', 'zrc'],
            'ztc (catalogi)'    => ['/apps/procest/api/zgw/catalogi/v1/zaaktypen', 'ztc'],
            'brc (besluiten)'   => ['/index.php/apps/procest/api/zgw/besluiten/v1/besluiten', 'brc'],
            'drc (documenten)'  => ['/apps/procest/api/zgw/documenten/v1/enkelvoudiginformatieobjecten', 'drc'],
            'nrc (notificaties)' => ['/apps/procest/api/zgw/notificaties/v1/kanalen', 'nrc'],
            'ac (autorisaties)' => ['/apps/procest/api/zgw/autorisaties/v1/applicaties', 'ac'],
            'unknown api group' => ['/apps/procest/api/zgw/unknown/v1/resources', null],
            'non-zgw path'      => ['/apps/procest/api/settings', null],
            'empty path'        => ['', null],
        ];
    }//end provideZgwUriToComponent()


    /**
     * Test that afterException returns null for non-ZgwAuthException.
     *
     * @return void
     */
    public function testAfterExceptionReturnsNullForGenericException(): void
    {
        $controller = $this->createMock(\OCP\AppFramework\Controller::class);
        $exception  = new \RuntimeException('generic error');

        $result = $this->middleware->afterException($controller, 'index', $exception);

        $this->assertNull($result);

    }//end testAfterExceptionReturnsNullForGenericException()


    /**
     * Test that afterException returns JSONResponse for ZgwAuthException.
     *
     * @return void
     */
    public function testAfterExceptionReturnsJsonForZgwAuthException(): void
    {
        $controller = $this->createMock(\OCP\AppFramework\Controller::class);
        $exception  = new \OCA\Procest\Middleware\ZgwAuthException(
            'test error',
            403
        );

        $result = $this->middleware->afterException($controller, 'index', $exception);

        $this->assertInstanceOf(
            \OCP\AppFramework\Http\JSONResponse::class,
            $result
        );

    }//end testAfterExceptionReturnsJsonForZgwAuthException()


    /**
     * Test confidentiality ordering is correct across all levels.
     *
     * @return void
     */
    public function testConfidentialityOrderingComplete(): void
    {
        $levels = [
            'openbaar',
            'beperkt_openbaar',
            'intern',
            'zaakvertrouwelijk',
            'vertrouwelijk',
            'confidentieel',
            'geheim',
            'zeer_geheim',
        ];

        // Each level should be allowed at its own level and above.
        foreach ($levels as $i => $level) {
            $this->assertTrue(
                $this->middleware->isConfidentialityAllowed($level, 'zeer_geheim'),
                "$level should be allowed at zeer_geheim"
            );
        }

        // Highest level should only be allowed at its own level.
        $this->assertTrue(
            $this->middleware->isConfidentialityAllowed('zeer_geheim', 'zeer_geheim')
        );
        $this->assertFalse(
            $this->middleware->isConfidentialityAllowed('zeer_geheim', 'geheim')
        );

    }//end testConfidentialityOrderingComplete()


}//end class
