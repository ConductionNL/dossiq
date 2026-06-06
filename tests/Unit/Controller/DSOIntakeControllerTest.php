<?php

/**
 * DSOIntakeController Unit Tests
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/vth-module/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Controller;

use OCA\Procest\Controller\DSOIntakeController;
use OCA\Procest\Service\DsoIntakeService;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for DSOIntakeController.
 *
 * @covers \OCA\Procest\Controller\DSOIntakeController
 */
class DSOIntakeControllerTest extends TestCase
{

    /**
     * @var DsoIntakeService|\PHPUnit\Framework\MockObject\MockObject
     */
    private DsoIntakeService $dsoIntakeService;

    /**
     * @var IRequest|\PHPUnit\Framework\MockObject\MockObject
     */
    private IRequest $request;

    /**
     * @var IAppConfig|\PHPUnit\Framework\MockObject\MockObject
     */
    private IAppConfig $appConfig;

    /**
     * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private LoggerInterface $logger;

    /**
     * @var DSOIntakeController
     */
    private DSOIntakeController $controller;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->dsoIntakeService = $this->createMock(DsoIntakeService::class);
        $this->request          = $this->createMock(IRequest::class);
        $this->appConfig        = $this->createMock(IAppConfig::class);
        $this->logger           = $this->createMock(LoggerInterface::class);

        // Default: no DSO webhook secret configured, so signature validation
        // is skipped (returns the supplied default unchanged).
        $this->appConfig->method('getValueString')
            ->willReturnCallback(static fn (string $app, string $key, string $default = '', bool $lazy = false): string => $default);

        $this->controller = new DSOIntakeController(
            appName: 'procest',
            request: $this->request,
            dsoIntakeService: $this->dsoIntakeService,
            appConfig: $this->appConfig,
            logger: $this->logger,
        );
    }//end setUp()

    /**
     * Test that the DSOIntakeController can be instantiated with expected dependencies.
     *
     * @return void
     *
     * @spec openspec/changes/vth-module/tasks.md#task-3
     */
    public function testControllerCanBeInstantiated(): void
    {
        $this->assertInstanceOf(DSOIntakeController::class, $this->controller);
    }//end testControllerCanBeInstantiated()

    /**
     * Test that the controller uses a public endpoint attribute.
     *
     * Verifies that the class has a PublicPage attribute or @PublicPage annotation
     * on the intake method, confirming it is accessible without authentication.
     *
     * @return void
     *
     * @spec openspec/changes/vth-module/tasks.md#task-3
     */
    public function testIntakeMethodIsPublicPage(): void
    {
        $reflection = new \ReflectionMethod(objectOrMethod: DSOIntakeController::class, method: 'intake');
        $attributes = $reflection->getAttributes();

        $attributeNames = array_map(
            static fn ($attr) => $attr->getName(),
            $attributes
        );

        $this->assertContains(
            needle: 'OCP\AppFramework\Http\Attribute\PublicPage',
            haystack: $attributeNames,
            message: 'intake() must have #[PublicPage] attribute'
        );
    }//end testIntakeMethodIsPublicPage()

    /**
     * Test that the controller uses NoCSRFRequired on intake.
     *
     * @return void
     *
     * @spec openspec/changes/vth-module/tasks.md#task-3
     */
    public function testIntakeMethodIsNoCSRFRequired(): void
    {
        $reflection = new \ReflectionMethod(objectOrMethod: DSOIntakeController::class, method: 'intake');
        $attributes = $reflection->getAttributes();

        $attributeNames = array_map(
            static fn ($attr) => $attr->getName(),
            $attributes
        );

        $this->assertContains(
            needle: 'OCP\AppFramework\Http\Attribute\NoCSRFRequired',
            haystack: $attributeNames,
            message: 'intake() must have #[NoCSRFRequired] attribute'
        );
    }//end testIntakeMethodIsNoCSRFRequired()

    /**
     * Test that the intake method is callable and returns a JSONResponse.
     *
     * Uses a request mock where the header method returns empty for signature check.
     *
     * @return void
     *
     * @spec openspec/changes/vth-module/tasks.md#task-3
     */
    public function testIntakeReturnsJsonResponse(): void
    {
        $this->request->method('getHeader')->willReturn('');
        $this->request->method('getParams')->willReturn(['activiteiten' => [], 'procedureType' => 'regulier']);

        $response = $this->controller->intake();

        $this->assertInstanceOf(
            expected: \OCP\AppFramework\Http\JSONResponse::class,
            actual: $response
        );
    }//end testIntakeReturnsJsonResponse()
}//end class
