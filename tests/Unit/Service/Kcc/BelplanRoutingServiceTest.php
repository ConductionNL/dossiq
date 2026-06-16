<?php

/**
 * BelplanRoutingService Unit Tests
 *
 * Covers the deterministic KCC routing algorithm.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service\Kcc
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T06
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service\Kcc;

use OCA\Procest\Service\Kcc\BelplanRoutingService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Procest\Service\Kcc\BelplanRoutingService
 */
class BelplanRoutingServiceTest extends TestCase
{
    private BelplanRoutingService $service;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->service = new BelplanRoutingService();
    }//end setUp()

    /**
     * @return void
     */
    public function testGetActiveBelplanMatchesByTriggerNummer(): void
    {
        $belplannen = [
            ['triggerNummer' => '+31201234567', 'naam' => 'Amsterdam', 'isActive' => true],
            ['triggerNummer' => '+31301234567', 'naam' => 'Utrecht',   'isActive' => true],
        ];

        $matched = $this->service->getActiveBelplan(
            phoneNumber: '+31 30 123 4567',
            belplannen: $belplannen
        );

        self::assertIsArray($matched);
        self::assertSame('Utrecht', $matched['naam']);
    }//end testGetActiveBelplanMatchesByTriggerNummer()

    /**
     * @return void
     */
    public function testGetActiveBelplanSkipsInactive(): void
    {
        $belplannen = [
            ['triggerNummer' => '+31201234567', 'naam' => 'Amsterdam', 'isActive' => false],
            ['triggerNummer' => '+31201234567', 'naam' => 'Amsterdam-DR', 'isActive' => true],
        ];

        $matched = $this->service->getActiveBelplan(
            phoneNumber: '+31201234567',
            belplannen: $belplannen
        );

        self::assertSame('Amsterdam-DR', $matched['naam']);
    }//end testGetActiveBelplanSkipsInactive()

    /**
     * @return void
     */
    public function testGetActiveBelplanReturnsNullWhenNoMatch(): void
    {
        $matched = $this->service->getActiveBelplan(
            phoneNumber: '+31501234567',
            belplannen: [['triggerNummer' => '+31201234567', 'isActive' => true]]
        );

        self::assertNull($matched);
    }//end testGetActiveBelplanReturnsNullWhenNoMatch()

    /**
     * @return void
     */
    public function testResolveVaardigheidByNumericIndex(): void
    {
        $belplan = [
            'routeringStappen' => [
                ['label' => 'Omgevingsvergunning', 'vaardigheid' => 'omgevingsvergunning'],
                ['label' => 'Bouwtoezicht',        'vaardigheid' => 'bouwtoezicht'],
            ],
        ];

        self::assertSame('omgevingsvergunning', $this->service->resolveVaardigheid($belplan, 1));
        self::assertSame('bouwtoezicht', $this->service->resolveVaardigheid($belplan, '2'));
    }//end testResolveVaardigheidByNumericIndex()

    /**
     * @return void
     */
    public function testResolveVaardigheidByLabel(): void
    {
        $belplan = [
            'routeringStappen' => [
                ['label' => 'Omgevingsvergunning', 'vaardigheid' => 'omgevingsvergunning'],
                ['label' => 'Bouwtoezicht',        'vaardigheid' => 'bouwtoezicht'],
            ],
        ];

        self::assertSame(
            'bouwtoezicht',
            $this->service->resolveVaardigheid($belplan, 'Bouwtoezicht')
        );
    }//end testResolveVaardigheidByLabel()

    /**
     * @return void
     */
    public function testRouteCallPicksLowestQueueAvailableSpecialist(): void
    {
        $pool = [
            [
                'medewerkerId'           => 'sp-1',
                'expertises'             => ['bouwtoezicht'],
                'status'                 => 'beschikbaar',
                'huidigeWachtrijLengte'  => 3,
                'gemiddeldeBehandelduur' => 240,
            ],
            [
                'medewerkerId'           => 'sp-2',
                'expertises'             => ['bouwtoezicht'],
                'status'                 => 'beschikbaar',
                'huidigeWachtrijLengte'  => 1,
                'gemiddeldeBehandelduur' => 300,
            ],
        ];

        $decision = $this->service->routeCall(vaardigheid: 'bouwtoezicht', pool: $pool);

        self::assertSame('sp-2', $decision['destinationSpecialistId']);
        self::assertFalse($decision['escalatieFlag']);
        self::assertSame(300, $decision['estimatedWaitTime']);
        self::assertSame(2, $decision['candidatePool']);
    }//end testRouteCallPicksLowestQueueAvailableSpecialist()

    /**
     * @return void
     */
    public function testRouteCallEscalatesWhenNoCandidates(): void
    {
        $pool = [
            ['medewerkerId' => 'sp-x', 'expertises' => ['omgevingsvergunning'], 'status' => 'beschikbaar'],
        ];

        $decision = $this->service->routeCall(vaardigheid: 'bouwtoezicht', pool: $pool);

        self::assertNull($decision['destinationSpecialistId']);
        self::assertTrue($decision['escalatieFlag']);
        self::assertSame(0, $decision['candidatePool']);
    }//end testRouteCallEscalatesWhenNoCandidates()

    /**
     * @return void
     */
    public function testRouteCallOverflowsWhenQueueExceedsThreshold(): void
    {
        $pool = [
            [
                'medewerkerId'           => 'sp-1',
                'expertises'             => ['bouwtoezicht'],
                'status'                 => 'in_gesprek',
                'huidigeWachtrijLengte'  => 7,
                'gemiddeldeBehandelduur' => 60,
            ],
        ];

        $decision = $this->service->routeCall(
            vaardigheid: 'bouwtoezicht',
            pool: $pool,
            overflowWachttijd: 120,
            overflowWachtrijLengte: 5
        );

        self::assertNull($decision['destinationSpecialistId']);
        self::assertTrue($decision['escalatieFlag']);
        self::assertSame(1, $decision['candidatePool']);
    }//end testRouteCallOverflowsWhenQueueExceedsThreshold()

    /**
     * @return void
     */
    public function testRouteCallDoesNotOverflowWhenQueueShortAndWaitLow(): void
    {
        $pool = [
            [
                'medewerkerId'           => 'sp-1',
                'expertises'             => ['bouwtoezicht'],
                'status'                 => 'in_gesprek',
                'huidigeWachtrijLengte'  => 1,
                'gemiddeldeBehandelduur' => 30,
            ],
        ];

        $decision = $this->service->routeCall(
            vaardigheid: 'bouwtoezicht',
            pool: $pool,
            overflowWachttijd: 120,
            overflowWachtrijLengte: 5
        );

        // Queue is short and est wait < threshold → no overflow.
        self::assertFalse($decision['escalatieFlag']);
    }//end testRouteCallDoesNotOverflowWhenQueueShortAndWaitLow()

    /**
     * @return void
     */
    public function testRouteCallReturnsEmptyOnNoVaardigheid(): void
    {
        $decision = $this->service->routeCall(vaardigheid: '', pool: []);

        self::assertNull($decision['destinationSpecialistId']);
        self::assertFalse($decision['escalatieFlag']);
        self::assertSame(0, $decision['candidatePool']);
    }//end testRouteCallReturnsEmptyOnNoVaardigheid()
}//end class
