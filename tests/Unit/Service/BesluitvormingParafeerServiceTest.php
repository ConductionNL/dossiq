<?php

/**
 * BesluitvormingParafeerService Unit Tests
 *
 * Tests for the parafering-chain orchestrator: activation, sequential advance,
 * retour handling, delegation validation, and auto-completion.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\BesluitvormingParafeerService;
use OCA\Procest\Service\ParaferingNotificationService;
use OCA\Procest\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * ObjectService stub matching the named-argument signatures used by
 * BesluitvormingParafeerService.
 */
interface BvwParafeerObjectServiceStub
{
    /**
     * Find a single object by id.
     *
     * @param string $id       The object id.
     * @param string $register The register slug.
     * @param string $schema   The schema id.
     *
     * @return array
     */
    public function find(string $id, string $register, string $schema): array;

    /**
     * Save or update an object.
     *
     * @param string $register The register slug.
     * @param string $schema   The schema id.
     * @param array  $object   The object payload.
     * @param string $id       Optional id for update.
     *
     * @return array
     */
    public function saveObject(string $register, string $schema, array $object, string $id=''): array;

    /**
     * Find objects.
     *
     * @param array $params The query params.
     *
     * @return array
     */
    public function findAll(array $params=[]): array;
}//end interface

/**
 * Unit tests for BesluitvormingParafeerService.
 *
 * @covers \OCA\Procest\Service\BesluitvormingParafeerService
 */
class BesluitvormingParafeerServiceTest extends TestCase
{
    /**
     * The mocked settings service.
     *
     * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
     */
    private SettingsService $settingsService;

    /**
     * The mocked notification service.
     *
     * @var ParaferingNotificationService|\PHPUnit\Framework\MockObject\MockObject
     */
    private ParaferingNotificationService $notificationService;

    /**
     * The service under test.
     *
     * @var BesluitvormingParafeerService
     */
    private BesluitvormingParafeerService $service;

    /**
     * Set up fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->settingsService     = $this->createMock(SettingsService::class);
        $this->notificationService = $this->createMock(ParaferingNotificationService::class);
        $logger                    = $this->createMock(LoggerInterface::class);

        $this->settingsService->method('getConfigValue')->willReturnCallback(
            static fn (string $key): string => $key === 'register' ? 'reg' : 'schema-'.$key,
        );

        $this->service = new BesluitvormingParafeerService(
            $this->settingsService,
            $this->notificationService,
            $logger,
        );
    }//end setUp()

    /**
     * Build a 3-step route snapshot.
     *
     * @return string
     */
    private function routeSnapshot(): string
    {
        return json_encode(
            [
                ['order' => 1, 'type' => 'parafering', 'actor' => 'adviseur', 'actorType' => 'role', 'mandatory' => true],
                ['order' => 2, 'type' => 'parafering', 'actor' => 'hoofd', 'actorType' => 'role', 'mandatory' => true],
                ['order' => 3, 'type' => 'parafering', 'actor' => 'secretaris', 'actorType' => 'role', 'mandatory' => true],
            ],
        );
    }//end routeSnapshot()

    /**
     * activate() snapshots the route, sets currentStep=1, notifies first parafeerder.
     *
     * @return void
     */
    public function testActivateOpensFirstStepAndNotifies(): void
    {
        $voorstel = [
            'id'            => 'v1',
            'onderwerp'     => 'Beleidsplan',
            'case'          => 'c1',
            'routeSnapshot' => $this->routeSnapshot(),
        ];

        $saved = [];
        $objectService = $this->createMock(BvwParafeerObjectServiceStub::class);
        $objectService->method('find')->willReturn($voorstel);
        $objectService->method('saveObject')->willReturnCallback(
            static function (string $r, string $s, array $o) use (&$saved): array {
                $saved = $o;
                $o['id'] = 'v1';
                return $o;
            },
        );

        $this->settingsService->method('getObjectService')->willReturn($objectService);

        $this->notificationService->expects($this->once())
            ->method('notifyStepActivated')
            ->with('adviseur', 'Beleidsplan', $this->anything(), $this->anything());

        $result = $this->service->activate('v1');

        $this->assertSame(BesluitvormingParafeerService::STATUS_IN_PARAFERING, $result['status']);
        $this->assertSame(1, $result['currentStep']);
    }//end testActivateOpensFirstStepAndNotifies()

    /**
     * A goedgekeurd at step 1 advances to step 2 and opens the next parafeerder.
     *
     * @return void
     */
    public function testGoedgekeurdAdvancesToNextStep(): void
    {
        $voorstel = ['id' => 'v1', 'onderwerp' => 'X', 'case' => 'c1', 'currentStep' => 1, 'routeSnapshot' => $this->routeSnapshot()];
        $actie    = ['id' => 'a1', 'action' => 'goedgekeurd', 'step' => 1, 'actorType' => 'user'];

        $objectService = $this->createMock(BvwParafeerObjectServiceStub::class);
        $objectService->method('find')->willReturnCallback(
            static function (string $id) use ($voorstel, $actie): array {
                return $id === 'a1' ? $actie : $voorstel;
            },
        );
        $objectService->method('saveObject')->willReturnArgument(2);

        $this->settingsService->method('getObjectService')->willReturn($objectService);

        $this->notificationService->expects($this->once())
            ->method('notifyStepActivated')
            ->with('hoofd', $this->anything(), $this->anything(), $this->anything());

        $result = $this->service->handleParaafAction('v1', 'a1');
        $this->assertSame(2, $result['currentStep']);
    }//end testGoedgekeurdAdvancesToNextStep()

    /**
     * Final goedgekeurd marks the voorstel gereed_voor_agendering.
     *
     * @return void
     */
    public function testFinalGoedgekeurdCompletesChain(): void
    {
        $voorstel = ['id' => 'v1', 'onderwerp' => 'X', 'case' => 'c1', 'currentStep' => 3, 'caseType' => 'ct1', 'routeSnapshot' => $this->routeSnapshot()];
        $actie    = ['id' => 'a3', 'action' => 'goedgekeurd', 'step' => 3, 'actorType' => 'user'];
        $case     = ['id' => 'c1', 'caseType' => 'ct1'];

        $objectService = $this->createMock(BvwParafeerObjectServiceStub::class);
        $objectService->method('find')->willReturnCallback(
            static function (string $id) use ($voorstel, $actie, $case): array {
                if ($id === 'a3') {
                    return $actie;
                }

                if ($id === 'c1') {
                    return $case;
                }

                return $voorstel;
            },
        );
        $objectService->method('findAll')->willReturn(['results' => [['id' => 'status-gereed']]]);
        $objectService->method('saveObject')->willReturnArgument(2);

        $this->settingsService->method('getObjectService')->willReturn($objectService);

        $result = $this->service->handleParaafAction('v1', 'a3');
        $this->assertSame(BesluitvormingParafeerService::STATUS_GEREED, $result['status']);
    }//end testFinalGoedgekeurdCompletesChain()

    /**
     * A retour returns the voorstel to the steller and records returnedFromStep.
     *
     * @return void
     */
    public function testRetourReturnsToSteller(): void
    {
        $voorstel = ['id' => 'v1', 'onderwerp' => 'X', 'case' => 'c1', 'steller' => 'piet', 'currentStep' => 2, 'routeSnapshot' => $this->routeSnapshot()];
        $actie    = ['id' => 'a2', 'action' => 'retour', 'step' => 2, 'actorType' => 'user', 'actor' => 'hoofd', 'comment' => 'Graag aanpassen'];

        $objectService = $this->createMock(BvwParafeerObjectServiceStub::class);
        $objectService->method('find')->willReturnCallback(
            static fn (string $id): array => $id === 'a2' ? $actie : $voorstel,
        );
        $objectService->method('saveObject')->willReturnArgument(2);

        $this->settingsService->method('getObjectService')->willReturn($objectService);

        $this->notificationService->expects($this->once())
            ->method('notifyVoorstelReturned')
            ->with('piet', $this->anything(), $this->anything(), 'hoofd', 'Graag aanpassen');

        $result = $this->service->handleParaafAction('v1', 'a2');
        $this->assertSame(BesluitvormingParafeerService::STATUS_RETOUR, $result['status']);
        $this->assertSame(2, $result['returnedFromStep']);
    }//end testRetourReturnsToSteller()

    /**
     * A gemachtigde paraaf without onBehalfOf/mandate is rejected.
     *
     * @return void
     */
    public function testDelegationRequiresMandate(): void
    {
        $voorstel = ['id' => 'v1', 'currentStep' => 3, 'routeSnapshot' => $this->routeSnapshot()];
        $actie    = ['id' => 'a3', 'action' => 'goedgekeurd', 'step' => 3, 'actorType' => 'gemachtigde'];

        $objectService = $this->createMock(BvwParafeerObjectServiceStub::class);
        $objectService->method('find')->willReturnCallback(
            static fn (string $id): array => $id === 'a3' ? $actie : $voorstel,
        );

        $this->settingsService->method('getObjectService')->willReturn($objectService);

        $this->expectException(RuntimeException::class);
        $this->service->handleParaafAction('v1', 'a3');
    }//end testDelegationRequiresMandate()

    /**
     * checkAllParafenCollected returns true once the chain is exhausted.
     *
     * @return void
     */
    public function testCheckAllParafenCollected(): void
    {
        $voorstel = ['id' => 'v1', 'currentStep' => 3, 'routeSnapshot' => $this->routeSnapshot()];

        $objectService = $this->createMock(BvwParafeerObjectServiceStub::class);
        $objectService->method('find')->willReturn($voorstel);

        $this->settingsService->method('getObjectService')->willReturn($objectService);

        $this->assertTrue($this->service->checkAllParafenCollected('v1'));
    }//end testCheckAllParafenCollected()
}//end class
