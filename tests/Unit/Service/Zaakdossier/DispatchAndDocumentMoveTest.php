<?php

/**
 * The dispatch row of one correspondent, and the log line of a document move.
 *
 * Pins the lookup a dispatch row is found by, the row it writes per role
 * (a sender gets a receive date, an addressee a send date, any other role
 * neither), and the line logged when a document moves into a case.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/document-correspondents/specs/document-zaakdossier/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zaakdossier;

use OCA\Dossiq\Service\Zaakdossier\Dispatch;
use OCA\Dossiq\Service\Zaakdossier\DocumentCorrespondents;
use OCA\Dossiq\Service\Zaakdossier\DocumentMove;
use PHPUnit\Framework\TestCase;

/**
 * Dispatch lookups and rows, and the document move log line.
 *
 * @covers \OCA\Dossiq\Service\Zaakdossier\Dispatch
 * @covers \OCA\Dossiq\Service\Zaakdossier\DocumentMove
 */
class DispatchAndDocumentMoveTest extends TestCase {

	/**
	 * A dispatch row is looked up by document, party and role, one row at most.
	 *
	 * @return void
	 */
	public function testLookupNamesDocumentPartyAndRole(): void {
		$dispatch = new Dispatch(caseId: 'case-1', documentId: 'doc-1', party: 'party-1', role: DocumentCorrespondents::ROLE_SENDER);

		self::assertSame(
			['document' => 'doc-1', 'involvedParty' => 'party-1', 'relationshipType' => 'afzender', '_limit' => 1],
			$dispatch->lookup()
		);
	}//end testLookupNamesDocumentPartyAndRole()

	/**
	 * A sender's row records the day it was received from them, and no send date.
	 *
	 * @return void
	 */
	public function testSenderRowCarriesOnlyTheReceiveDate(): void {
		$row = (new Dispatch(caseId: 'case-1', documentId: 'doc-1', party: 'party-1', role: DocumentCorrespondents::ROLE_SENDER))->row(today: '2026-11-13');

		self::assertSame(
			['document' => 'doc-1', 'case' => 'case-1', 'involvedParty' => 'party-1', 'relationshipType' => 'afzender', 'receiveDate' => '2026-11-13'],
			$row
		);
	}//end testSenderRowCarriesOnlyTheReceiveDate()

	/**
	 * An addressee's row records the day it was sent to them, and no receive date.
	 *
	 * @return void
	 */
	public function testRecipientRowCarriesOnlyTheSendDate(): void {
		$row = (new Dispatch(caseId: 'case-2', documentId: 'doc-2', party: 'party-2', role: DocumentCorrespondents::ROLE_RECIPIENT))->row(today: '2026-01-02');

		self::assertSame(
			['document' => 'doc-2', 'case' => 'case-2', 'involvedParty' => 'party-2', 'relationshipType' => 'geadresseerde', 'sendDate' => '2026-01-02'],
			$row
		);
	}//end testRecipientRowCarriesOnlyTheSendDate()

	/**
	 * Any other role gets neither date.
	 *
	 * @return void
	 */
	public function testOtherRoleCarriesNoDate(): void {
		$row = (new Dispatch(caseId: 'case-3', documentId: 'doc-3', party: 'party-3', role: 'belanghebbende'))->row(today: '2026-01-02');

		self::assertSame(
			['document' => 'doc-3', 'case' => 'case-3', 'involvedParty' => 'party-3', 'relationshipType' => 'belanghebbende'],
			$row
		);
	}//end testOtherRoleCarriesNoDate()

	/**
	 * The move names the file, the record and the target case.
	 *
	 * @return void
	 */
	public function testMoveLogLineNamesFileRecordAndCase(): void {
		$move = new DocumentMove(record: ['fileName' => 'besluit.pdf', 'title' => 'Besluit'], recordId: 'rec-1', caseId: 'case-9');

		self::assertSame('besluit.pdf', $move->fileName);
		self::assertSame('Dossiq documents: moved besluit.pdf of rec-1 into case case-9', $move->logLine());
	}//end testMoveLogLineNamesFileRecordAndCase()

	/**
	 * A record without a file name logs an empty name, not an error.
	 *
	 * @return void
	 */
	public function testMoveWithoutFileNameLogsAnEmptyName(): void {
		$move = new DocumentMove(record: ['fileName' => 42], recordId: 'rec-2', caseId: 'case-1');
		self::assertSame('42', $move->fileName);

		$bare = new DocumentMove(record: [], recordId: 'rec-3', caseId: 'case-1');
		self::assertSame('', $bare->fileName);
		self::assertSame('Dossiq documents: moved  of rec-3 into case case-1', $bare->logLine());
	}//end testMoveWithoutFileNameLogsAnEmptyName()
}//end class
