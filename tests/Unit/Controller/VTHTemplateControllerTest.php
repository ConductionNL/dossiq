<?php

/**
 * VTHTemplateController Unit Tests
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
 * @spec openspec/changes/vth-module/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Controller;

use OCA\Procest\Controller\VTHTemplateController;
use OCA\Procest\Service\VTHTemplateService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for VTHTemplateController.
 *
 * @covers \OCA\Procest\Controller\VTHTemplateController
 */
class VTHTemplateControllerTest extends TestCase
{

    /**
     * @var VTHTemplateService|\PHPUnit\Framework\MockObject\MockObject
     */
    private VTHTemplateService $vthTemplateService;

    /**
     * @var IRequest|\PHPUnit\Framework\MockObject\MockObject
     */
    private IRequest $request;

    /**
     * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private LoggerInterface $logger;

    /**
     * @var VTHTemplateController
     */
    private VTHTemplateController $controller;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->vthTemplateService = $this->createMock(VTHTemplateService::class);
        $this->request            = $this->createMock(IRequest::class);
        $this->logger             = $this->createMock(LoggerInterface::class);

        $this->controller = new VTHTemplateController(
            appName: 'procest',
            request: $this->request,
            vthTemplateService: $this->vthTemplateService,
            logger: $this->logger,
        );
    }//end setUp()

    /**
     * Test that index returns 200 with template list.
     *
     * @return void
     *
     * @spec openspec/changes/vth-module/tasks.md#task-2
     */
    public function testIndexReturns200WithTemplates(): void
    {
        $templates = [
            ['id' => 'vth-omgevingsvergunning', 'title' => 'Omgevingsvergunning', 'category' => 'vth', 'version' => '1.0.0'],
            ['id' => 'vth-toezichtzaak', 'title' => 'Toezichtzaak', 'category' => 'vth', 'version' => '1.0.0'],
            ['id' => 'vth-handhavingszaak', 'title' => 'Handhavingszaak', 'category' => 'vth', 'version' => '1.0.0'],
        ];

        $this->vthTemplateService
            ->method('listTemplates')
            ->willReturn($templates);

        $response = $this->controller->index();

        $this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
        $data = $response->getData();
        $this->assertCount(expectedCount: 3, haystack: $data);
    }//end testIndexReturns200WithTemplates()

    /**
     * Test that activate returns 200 on successful activation.
     *
     * @return void
     *
     * @spec openspec/changes/vth-module/tasks.md#task-2
     */
    public function testActivateReturns200OnSuccess(): void
    {
        $this->vthTemplateService
            ->method('activateTemplate')
            ->willReturn(['caseTypeId' => 'uuid-1', 'template' => 'vth-omgevingsvergunning', 'counts' => []]);

        $response = $this->controller->activate(slug: 'vth-omgevingsvergunning');

        $this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
    }//end testActivateReturns200OnSuccess()

    /**
     * Test that activate returns 500 when service throws.
     *
     * @return void
     *
     * @spec openspec/changes/vth-module/tasks.md#task-2
     */
    public function testActivateReturns500OnServiceException(): void
    {
        $this->vthTemplateService
            ->method('activateTemplate')
            ->willThrowException(new RuntimeException('VTH template not found: vth-bad'));

        $response = $this->controller->activate(slug: 'vth-bad');

        $this->assertSame(expected: Http::STATUS_INTERNAL_SERVER_ERROR, actual: $response->getStatus());
    }//end testActivateReturns500OnServiceException()
}//end class
