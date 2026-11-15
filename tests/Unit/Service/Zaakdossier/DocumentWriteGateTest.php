<?php

/**
 * DocumentWriteGate: the refusals a document write or bulk action answers.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zaakdossier;

use OCA\Dossiq\Service\Zaakdossier\BulkDocumentActions;
use OCA\Dossiq\Service\Zaakdossier\DocumentCaseWriteGuard;
use OCA\Dossiq\Service\Zaakdossier\DocumentWriteGate;
use OCP\IUser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A write to a document of a case the user may not change is refused with 403, naming document and case.
 */
#[CoversClass(DocumentWriteGate::class)]
final class DocumentWriteGateTest extends TestCase {

	private DocumentCaseWriteGuard&MockObject $caseWrites;
	private BulkDocumentActions&MockObject $bulk;
	private IUser $user;
	private DocumentWriteGate $gate;

	protected function setUp(): void {
		$this->caseWrites = $this->createMock(DocumentCaseWriteGuard::class);
		$this->bulk       = $this->createMock(BulkDocumentActions::class);
		$this->user       = $this->createMock(IUser::class);
		$this->gate       = new DocumentWriteGate($this->caseWrites, $this->bulk);
	}//end setUp()

	/**
	 * The refusal body for a document of a case.
	 *
	 * @param string $documentId The document.
	 * @param string $caseId     The case.
	 *
	 * @return array<string, string>
	 */
	private static function refusal(string $documentId, string $caseId): array {
		return [
			'error' => 'You may not change a case this document belongs to; nothing was changed',
			'documentId' => $documentId,
			'caseId' => $caseId,
		];
	}//end refusal()

	public function testAWriteToAChangeableCaseIsLetThrough(): void {
		$this->caseWrites->expects($this->once())->method('refusedCase')->with($this->user, 'doc-1')->willReturn(null);

		$this->assertNull($this->gate->refusedWrite($this->user, 'doc-1'));
	}//end testAWriteToAChangeableCaseIsLetThrough()

	public function testAWriteToAnUnchangeableCaseIsRefused(): void {
		$this->caseWrites->method('refusedCase')->willReturn('case-9');

		$response = $this->gate->refusedWrite($this->user, 'doc-1');

		$this->assertSame(403, $response->getStatus());
		$this->assertSame(self::refusal('doc-1', 'case-9'), $response->getData());
	}//end testAWriteToAnUnchangeableCaseIsRefused()

	public function testBulkMetadataIsRefusedOnTheFirstRefusedDocument(): void {
		$this->caseWrites->expects($this->once())->method('firstRefused')->with($this->user, ['a', 'b'])
			->willReturn(['documentId' => 'b', 'caseId' => 'case-2']);

		$response = $this->gate->refusedBulkMetadata($this->user, ['a', 'b']);

		$this->assertSame(403, $response->getStatus());
		$this->assertSame(self::refusal('b', 'case-2'), $response->getData());
	}//end testBulkMetadataIsRefusedOnTheFirstRefusedDocument()

	public function testBulkMetadataOnChangeableCasesIsLetThrough(): void {
		$this->caseWrites->method('firstRefused')->willReturn(null);

		$this->assertNull($this->gate->refusedBulkMetadata($this->user, ['a']));
	}//end testBulkMetadataOnChangeableCasesIsLetThrough()

	public function testABulkTransitionChecksTheCaseFirstWithoutReadingClearance(): void {
		$this->caseWrites->method('firstRefused')->willReturn(['documentId' => 'a', 'caseId' => 'case-1']);
		$this->bulk->expects($this->never())->method('allReadable');

		$this->assertSame(self::refusal('a', 'case-1'), $this->gate->refusedBulkTransition($this->user, ['a'])->getData());
	}//end testABulkTransitionChecksTheCaseFirstWithoutReadingClearance()

	public function testABulkTransitionNeedsClearanceForEveryDocument(): void {
		$this->caseWrites->method('firstRefused')->willReturn(null);
		$this->bulk->expects($this->once())->method('allReadable')->with($this->user, ['a', 'b'])->willReturn(false);

		$response = $this->gate->refusedBulkTransition($this->user, ['a', 'b']);

		$this->assertSame(403, $response->getStatus());
		$this->assertSame(['error' => 'Insufficient clearance for one or more selected documents'], $response->getData());
	}//end testABulkTransitionNeedsClearanceForEveryDocument()

	public function testAClearedBulkTransitionIsLetThrough(): void {
		$this->caseWrites->method('firstRefused')->willReturn(null);
		$this->bulk->method('allReadable')->willReturn(true);

		$this->assertNull($this->gate->refusedBulkTransition($this->user, ['a']));
	}//end testAClearedBulkTransitionIsLetThrough()

	public function testUpdatesAndChangeRightsAreDelegated(): void {
		$this->bulk->expects($this->once())->method('updateAll')->with($this->user, ['a'], ['k' => 'v'])->willReturn([['id' => 'a', 'success' => true]]);
		$this->caseWrites->expects($this->once())->method('withChangeRight')->with($this->user, [['id' => 'a']])->willReturn([['id' => 'a', 'canChange' => true]]);

		$this->assertSame([['id' => 'a', 'success' => true]], $this->gate->updateAll($this->user, ['a'], ['k' => 'v']));
		$this->assertSame([['id' => 'a', 'canChange' => true]], $this->gate->withChangeRight($this->user, [['id' => 'a']]));
	}//end testUpdatesAndChangeRightsAreDelegated()
}//end class
