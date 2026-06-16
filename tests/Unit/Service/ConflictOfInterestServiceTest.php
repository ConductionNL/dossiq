<?php

/**
 * Unit tests for ConflictOfInterestService.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\ConflictOfInterestService;
use OCA\Procest\Service\External\Brp\BrpHaalCentraalAdapterInterface;
use OCA\Procest\Service\External\Brp\BrpLookupResult;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Procest\Service\ConflictOfInterestService
 */
class ConflictOfInterestServiceTest extends TestCase
{
    /**
     * @return void
     */
    public function testNoConflictWithoutBsns(): void
    {
        $svc = new ConflictOfInterestService($this->createMock(LoggerInterface::class));
        $r = $svc->checkConflict('alice', 'Z/2026/1');
        self::assertFalse($r['conflict']);
    }

    /**
     * @return void
     */
    public function testSelfDetected(): void
    {
        $svc = new ConflictOfInterestService($this->createMock(LoggerInterface::class));
        $r = $svc->checkConflict('alice', 'Z/2026/2', ['userBsn' => '123', 'applicantBsn' => '123']);
        self::assertTrue($r['conflict']);
        self::assertSame('self', $r['reason']);
    }

    /**
     * @return void
     */
    public function testRelationshipDetected(): void
    {
        $svc = new ConflictOfInterestService($this->createMock(LoggerInterface::class));
        $svc->setRelationshipLookup(static fn (string $u, string $a): ?string => ($u === '111' && $a === '222') ? 'spouse' : null);
        $r = $svc->checkConflict('alice', 'Z/2026/3', ['userBsn' => '111', 'applicantBsn' => '222']);
        self::assertTrue($r['conflict']);
        self::assertSame('spouse', $r['reason']);
    }

    /**
     * @return void
     */
    public function testManualRegistrationOverridesAuto(): void
    {
        $svc = new ConflictOfInterestService($this->createMock(LoggerInterface::class));
        $svc->registerConflict('Z/2026/4', 'persoonlijk');
        $r = $svc->checkConflict('alice', 'Z/2026/4', ['userBsn' => '111', 'applicantBsn' => '222']);
        self::assertTrue($r['conflict']);
        self::assertSame('persoonlijk', $r['reason']);
    }

    /**
     * @return void
     */
    public function testClearConflictUnblocks(): void
    {
        $svc = new ConflictOfInterestService($this->createMock(LoggerInterface::class));
        $svc->registerConflict('Z/2026/5', 'persoonlijk');
        $svc->clearConflict('Z/2026/5');
        $r = $svc->checkConflict('alice', 'Z/2026/5');
        self::assertFalse($r['conflict']);
    }

    /**
     * @return void
     */
    public function testDormantBrpAdapterLeavesConflictOpen(): void
    {
        $brp = $this->createMock(BrpHaalCentraalAdapterInterface::class);
        $brp->method('lookup')->willReturn(
            new BrpLookupResult(
                lookupStatus: 'LOOKUP_DEFERRED',
                persoon: [],
                dormant: true,
            )
        );

        $svc = new ConflictOfInterestService($this->createMock(LoggerInterface::class), $brp);
        $r   = $svc->checkConflict('alice', 'Z/2026/6', ['userBsn' => '111', 'applicantBsn' => '222']);
        self::assertFalse($r['conflict']);
    }

    /**
     * @return void
     */
    public function testActiveBrpAdapterDetectsRelation(): void
    {
        $brp = $this->createMock(BrpHaalCentraalAdapterInterface::class);
        $brp->method('lookup')->willReturn(
            new BrpLookupResult(
                lookupStatus: 'FOUND',
                persoon: [
                    'relaties' => [
                        ['burgerservicenummer' => '222', 'relatie' => 'partner'],
                    ],
                ],
                dormant: false,
            )
        );

        $svc = new ConflictOfInterestService($this->createMock(LoggerInterface::class), $brp);
        $r   = $svc->checkConflict('alice', 'Z/2026/7', ['userBsn' => '111', 'applicantBsn' => '222']);
        self::assertTrue($r['conflict']);
        self::assertSame('partner', $r['reason']);
    }

    /**
     * @return void
     */
    public function testBrpAdapterRelationOnlyFiresWhenBsnMatches(): void
    {
        $brp = $this->createMock(BrpHaalCentraalAdapterInterface::class);
        $brp->method('lookup')->willReturn(
            new BrpLookupResult(
                lookupStatus: 'FOUND',
                persoon: [
                    'relaties' => [
                        ['burgerservicenummer' => '999', 'relatie' => 'parent'],
                    ],
                ],
                dormant: false,
            )
        );

        $svc = new ConflictOfInterestService($this->createMock(LoggerInterface::class), $brp);
        $r   = $svc->checkConflict('alice', 'Z/2026/8', ['userBsn' => '111', 'applicantBsn' => '222']);
        self::assertFalse($r['conflict']);
    }
}
