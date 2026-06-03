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
     * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
     */
    private SettingsService $settingsService;

    /**
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
        $this->settingsService = $this->createMock(SettingsService::class);
        $container             = $this->createMock(ContainerInterface::class);
        $logger                = $this->createMock(LoggerInterface::class);

        $this->settingsService->method('getKccConfigValue')
            ->willReturnCallback(
                static function (string $key): string {
                    return ($key === 'identification_score_threshold' ? '0.8' : '');
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

        $this->assertSame(1.0, $score);
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

        $this->assertSame(0.6, $result['score']);
        $this->assertFalse($result['identified']);
        $this->assertNull($result['burgerId']);
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

        $this->assertSame(0.8, $result['score']);
        $this->assertTrue($result['identified']);
        $this->assertSame('burger:abc', $result['burgerId']);
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

        $this->assertSame('digid', $result['method']);
        $this->assertStringStartsWith('burger:', $result['burgerId']);
        $this->assertStringNotContainsString($bsn, $result['burgerId']);
    }//end testDigiDResolutionPseudonymises()

    /**
     * An empty BSN yields the niet_geidentificeerd method.
     *
     * @return void
     */
    public function testEmptyBsnNotIdentified(): void
    {
        $result = $this->service->resolveFromDigiD('   ');

        $this->assertSame('niet_geidentificeerd', $result['method']);
        $this->assertSame('', $result['burgerId']);
    }//end testEmptyBsnNotIdentified()
}//end class
