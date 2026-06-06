<?php

/**
 * SamenwerkverzoekService Unit Tests
 *
 * Tests for the samenwerkverzoek lifecycle service, covering samenwerking
 * initiation, accept/reject responses, and per-object authorization.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T13
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\SamenwerkverzoekService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for SamenwerkverzoekService.
 *
 * @covers \OCA\Procest\Service\SamenwerkverzoekService
 */
class SamenwerkverzoekServiceTest extends TestCase
{

    /**
     * The IAppConfig mock.
     *
     * @var IAppConfig|MockObject
     */
    private IAppConfig $appConfig;

    /**
     * The ContainerInterface mock.
     *
     * @var ContainerInterface|MockObject
     */
    private ContainerInterface $container;

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
     * The service under test.
     *
     * @var SamenwerkverzoekService
     */
    private SamenwerkverzoekService $service;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->appConfig       = $this->createMock(IAppConfig::class);
        $this->container       = $this->createMock(ContainerInterface::class);
        $this->eventDispatcher = $this->createMock(IEventDispatcher::class);
        $this->logger          = $this->createMock(LoggerInterface::class);

        $this->service = new SamenwerkverzoekService(
            appConfig: $this->appConfig,
            container: $this->container,
            eventDispatcher: $this->eventDispatcher,
            logger: $this->logger,
        );
    }//end setUp()

    /**
     * Test that initiateSamenwerking creates a samenwerkverzoek object.
     *
     * The service must call ObjectService::saveObject with status 'aangevraagd'
     * and dispatch a SamenwerkverzoekInitiated event.
     *
     * @return void
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#T13
     */
    public function testInitiateSamenwerkingCreatesObject(): void
    {
        $objectServiceMock = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['findObject', 'saveObject'])
            ->getMock();

        $zaak = [
            'id'                    => 'zaak-uuid-1',
            'vergunningaanvraagRef' => 'aanvraag-uuid-1',
        ];

        $objectServiceMock
            ->expects($this->once())
            ->method('findObject')
            ->willReturn($zaak);

        $expectedSamenwerkverzoek = [
            'id'                     => 'samenwerk-uuid-1',
            'status'                 => 'aangevraagd',
            'aangezochtBevoegdGezag' => 'gemeente-haarlem',
        ];

        $objectServiceMock
            ->expects($this->once())
            ->method('saveObject')
            ->with(
                $this->equalTo('procest-register'),
                $this->anything(),
                $this->callback(
                        function (array $obj) {
                            return ($obj['status'] ?? '') === 'aangevraagd';
                        }
                        )
            )
            ->willReturn($expectedSamenwerkverzoek);

        $this->container
            ->method('get')
            ->willReturnCallback(
                    function (string $id) use ($objectServiceMock) {
                        if ($id === 'OCA\OpenRegister\Service\ObjectService') {
                            return $objectServiceMock;
                        }

                        return null;
                    }
                    );

        $this->appConfig
            ->method('getValueString')
            ->willReturnCallback(
                    function (string $app, string $key, string $default='') {
                        $map = [
                            'register'                    => 'procest-register',
                            'case_schema'                 => 'case-schema-id',
                            'dso_samenwerkverzoek_schema' => 'samenwerk-schema-id',
                        ];
                        return $map[$key] ?? $default;
                    }
                    );

        $this->eventDispatcher->expects($this->once())->method('dispatch');
        $this->logger->expects($this->once())->method('info');

        $result = $this->service->initiateSamenwerking(
            zaakId: 'zaak-uuid-1',
            aangezochtBevoegdGezag: 'gemeente-haarlem',
            rationale: 'Bevoegdheidsgrens.'
        );

        $this->assertSame('aangevraagd', $result['status']);
    }//end testInitiateSamenwerkingCreatesObject()

    /**
     * Test that respondToSamenwerking sets status to 'geaccepteerd' on accept.
     *
     * @return void
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#T13
     */
    public function testRespondToSamenwerkingAcceptUpdatesStatus(): void
    {
        $objectServiceMock = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['findObject', 'saveObject'])
            ->getMock();

        $verzoek = [
            'id'     => 'samenwerk-uuid-1',
            'status' => 'aangevraagd',
        ];

        $objectServiceMock
            ->expects($this->once())
            ->method('findObject')
            ->willReturn($verzoek);

        $objectServiceMock
            ->expects($this->once())
            ->method('saveObject')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->callback(
                        function (array $obj) {
                            return ($obj['status'] ?? '') === 'geaccepteerd';
                        }
                        )
            )
            ->willReturnCallback(
                    function (string $r, string $s, array $obj) {
                        return $obj;
                    }
                    );

        $this->container
            ->method('get')
            ->willReturn($objectServiceMock);

        $this->appConfig
            ->method('getValueString')
            ->willReturn('some-value');

        $this->logger->expects($this->once())->method('info');

        $result = $this->service->respondToSamenwerking(
            samenwerkId: 'samenwerk-uuid-1',
            accept: true,
            advies: 'Wij stemmen in.'
        );

        $this->assertSame('geaccepteerd', $result['status']);
        $this->assertSame('Wij stemmen in.', $result['advies']);
    }//end testRespondToSamenwerkingAcceptUpdatesStatus()

    /**
     * Test that respondToSamenwerking sets status to 'geweigerd' on rejection.
     *
     * @return void
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#T13
     */
    public function testRespondToSamenwerkingRejectUpdatesStatus(): void
    {
        $objectServiceMock = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['findObject', 'saveObject'])
            ->getMock();

        $verzoek = [
            'id'     => 'samenwerk-uuid-2',
            'status' => 'aangevraagd',
        ];

        $objectServiceMock
            ->method('findObject')
            ->willReturn($verzoek);

        $objectServiceMock
            ->method('saveObject')
            ->willReturnCallback(
                    function (string $r, string $s, array $obj) {
                        return $obj;
                    }
                    );

        $this->container
            ->method('get')
            ->willReturn($objectServiceMock);

        $this->appConfig
            ->method('getValueString')
            ->willReturn('some-value');

        $this->logger->method('info');

        $result = $this->service->respondToSamenwerking(
            samenwerkId: 'samenwerk-uuid-2',
            accept: false,
            advies: 'Wij wijzen af.'
        );

        $this->assertSame('geweigerd', $result['status']);
        $this->assertSame('Wij wijzen af.', $result['advies']);
    }//end testRespondToSamenwerkingRejectUpdatesStatus()
}//end class
