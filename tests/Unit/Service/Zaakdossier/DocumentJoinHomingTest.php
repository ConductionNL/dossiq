<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A zaakinformatieobject names the case an API-first document moves into.
 *
 * @spec openspec/specs/document-projection/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zaakdossier;

use OCA\Dossiq\Service\Zaakdossier\DocumentJoinHoming;
use OCA\Dossiq\Service\Zaakdossier\DocumentProjectionService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class DocumentJoinHomingTest extends TestCase {
	private const CASE_URL = 'https://gemeente.example/api/zgw/zaken/v1/zaken/11111111-1111-4111-8111-111111111111';
	private const IO_URL = 'https://gemeente.example/api/zgw/documenten/v1/enkelvoudiginformatieobjecten/33333333-3333-4333-8333-333333333333/';

	/** @var DocumentProjectionService&MockObject The projection double. */
	private DocumentProjectionService&MockObject $projection;

	/** @var LoggerInterface&MockObject Where a failed move lands. */
	private LoggerInterface&MockObject $logger;

	/** @var DocumentJoinHoming The collaborator under test. */
	private DocumentJoinHoming $homing;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->projection = $this->createMock(originalClassName: DocumentProjectionService::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
		$this->homing = new DocumentJoinHoming(projection: $this->projection, logger: $this->logger);
	}//end setUp()

	/**
	 * @return void
	 */
	public function testAUrlOrABareUuidBothNameTheObject(): void {
		$this->assertSame(expected: '11111111-1111-4111-8111-111111111111', actual: $this->homing->uuidFromUrl(url: self::CASE_URL));
		$this->assertSame(expected: '33333333-3333-4333-8333-333333333333', actual: $this->homing->uuidFromUrl(url: self::IO_URL));
		$this->assertSame(expected: 'abc', actual: $this->homing->uuidFromUrl(url: ' abc '));
		$this->assertSame(expected: '', actual: $this->homing->uuidFromUrl(url: ''));
	}//end testAUrlOrABareUuidBothNameTheObject()

	/**
	 * @return void
	 */
	public function testAJoinToACaseWithoutAFolderIsRefusedByName(): void {
		$this->projection->method('caseHasFolder')->with('11111111-1111-4111-8111-111111111111')->willReturn(false);

		$refusal = $this->homing->refusal(caseUrl: self::CASE_URL);

		$this->assertSame(expected: 'Case 11111111-1111-4111-8111-111111111111 has no folder to hold its documents', actual: $refusal);
	}//end testAJoinToACaseWithoutAFolderIsRefusedByName()

	/**
	 * @return void
	 */
	public function testAJoinToACaseWithAFolderProceeds(): void {
		$this->projection->method('caseHasFolder')->willReturn(true);
		$this->assertNull(actual: $this->homing->refusal(caseUrl: self::CASE_URL));
		$this->assertNull(actual: $this->homing->refusal(caseUrl: ''), message: 'nothing to refuse on; the rules validate the reference');
	}//end testAJoinToACaseWithAFolderProceeds()

	/**
	 * @return void
	 */
	public function testTheJoinMovesTheFileThroughTheProjection(): void {
		$this->projection->expects($this->once())->method('homeDocument')
			->with('33333333-3333-4333-8333-333333333333', '11111111-1111-4111-8111-111111111111')
			->willReturn(true);

		$this->assertTrue(condition: $this->homing->home(caseUrl: self::CASE_URL, informatieobjectUrl: self::IO_URL));
	}//end testTheJoinMovesTheFileThroughTheProjection()

	/**
	 * @return void
	 */
	public function testAFailedMoveIsLoggedAndTheJoinStands(): void {
		$this->projection->method('homeDocument')->willThrowException(new RuntimeException('storage gone'));
		$this->logger->expects($this->once())->method('warning')->with(
			$this->stringContains(string: 'storage gone'),
			$this->arrayHasKey(key: 'exception')
		);

		$this->assertFalse(condition: $this->homing->home(caseUrl: self::CASE_URL, informatieobjectUrl: self::IO_URL));
	}//end testAFailedMoveIsLoggedAndTheJoinStands()

	/**
	 * @return void
	 */
	public function testNothingMovesWithoutBothReferences(): void {
		$this->projection->expects($this->never())->method('homeDocument');
		$this->assertFalse(condition: $this->homing->home(caseUrl: '', informatieobjectUrl: self::IO_URL));
		$this->assertFalse(condition: $this->homing->home(caseUrl: self::CASE_URL, informatieobjectUrl: ''));
	}//end testNothingMovesWithoutBothReferences()
}//end class
