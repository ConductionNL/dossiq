<?php

/**
 * BrcDocumentLinkHandler edges: a link the store answers as nothing.
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
use OCA\Dossiq\Service\Zgw\BrcDocumentLinkHandler;
use OCA\Dossiq\Service\ZgwService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * A delete whose read the store answers with no object, rather than an exception.
 *
 * @covers \OCA\Dossiq\Service\Zgw\BrcDocumentLinkHandler
 * @uses \OCA\Dossiq\Service\Zgw\BioRequest
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */
final class BrcDocumentLinkHandlerEdgesTest extends TestCase {
	private const BIO = '33333333-3333-4333-8333-333333333333';

	/**
	 * A null read is the service's 404: nothing is deleted, mirrored, announced or reported as a failure.
	 *
	 * @return void
	 */
	public function testADeleteOfALinkTheStoreAnswersAsNothingIsTheNotFoundAnswer(): void {
		$store = new RecordsBrcStoreCalls();
		$store->objects[self::BIO] = null;
		$notFound = new JSONResponse(data: ['detail' => 'Not found'], statusCode: 404);

		$zgw = $this->createMock(ZgwService::class);
		$zgw->method('getObjectService')->willReturn($store);
		$zgw->method('loadMappingConfig')->willReturn(['sourceRegister' => '7', 'sourceSchema' => '71']);
		$zgw->expects($this->once())->method('notFoundResponse')->willReturn($notFound);
		$zgw->expects($this->never())->method('storeFailureResponse');
		$zgw->expects($this->never())->method('notifyDeleted');
		$sync = $this->createMock(BrcCrossRegisterSyncInterface::class);
		$sync->expects($this->never())->method('deleteOioByDecisionAndIo');

		$response = (new BrcDocumentLinkHandler(zgwService: $zgw, sync: $sync))
			->destroy(request: $this->createMock(IRequest::class), uuid: self::BIO);

		$this->assertSame($notFound, $response);
		$this->assertSame([[self::BIO, '7', '71']], $store->callsOf('find'));
		$this->assertSame([], $store->callsOf('deleteObject'));
	}//end testADeleteOfALinkTheStoreAnswersAsNothingIsTheNotFoundAnswer()
}//end class
