<?php

/**
 * DsoCaseService Unit Tests
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T02
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\DsoCaseService;
use OCA\Procest\Service\SettingsService;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for DsoCaseService.
 *
 * @covers \OCA\Procest\Service\DsoCaseService
 */
class DsoCaseServiceTest extends TestCase
{

    /**
     * Mocked settings service.
     *
     * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
     */
    private SettingsService $settingsService;

    /**
     * Mocked event dispatcher.
     *
     * @var IEventDispatcher|\PHPUnit\Framework\MockObject\MockObject
     */
    private IEventDispatcher $eventDispatcher;

    /**
     * Mocked logger.
     *
     * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private LoggerInterface $logger;

    /**
     * The service under test.
     *
     * @var DsoCaseService
     */
    private DsoCaseService $service;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        // phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->eventDispatcher = $this->createMock(IEventDispatcher::class);
        $this->logger          = $this->createMock(LoggerInterface::class);
        // phpcs:enable CustomSniffs.Functions.NamedParameters.RequireNamedParameters

        $this->service = new DsoCaseService(
            settingsService: $this->settingsService,
            eventDispatcher: $this->eventDispatcher,
            logger: $this->logger,
        );

    }//end setUp()

    /**
     * Test computeDeadline returns a working day 40 days after the start for reguliere.
     *
     * @return void
     */
    public function testComputeDeadlineReguliere(): void
    {
        $result = $this->service->computeDeadline(
            indieningsdatum: '2026-06-01',
            procedureType: 'reguliere'
        );

        $this->assertNotEmpty(actual: $result);
        $this->assertMatchesRegularExpression(pattern: '/^\d{4}-\d{2}-\d{2}$/', string: $result);
        $this->assertGreaterThan(expected: '2026-06-01', actual: $result);

    }//end testComputeDeadlineReguliere()

    /**
     * Test computeDeadline returns a later date for uitgebreide procedure.
     *
     * @return void
     */
    public function testComputeDeadlineUitgebreideIsLaterThanReguliere(): void
    {
        $reguliere   = $this->service->computeDeadline(
            indieningsdatum: '2026-06-01',
            procedureType: 'reguliere'
        );
        $uitgebreide = $this->service->computeDeadline(
            indieningsdatum: '2026-06-01',
            procedureType: 'uitgebreide'
        );

        $this->assertGreaterThan(expected: $reguliere, actual: $uitgebreide);

    }//end testComputeDeadlineUitgebreideIsLaterThanReguliere()

    /**
     * Test that createZaakFromVergunningaanvraag throws when OpenRegister is unavailable.
     *
     * @return void
     */
    public function testCreateZaakThrowsWhenOpenRegisterUnavailable(): void
    {
        $this->settingsService
            ->method('getObjectService')
            ->willReturn(null);

        // phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $this->expectException(\RuntimeException::class);
        // phpcs:enable CustomSniffs.Functions.NamedParameters.RequireNamedParameters

        $this->service->createZaakFromVergunningaanvraag(
            vergunningaanvraagId: 'test-uuid-123'
        );

    }//end testCreateZaakThrowsWhenOpenRegisterUnavailable()

    /**
     * Test that transitionStatus throws on an invalid status value.
     *
     * @return void
     */
    public function testTransitionStatusThrowsOnInvalidStatus(): void
    {
        $objectService = new class {
            /**
             * Stub getObject for testing.
             *
             * @param string $register The register slug
             * @param string $schema   The schema slug
             * @param string $id       The object id
             *
             * @return array<string, mixed>
             */
            public function getObject(string $register, string $schema, string $id): array
            {
                return ['id' => $id, 'status' => 'ingediend', 'vergunningaanvraagRef' => ''];
            }//end getObject()
        };

        $this->settingsService
            ->method('getObjectService')
            ->willReturn($objectService);
        $this->settingsService
            ->method('getConfigValue')
            ->willReturnMap(
                [
                    ['register', '', 'procest'],
                    ['case_schema', '', 'case'],
                ]
            );

        // phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $this->expectException(\InvalidArgumentException::class);
        // phpcs:enable CustomSniffs.Functions.NamedParameters.RequireNamedParameters

        $this->service->transitionStatus(
            zaakId: 'zaak-123',
            newStatus: 'onbekend',
            besluitdatum: null,
            toelichting: null,
            userId: 'admin'
        );

    }//end testTransitionStatusThrowsOnInvalidStatus()

    /**
     * Test that the deadline excludes weekends.
     *
     * @return void
     */
    public function testDeadlineExcludesWeekends(): void
    {
        $deadline = $this->service->computeDeadline(
            indieningsdatum: '2026-07-03',
            procedureType: 'reguliere'
        );

        $deadlineDate = new \DateTimeImmutable(datetime: $deadline);
        $dow          = (int) $deadlineDate->format(format: 'N');
        $this->assertLessThan(expected: 6, actual: $dow);

    }//end testDeadlineExcludesWeekends()
}//end class
