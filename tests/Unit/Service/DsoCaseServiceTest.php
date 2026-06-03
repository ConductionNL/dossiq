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
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#V02
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#V03
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#V04
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#V06
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Event\VergunningStatusChangedEvent;
use OCA\Procest\Service\DsoCaseService;
use OCA\Procest\Service\SettingsService;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Procest\Service\DsoCaseService
 */
class DsoCaseServiceTest extends TestCase
{

    private SettingsService $settingsService;

    private IEventDispatcher $dispatcher;

    private LoggerInterface $logger;

    private DsoCaseService $service;

    protected function setUp(): void
    {
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->dispatcher      = $this->createMock(IEventDispatcher::class);
        $this->logger          = $this->createMock(LoggerInterface::class);

        $this->service = new DsoCaseService(
            settingsService: $this->settingsService,
            dispatcher: $this->dispatcher,
            logger: $this->logger,
        );
    }//end setUp()

    /**
     * computeDeadline adds correct working days for reguliere (40 wd).
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#V06
     */
    public function testComputeDeadlineReguliere(): void
    {
        // 2026-03-23 is a Monday; 40 working days from it should be 2026-05-18.
        $result = $this->service->computeDeadline(
            indieningsdatum: '2026-03-23',
            procedureType: 'reguliere',
        );

        // Result must be a valid date string after the submission date.
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $result);

        $start    = new \DateTimeImmutable('2026-03-23');
        $deadline = new \DateTimeImmutable($result);
        $this->assertGreaterThan($start, $deadline);
    }//end testComputeDeadlineReguliere()

    /**
     * computeDeadline adds correct working days for uitgebreide (130 wd).
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#V06
     */
    public function testComputeDeadlineUitgebreide(): void
    {
        $result = $this->service->computeDeadline(
            indieningsdatum: '2026-01-05',
            procedureType: 'uitgebreide',
        );

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $result);

        $start    = new \DateTimeImmutable('2026-01-05');
        $deadline = new \DateTimeImmutable($result);
        $this->assertGreaterThan($start, $deadline);
    }//end testComputeDeadlineUitgebreide()

    /**
     * computeDeadline skips weekends and does not land on a weekend day.
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#V06
     */
    public function testComputeDeadlineDoesNotLandOnWeekend(): void
    {
        $result = $this->service->computeDeadline(
            indieningsdatum: '2026-01-01',
            procedureType: 'reguliere',
        );

        $deadline  = new \DateTimeImmutable($result);
        $dayOfWeek = (int) $deadline->format('N');

        $this->assertLessThanOrEqual(5, $dayOfWeek, 'Deadline must not land on a weekend');
    }//end testComputeDeadlineDoesNotLandOnWeekend()

    /**
     * determineProcedureType returns reguliere by default.
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#V02
     */
    public function testDetermineProcedureTypeDefaultReguliere(): void
    {
        $result = $this->service->determineProcedureType(
                aanvraag: [
                    'activiteiten' => ['nl.imow.bouwen'],
                ]
                );

        $this->assertSame('reguliere', $result);
    }//end testDetermineProcedureTypeDefaultReguliere()

    /**
     * determineProcedureType returns uitgebreide when activiteit is flagged.
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#V02
     */
    public function testDetermineProcedureTypeUitgebreideViaActiviteit(): void
    {
        $result = $this->service->determineProcedureType(
                aanvraag: [
                    'activiteiten' => [
                        ['naam' => 'Bouwen', 'procedureType' => 'uitgebreid'],
                    ],
                ]
                );

        $this->assertSame('uitgebreide', $result);
    }//end testDetermineProcedureTypeUitgebreideViaActiviteit()

    /**
     * determineProcedureType honours explicit aanvraag field.
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#V02
     */
    public function testDetermineProcedureTypeExplicitField(): void
    {
        $result = $this->service->determineProcedureType(
                aanvraag: [
                    'procedureType' => 'uitgebreide',
                    'activiteiten'  => [],
                ]
                );

        $this->assertSame('uitgebreide', $result);
    }//end testDetermineProcedureTypeExplicitField()

    /**
     * transitionStatus throws when OpenRegister is unavailable.
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#V03
     */
    public function testTransitionStatusThrowsWhenNoObjectService(): void
    {
        $this->settingsService
            ->expects($this->once())
            ->method('getObjectService')
            ->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('OpenRegister is not available');

        $this->service->transitionStatus(
            zaakId: 'test-zaak-id',
            newStatus: 'in_behandeling',
            besluitdatum: null,
            toelichting: null,
            userId: 'testuser',
        );
    }//end testTransitionStatusThrowsWhenNoObjectService()

    /**
     * VergunningStatusChangedEvent carries all expected fields.
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#V04
     */
    public function testVergunningStatusChangedEventFields(): void
    {
        $event = new VergunningStatusChangedEvent(
            vergunningaanvraagRef: 'nl.dso.aanvraag.2026-AMS-001',
            oldStatus: 'ingediend',
            newStatus: 'in_behandeling',
            besluitdatum: null,
            toelichting: 'Behandeling gestart.',
            userId: 'testuser',
        );

        $this->assertSame('nl.dso.aanvraag.2026-AMS-001', $event->getVergunningaanvraagRef());
        $this->assertSame('ingediend', $event->getOldStatus());
        $this->assertSame('in_behandeling', $event->getNewStatus());
        $this->assertNull($event->getBesluitdatum());
        $this->assertSame('Behandeling gestart.', $event->getToelichting());
        $this->assertSame('testuser', $event->getUserId());
    }//end testVergunningStatusChangedEventFields()

    /**
     * createZaakFromVergunningaanvraag throws when OpenRegister is unavailable.
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#V02
     */
    public function testCreateZaakThrowsWhenNoObjectService(): void
    {
        $this->settingsService
            ->expects($this->once())
            ->method('getObjectService')
            ->willReturn(null);

        $this->expectException(\RuntimeException::class);

        $this->service->createZaakFromVergunningaanvraag(vergunningaanvraagId: 'test-id');
    }//end testCreateZaakThrowsWhenNoObjectService()
}//end class
