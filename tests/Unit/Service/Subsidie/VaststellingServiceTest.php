<?php

/**
 * VaststellingService Unit Tests.
 *
 * Exercises the settlement math (REQ-SUB-005): accountantsverklaring
 * threshold, final-bedrag capping, overpayment detection and the
 * terugvordering trigger boundary.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service\Subsidie
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

namespace OCA\Procest\Tests\Unit\Service\Subsidie;

use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\Subsidie\TerugvorderingService;
use OCA\Procest\Service\Subsidie\VaststellingService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Procest\Service\Subsidie\VaststellingService
 *
 * @spec openspec/changes/subsidieverlening-keten/tasks.md#TASK-SUB-20
 */
class VaststellingServiceTest extends TestCase
{

    private VaststellingService $service;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $settings       = $this->createMock(SettingsService::class);
        $logger         = $this->createMock(LoggerInterface::class);
        $terugvordering = new TerugvorderingService($settings, $logger);
        $this->service  = new VaststellingService($settings, $terugvordering, $logger);
    }//end setUp()

    /**
     * @return void
     */
    public function testAccountantsverklaringThreshold(): void
    {
        $this->assertTrue($this->service->accountantsverklaringVereist(150000.0, 125000.0));
        $this->assertFalse($this->service->accountantsverklaringVereist(125000.0, 125000.0));
        $this->assertFalse($this->service->accountantsverklaringVereist(100000.0, 125000.0));
    }//end testAccountantsverklaringThreshold()

    /**
     * Final bedrag is capped at the granted amount and never above actual costs.
     *
     * @return void
     */
    public function testVastgesteldBedragCapping(): void
    {
        // Actual costs below granted -> settle at actual costs.
        $this->assertSame(330000.0, $this->service->computeVastgesteldBedrag(450000.0, 330000.0));
        // Actual costs above granted -> capped at granted.
        $this->assertSame(450000.0, $this->service->computeVastgesteldBedrag(450000.0, 500000.0));
        // Negative actual costs guarded to zero.
        $this->assertSame(0.0, $this->service->computeVastgesteldBedrag(450000.0, -1.0));
    }//end testVastgesteldBedragCapping()

    /**
     * REQ-SUB-005: overpayment is the positive difference between disbursed
     * advances and the final settled amount.
     *
     * @return void
     */
    public function testOverpaymentAndTrigger(): void
    {
        // €360.000 advances vs €330.000 settled -> €30.000 clawback.
        $this->assertSame(30000.0, $this->service->computeOverpayment(360000.0, 330000.0));
        $this->assertTrue($this->service->triggerTerugvordering(360000.0, 330000.0));

        // Advances equal to settled -> no clawback.
        $this->assertSame(0.0, $this->service->computeOverpayment(330000.0, 330000.0));
        $this->assertFalse($this->service->triggerTerugvordering(330000.0, 330000.0));

        // Settled above advances (under-disbursed) -> no clawback.
        $this->assertSame(0.0, $this->service->computeOverpayment(300000.0, 330000.0));
        $this->assertFalse($this->service->triggerTerugvordering(300000.0, 330000.0));
    }//end testOverpaymentAndTrigger()
}//end class
