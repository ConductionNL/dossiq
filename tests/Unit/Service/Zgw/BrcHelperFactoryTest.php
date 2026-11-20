<?php

/**
 * BrcHelperFactory Unit Tests
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Zgw
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
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zgw;

use OCA\Dossiq\Kernel\Contracts\BrcCrossRegisterSyncInterface;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Zgw\BrcCrossRegisterSync;
use OCA\Dossiq\Service\Zgw\BrcDocumentLinkHandler;
use OCA\Dossiq\Service\Zgw\BrcHelperFactory;
use OCA\Dossiq\Service\ZgwBusinessRulesService;
use OCA\Dossiq\Service\ZgwService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Each BRC helper the factory builds is the real implementation, wired to the collaborators it was given.
 *
 * @covers \OCA\Dossiq\Service\Zgw\BrcHelperFactory
 * @uses \OCA\Dossiq\Service\Zgw\BrcCrossRegisterSync
 * @uses \OCA\Dossiq\Service\Zgw\BrcDocumentLinkHandler
 * @uses \OCA\Dossiq\Service\Zgw\BioRequest
 * @uses \OCA\Dossiq\Support\NormalisesObjectRows
 *
 * @spec exclude Structural factory, no functional requirement.
 */
final class BrcHelperFactoryTest extends TestCase {
	/**
	 * The sync reads the store from the given ZGW service and the schema from the given settings.
	 *
	 * Without a zaakbesluit schema it logs the skip on the ZGW service's logger and writes nothing.
	 *
	 * @return void
	 */
	public function testTheSyncUsesTheGivenServiceAndSettings(): void {
		$store = new RecordsBrcStoreCalls();
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('debug')->with('brc-006: case_decision_schema not configured, skipping zaakbesluit sync');

		$zgw = $this->createMock(ZgwService::class);
		$zgw->expects($this->once())->method('getObjectService')->willReturn($store);
		$zgw->method('getLogger')->willReturn($logger);
		$settings = $this->createMock(SettingsService::class);
		$settings->expects($this->once())->method('getConfigValue')->with('case_decision_schema')->willReturn('');

		$sync = (new BrcHelperFactory())->brcCrossRegisterSync(zgwService: $zgw, settingsService: $settings);

		$this->assertInstanceOf(BrcCrossRegisterSync::class, $sync);
		$sync->syncCaseDecisionToZrc(caseUrl: 'https://nc/zaken/v1/zaken/z', decisionUrl: 'https://nc/besluiten/v1/besluiten/b');
		$this->assertSame([], $store->calls);
	}//end testTheSyncUsesTheGivenServiceAndSettings()

	/**
	 * The handler stores through the given ZGW service and mirrors the link through the given sync.
	 *
	 * @return void
	 */
	public function testTheHandlerUsesTheGivenServiceAndSync(): void {
		$store = new RecordsBrcStoreCalls();
		$store->saved = ['id' => 'bio-1'];
		$body = ['decision' => 'https://nc/besluiten/v1/besluiten/b', 'informatieobject' => 'https://nc/documenten/v1/eio/d'];

		$rules = $this->createMock(ZgwBusinessRulesService::class);
		$rules->method('validate')->willReturn(['valid' => true, 'status' => 200, 'detail' => '', 'enrichedBody' => $body]);
		$zgw = $this->createMock(ZgwService::class);
		$zgw->method('getObjectService')->willReturn($store);
		$zgw->method('loadMappingConfig')->willReturn(['sourceRegister' => '7', 'sourceSchema' => '71']);
		$zgw->method('getRequestBody')->willReturn($body);
		$zgw->method('getBusinessRulesService')->willReturn($rules);
		$zgw->method('createInboundMapping')->willReturn(new \stdClass());
		$zgw->method('applyInboundMapping')->willReturnArgument(0);
		$zgw->method('createOutboundMapping')->willReturn(new \stdClass());
		$zgw->method('applyOutboundMapping')->willReturnArgument(0);
		$zgw->method('buildBaseUrl')->willReturn('https://nc/besluiten/v1/besluitinformatieobjecten');

		$sync = $this->createMock(BrcCrossRegisterSyncInterface::class);
		$sync->expects($this->once())->method('createOioInDrc')->with($body['decision'], $body['informatieobject']);

		$handler = (new BrcHelperFactory())->brcDocumentLinkHandler(zgwService: $zgw, sync: $sync);
		$response = $handler->create(request: $this->createMock(IRequest::class));

		$this->assertInstanceOf(BrcDocumentLinkHandler::class, $handler);
		$this->assertSame(201, $response->getStatus());
		$this->assertSame([['7', '71', $body]], $store->callsOf('saveObject'));
	}//end testTheHandlerUsesTheGivenServiceAndSync()
}//end class
