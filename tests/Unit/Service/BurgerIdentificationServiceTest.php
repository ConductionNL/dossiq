<?php

/**
 * BurgerIdentificationService Unit Tests
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T05
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\BurgerIdentificationService;
use OCA\Procest\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for BurgerIdentificationService.
 *
 * @covers \OCA\Procest\Service\BurgerIdentificationService
 */
class BurgerIdentificationServiceTest extends TestCase
{

    /**
     * The SettingsService mock.
     *
     * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
     */
    private SettingsService $settingsService;

    /**
     * The service under test.
     *
     * @var BurgerIdentificationService
     */
    private BurgerIdentificationService $service;

    /**
     * Set up fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->settingsService = $this->createMock(originalClassName: SettingsService::class);
        $container = $this->createMock(originalClassName: ContainerInterface::class);
        $logger    = $this->createMock(originalClassName: LoggerInterface::class);

        $this->settingsService->method('getKccConfigValue')
            ->willReturnCallback(
                static function (string $key): string {
                    if ($key === 'identification_score_threshold') {
                        return '0.8';
                    }

                    return '';
                }
            );

        $this->service = new BurgerIdentificationService(
            settingsService: $this->settingsService,
            container: $container,
            logger: $logger,
        );
    }//end setUp()

    /**
     * A full match scores 1.0 (all weighted dimensions present).
     *
     * @return void
     */
    public function testFullMatchScoresOne(): void
    {
        $score = $this->service->calculateScore(
            [
                'naam'          => true,
                'geboortedatum' => true,
                'adres'         => true,
                'bsn'           => true,
                'out_of_wallet' => true,
            ]
        );

        $this->assertSame(expected: 1.0, actual: $score);
    }//end testFullMatchScoresOne()

    /**
     * Naam + geboortedatum alone (0.6) is below the 0.8 threshold.
     *
     * @return void
     */
    public function testPartialMatchBelowThresholdNotIdentified(): void
    {
        $result = $this->service->startIdentificatievragen(
            ['naam' => true, 'geboortedatum' => true],
            'burger:abc',
        );

        $this->assertSame(expected: 0.6, actual: $result['score']);
        $this->assertFalse(condition: $result['identified']);
        $this->assertNull(actual: $result['burgerId']);
    }//end testPartialMatchBelowThresholdNotIdentified()

    /**
     * Naam + geboortedatum + adres (0.8) meets the threshold and links.
     *
     * @return void
     */
    public function testMatchAtThresholdIdentified(): void
    {
        $result = $this->service->startIdentificatievragen(
            ['naam' => true, 'geboortedatum' => true, 'adres' => true],
            'burger:abc',
        );

        $this->assertSame(expected: 0.8, actual: $result['score']);
        $this->assertTrue(condition: $result['identified']);
        $this->assertSame(expected: 'burger:abc', actual: $result['burgerId']);
    }//end testMatchAtThresholdIdentified()

    /**
     * DigiD resolution returns a pseudonymous reference, never the raw BSN.
     *
     * @return void
     */
    public function testDigiDResolutionPseudonymises(): void
    {
        $bsn    = '123456782';
        $result = $this->service->resolveFromDigiD($bsn);

        $this->assertSame(expected: 'digid', actual: $result['method']);
        $this->assertStringStartsWith(prefix: 'burger:', string: $result['burgerId']);
        $this->assertStringNotContainsString(needle: $bsn, haystack: $result['burgerId']);
    }//end testDigiDResolutionPseudonymises()

    /**
     * An empty BSN yields the niet_geidentificeerd method.
     *
     * @return void
     */
    public function testEmptyBsnNotIdentified(): void
    {
        $result = $this->service->resolveFromDigiD('   ');

        $this->assertSame(expected: 'niet_geidentificeerd', actual: $result['method']);
        $this->assertSame(expected: '', actual: $result['burgerId']);
    }//end testEmptyBsnNotIdentified()
}//end class
