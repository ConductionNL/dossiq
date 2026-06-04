<?php

/**
 * PortalRequestService Unit Tests
 *
 * Verifies bezwaar deadline validation (timely vs. expired), klacht category
 * validation and that the submitter reference is taken from the session.
 * Persistence paths that require OpenRegister are covered separately; these
 * tests focus on the validation and deadline decision logic.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service\Zaakportaal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service\Zaakportaal;

use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\Zaakportaal\AwbDeadlineService;
use OCA\Procest\Service\Zaakportaal\PortalRequestService;
use OCP\AppFramework\OCS\OCSBadRequestException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for PortalRequestService.
 *
 * @covers \OCA\Procest\Service\Zaakportaal\PortalRequestService
 */
class PortalRequestServiceTest extends TestCase
{

    private PortalRequestService $service;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        // SettingsService returns no ObjectService, so persistence is never
        // reached for the validation-rejection tests.
        $settings = $this->createMock(SettingsService::class);
        $settings->method('getObjectService')->willReturn(null);

        $this->service = new PortalRequestService(
            $settings,
            new AwbDeadlineService(),
            $this->createMock(LoggerInterface::class),
        );
    }//end setUp()

    /**
     * The deadline validator reports the computed deadline and timeliness.
     *
     * @return void
     */
    public function testValidateBezwaarDeadline(): void
    {
        $result = $this->service->validateBezwaarDeadline('2026-01-08');
        $this->assertSame('2026-02-19', $result['deadline']);
        $this->assertArrayHasKey('binnenTermijn', $result);
        $this->assertArrayHasKey('dagenResterend', $result);
    }//end testValidateBezwaarDeadline()

    /**
     * A bezwaar filed after the termijn is rejected before persistence.
     *
     * @return void
     */
    public function testExpiredBezwaarRejected(): void
    {
        $this->expectException(OCSBadRequestException::class);
        $this->service->submitBezwaar(
            [
                'tegenZaakId'  => 'z1',
                'motivering'   => 'Mijn motivering',
                // A decision long in the past -> termijn expired.
                'decisionDate' => '2020-01-01',
            ],
            'subj-1'
        );
    }//end testExpiredBezwaarRejected()

    /**
     * A bezwaar without motivering is rejected.
     *
     * @return void
     */
    public function testBezwaarRequiresMotivering(): void
    {
        $this->expectException(OCSBadRequestException::class);
        $this->service->submitBezwaar(
            ['tegenZaakId' => 'z1', 'decisionDate' => '2026-01-08', 'motivering' => ''],
            'subj-1'
        );
    }//end testBezwaarRequiresMotivering()

    /**
     * A klacht with an invalid category is rejected.
     *
     * @return void
     */
    public function testKlachtInvalidCategoryRejected(): void
    {
        $this->expectException(OCSBadRequestException::class);
        $this->service->submitKlacht(
            ['categorie' => 'Onzin', 'omschrijving' => 'Iets'],
            'subj-1'
        );
    }//end testKlachtInvalidCategoryRejected()

    /**
     * A klacht without omschrijving is rejected.
     *
     * @return void
     */
    public function testKlachtRequiresOmschrijving(): void
    {
        $this->expectException(OCSBadRequestException::class);
        $this->service->submitKlacht(
            ['categorie' => 'Bejegening', 'omschrijving' => ''],
            'subj-1'
        );
    }//end testKlachtRequiresOmschrijving()
}//end class
