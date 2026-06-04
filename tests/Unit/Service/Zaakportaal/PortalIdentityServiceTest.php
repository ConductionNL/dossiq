<?php

/**
 * PortalIdentityService Unit Tests
 *
 * Verifies the pseudonymisation of citizen identifiers (stable, salted, never
 * the raw BSN), BSN masking for safe display, and the Wdo trust-level gate.
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
use OCA\Procest\Service\Zaakportaal\PortalIdentityService;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PortalIdentityService.
 *
 * @covers \OCA\Procest\Service\Zaakportaal\PortalIdentityService
 */
class PortalIdentityServiceTest extends TestCase
{

    private PortalIdentityService $service;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $settings = $this->createMock(SettingsService::class);
        $settings->method('getConfigValue')->willReturn('fixed-salt');

        $this->service = new PortalIdentityService(
            $settings,
            $this->createMock(IUserSession::class),
        );
    }//end setUp()

    /**
     * The subject reference is stable and never echoes the raw identifier.
     *
     * @return void
     */
    public function testSubjectRefIsStableAndPseudonymous(): void
    {
        $ref1 = $this->service->deriveSubjectRef('123456789');
        $ref2 = $this->service->deriveSubjectRef('123 456 789');

        $this->assertSame($ref1, $ref2, 'whitespace-insensitive and deterministic');
        $this->assertStringStartsWith('subj-', $ref1);
        $this->assertStringNotContainsString('123456789', $ref1);
    }//end testSubjectRefIsStableAndPseudonymous()

    /**
     * Different identifiers produce different references.
     *
     * @return void
     */
    public function testDifferentIdentifiersDiffer(): void
    {
        $this->assertNotSame(
            $this->service->deriveSubjectRef('123456789'),
            $this->service->deriveSubjectRef('987654321')
        );
    }//end testDifferentIdentifiersDiffer()

    /**
     * An empty identifier is rejected.
     *
     * @return void
     */
    public function testEmptyIdentifierRejected(): void
    {
        $this->expectException(OCSBadRequestException::class);
        $this->service->deriveSubjectRef('   ');
    }//end testEmptyIdentifierRejected()

    /**
     * BSN masking keeps only the last four digits.
     *
     * @return void
     */
    public function testMaskBsn(): void
    {
        $this->assertSame('*****6789', $this->service->maskBsn('123456789'));
        $this->assertSame('****', $this->service->maskBsn('12'));
    }//end testMaskBsn()

    /**
     * Trust levels at or above substantieel are accepted.
     *
     * @return void
     */
    public function testTrustLevelAccepted(): void
    {
        $this->service->assertTrustLevel('substantieel');
        $this->service->assertTrustLevel('Hoog');
        $this->addToAssertionCount(1);
    }//end testTrustLevelAccepted()

    /**
     * A low trust level is rejected (Wdo minimum).
     *
     * @return void
     */
    public function testLowTrustLevelRejected(): void
    {
        $this->expectException(OCSBadRequestException::class);
        $this->service->assertTrustLevel('laag');
    }//end testLowTrustLevelRejected()
}//end class
