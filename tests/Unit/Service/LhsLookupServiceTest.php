<?php

/**
 * LhsLookupService Unit Tests
 *
 * Tests for the LHS 4x4 matrix lookup service that maps gedrag × gevolg
 * combinations to interventieladder recommendations.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/vth-module/tasks.md#task-8
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\LhsLookupService;
use OCA\Procest\Service\SettingsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for the LhsLookupService class.
 *
 * @covers \OCA\Procest\Service\LhsLookupService
 */
class LhsLookupServiceTest extends TestCase
{

    /**
     * The mocked settings service.
     *
     * @var SettingsService|MockObject
     */
    private SettingsService $settingsService;

    /**
     * The mocked logger.
     *
     * @var LoggerInterface|MockObject
     */
    private LoggerInterface $logger;

    /**
     * The service under test.
     *
     * @var LhsLookupService
     */
    private LhsLookupService $service;


    /**
     * Set up test fixtures.
     *
     * OpenRegister is unavailable so all lookups use the built-in fallback matrix.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->logger          = $this->createMock(LoggerInterface::class);

        // Simulate OpenRegister unavailable — service falls back to built-in matrix.
        $this->settingsService->method('getObjectService')->willReturn(null);
        $this->settingsService->method('getConfigValue')->willReturn('');

        $this->service = new LhsLookupService(
            settingsService: $this->settingsService,
            logger: $this->logger,
        );

    }//end setUp()


    /**
     * Test that lookup() returns the correct cell from the fallback matrix.
     *
     * B × 2 = "Bestuurlijke waarschuwing" per the LHS 2022 standard.
     *
     * @return void
     */
    public function testLookupReturnsCorrectCellFromFallbackMatrix(): void
    {
        $result = $this->service->lookup(gedrag: 'B', gevolg: '2');

        self::assertSame('B', $result['gedragRow']);
        self::assertSame('2', $result['gevolgColumn']);
        self::assertSame('Bestuurlijke waarschuwing', $result['interventieStep']);
        self::assertNotEmpty($result['description']);

    }//end testLookupReturnsCorrectCellFromFallbackMatrix()


    /**
     * Test that lookup() normalises gedrag to uppercase.
     *
     * @return void
     */
    public function testLookupIsCaseInsensitiveForGedrag(): void
    {
        $result = $this->service->lookup(gedrag: 'a', gevolg: '1');

        self::assertSame('A', $result['gedragRow']);
        self::assertSame('1', $result['gevolgColumn']);

    }//end testLookupIsCaseInsensitiveForGedrag()


    /**
     * Test that lookup() trims whitespace from inputs.
     *
     * @return void
     */
    public function testLookupTrimsWhitespace(): void
    {
        $result = $this->service->lookup(gedrag: ' C ', gevolg: ' 3 ');

        self::assertSame('C', $result['gedragRow']);
        self::assertSame('3', $result['gevolgColumn']);

    }//end testLookupTrimsWhitespace()


    /**
     * Test that lookup() throws on an invalid gedrag code.
     *
     * @return void
     */
    public function testLookupThrowsOnInvalidGedrag(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Ongeldig gedrag-code');

        $this->service->lookup(gedrag: 'X', gevolg: '1');

    }//end testLookupThrowsOnInvalidGedrag()


    /**
     * Test that lookup() throws on an invalid gevolg column.
     *
     * @return void
     */
    public function testLookupThrowsOnInvalidGevolg(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Ongeldig gevolg-kolom');

        $this->service->lookup(gedrag: 'A', gevolg: '5');

    }//end testLookupThrowsOnInvalidGevolg()


    /**
     * Test that lookup() throws on numeric-zero gevolg (out of range).
     *
     * @return void
     */
    public function testLookupThrowsOnZeroGevolg(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Ongeldig gevolg-kolom');

        $this->service->lookup(gedrag: 'A', gevolg: '0');

    }//end testLookupThrowsOnZeroGevolg()


    /**
     * Test that all sixteen matrix cells are reachable and non-empty.
     *
     * @return void
     */
    public function testLookupAllSixteenCells(): void
    {
        $gedragRows = ['A', 'B', 'C', 'D'];
        $gevolgCols = ['1', '2', '3', '4'];

        foreach ($gedragRows as $gedrag) {
            foreach ($gevolgCols as $gevolg) {
                $result = $this->service->lookup(gedrag: $gedrag, gevolg: $gevolg);
                self::assertSame($gedrag, $result['gedragRow'], "Row mismatch for $gedrag");
                self::assertSame($gevolg, $result['gevolgColumn'], "Column mismatch for $gevolg");
                self::assertNotEmpty(
                    $result['interventieStep'],
                    "interventieStep must not be empty for cell $gedrag x $gevolg"
                );
                self::assertNotEmpty(
                    $result['description'],
                    "description must not be empty for cell $gedrag x $gevolg"
                );
            }
        }

    }//end testLookupAllSixteenCells()


    /**
     * Test that D × 4 returns a description (most severe cell).
     *
     * @return void
     */
    public function testLookupMostSevereCell(): void
    {
        $result = $this->service->lookup(gedrag: 'D', gevolg: '4');

        self::assertSame('D', $result['gedragRow']);
        self::assertSame('4', $result['gevolgColumn']);
        self::assertNotEmpty($result['interventieStep']);

    }//end testLookupMostSevereCell()


    /**
     * Test that A × 1 returns the mildest intervention step.
     *
     * @return void
     */
    public function testLookupMildestCell(): void
    {
        $result = $this->service->lookup(gedrag: 'A', gevolg: '1');

        self::assertSame('Aanspreken / informeren', $result['interventieStep']);

    }//end testLookupMildestCell()


}//end class
