<?php

/**
 * LegesRestitutieService Unit Tests
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
 * @spec openspec/changes/leges-heffingen/specs.md#req-leges-006
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\LegesRestitutieService;
use OCA\Procest\Service\LegesShillinqService;
use OCA\Procest\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Object service stub for restitutie tests.
 */
interface RestitutieObjectServiceStub
{
    /**
     * Save an object.
     *
     * @param string $register Register id.
     * @param string $schema   Schema id.
     * @param array  $object   Object data.
     * @param string $id       Optional id.
     *
     * @return array
     */
    public function saveObject(string $register, string $schema, array $object, string $id='');

    /**
     * Find a single object.
     *
     * @param string $id       Object id.
     * @param string $register Register id.
     * @param string $schema   Schema id.
     *
     * @return array
     */
    public function find(string $id, string $register='', string $schema='');
}//end interface

/**
 * Unit tests for LegesRestitutieService.
 *
 * @covers \OCA\Procest\Service\LegesRestitutieService
 */
class LegesRestitutieServiceTest extends TestCase
{

    /**
     * The mocked settings service.
     *
     * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
     */
    private SettingsService $settingsService;

    /**
     * The mocked shillinq service.
     *
     * @var LegesShillinqService|\PHPUnit\Framework\MockObject\MockObject
     */
    private LegesShillinqService $shillinqService;

    /**
     * The service under test.
     *
     * @var LegesRestitutieService
     */
    private LegesRestitutieService $service;

    /**
     * Set up fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->shillinqService = $this->createMock(LegesShillinqService::class);
        $this->service         = new LegesRestitutieService(
            settingsService: $this->settingsService,
            shillinqService: $this->shillinqService,
            logger: $this->createMock(LoggerInterface::class)
        );

        $this->settingsService->method('getConfigValue')->willReturnCallback(
            static fn (string $key): string => ($key === 'register' ? '1' : $key)
        );
    }//end setUp()

    /**
     * The phase staffel maps phases to the expected percentages.
     *
     * @return void
     */
    public function testStaffel(): void
    {
        $this->assertSame(100, $this->service->applyRestitutieStaffel(fase: 'aanvraag'));
        $this->assertSame(75, $this->service->applyRestitutieStaffel(fase: 'in_behandeling'));
        $this->assertSame(0, $this->service->applyRestitutieStaffel(fase: 'beschikking'));
        $this->assertSame(0, $this->service->applyRestitutieStaffel(fase: 'onbekend'));
    }//end testStaffel()

    /**
     * createRestitutie computes a phase-based refund amount and persists it.
     *
     * @return void
     */
    public function testCreateRestitutie75Percent(): void
    {
        $berekening = [
            'id'            => 'ber-1',
            'status'        => 'betaald',
            'bedragInclBtw' => 35000,
            'factuurId'     => 'F-1',
        ];

        $stub = $this->createMock(RestitutieObjectServiceStub::class);
        $stub->method('find')->willReturn($berekening);
        $stub->method('saveObject')->willReturnCallback(
            static fn (string $register, string $schema, array $object, string $id=''): array => array_merge(['id' => 'rest-1'], $object)
        );

        $this->settingsService->method('getObjectService')->willReturn($stub);
        $this->shillinqService->method('isEnabled')->willReturn(false);

        $result = $this->service->createRestitutie(
            berekeningId: 'ber-1',
            reason: 'aanvraag_ingetrokken',
            fase: 'in_behandeling',
            besluitNemerId: 'alice'
        );

        $this->assertSame(75, $result['restitutiePercentage']);
        // 75% of 35000 = 26250.
        $this->assertSame(26250, $result['restitutieBedrag']);
        $this->assertSame('alice', $result['besluitNemerId']);
    }//end testCreateRestitutie75Percent()

    /**
     * A refund cannot be granted on a calculation that was never invoiced.
     *
     * @return void
     */
    public function testRefundRejectedForUninvoiced(): void
    {
        $stub = $this->createMock(RestitutieObjectServiceStub::class);
        $stub->method('find')->willReturn(['id' => 'ber-2', 'status' => 'berekend', 'bedragInclBtw' => 1000]);

        $this->settingsService->method('getObjectService')->willReturn($stub);

        $this->expectException(RuntimeException::class);
        $this->service->createRestitutie(
            berekeningId: 'ber-2',
            reason: 'coulance',
            fase: 'aanvraag',
            besluitNemerId: 'alice'
        );
    }//end testRefundRejectedForUninvoiced()
}//end class
