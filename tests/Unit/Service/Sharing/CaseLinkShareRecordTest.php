<?php

/**
 * Unit tests for CaseLinkShareRecord.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Sharing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Sharing;

use OCA\Dossiq\Service\Sharing\CaseLinkShareRecord;
use OCA\Dossiq\Service\Sharing\MintedShare;
use PHPUnit\Framework\TestCase;

/**
 * The record written for a minted link, the link ids a record holds and the anchor of a link address.
 *
 * @covers \OCA\Dossiq\Service\Sharing\CaseLinkShareRecord
 * @uses   \OCA\Dossiq\Service\Sharing\MintedShare
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class CaseLinkShareRecordTest extends TestCase {

	/**
	 * The record's own fields win over the extra fields, and a comment grant reads as view-and-respond.
	 *
	 * @return void
	 */
	public function testTheRecordOfAMintedLink(): void {
		$record = (new CaseLinkShareRecord())->forMinted(
			share: new MintedShare(
				caseId: 'case-1',
				label: 'Aanvrager',
				createdBy: 'ann',
				link: ['id' => '7', 'uuid' => 'link-7', 'url' => 'https://nc.example/l/A', 'expiresAt' => '2026-12-31'],
				capabilities: ['read', 'comment'],
				documents: [['fileId' => 3, 'linkId' => 8]],
				extra: ['recipientEmail' => 'a@example.org', 'status' => 'overridden', 'caseId' => 'other'],
			)
		);

		$this->assertSame(
			expected: [
				'recipientEmail' => 'a@example.org',
				'status' => 'active',
				'caseId' => 'case-1',
				'shareType' => 'link',
				'permissionLevel' => 'bekijken_reageren',
				'label' => 'Aanvrager',
				'createdBy' => 'ann',
				'accessLinkId' => 7,
				'accessLinkUuid' => 'link-7',
				'accessLinkUrl' => 'https://nc.example/l/A',
				'capabilities' => 'read,comment',
				'expiresAt' => '2026-12-31',
				'sharedDocuments' => '[{"fileId":3,"linkId":8}]',
			],
			actual: $record
		);
	}//end testTheRecordOfAMintedLink()

	/**
	 * A read-only link without link details is view-only, with empty link fields.
	 *
	 * @return void
	 */
	public function testAReadOnlyLinkIsViewOnly(): void {
		$record = (new CaseLinkShareRecord())->forMinted(
			share: new MintedShare(caseId: 'case-1', label: '', createdBy: 'ann', link: [], capabilities: ['read'], documents: [], extra: [])
		);

		$this->assertSame(expected: 'bekijken', actual: $record['permissionLevel']);
		$this->assertSame(expected: 0, actual: $record['accessLinkId']);
		$this->assertSame(expected: '', actual: $record['accessLinkUrl']);
		$this->assertSame(expected: '[]', actual: $record['sharedDocuments']);
	}//end testAReadOnlyLinkIsViewOnly()

	/**
	 * The case link comes first, then each document link, from stored text or a list; empty and junk entries drop.
	 *
	 * @return void
	 */
	public function testTheLinkIdsOfAStoredRecord(): void {
		$records = new CaseLinkShareRecord();

		$this->assertSame(
			expected: [10, 11, 12],
			actual: $records->linkIds(share: ['accessLinkId' => '10', 'sharedDocuments' => '[{"linkId":11},{"linkId":0},"junk",{"linkId":12}]'])
		);
		$this->assertSame(expected: [21], actual: $records->linkIds(share: ['sharedDocuments' => [['linkId' => 21], ['fileId' => 4]]]));
		$this->assertSame(expected: [], actual: $records->linkIds(share: ['accessLinkId' => 0, 'sharedDocuments' => 'not json']));
	}//end testTheLinkIdsOfAStoredRecord()

	/**
	 * The anchor is the last path segment of a trimmed address, and nothing for an empty one.
	 *
	 * @return void
	 */
	public function testTheAnchorOfALinkAddress(): void {
		$records = new CaseLinkShareRecord();

		$this->assertSame(expected: 'ANCHOR-2', actual: $records->anchorOf(url: ' https://nc.example/apps/openregister/l/ANCHOR-2 '));
		$this->assertSame(expected: 'bare', actual: $records->anchorOf(url: 'bare'));
		$this->assertSame(expected: '', actual: $records->anchorOf(url: 'https://nc.example/l/'));
		$this->assertSame(expected: '', actual: $records->anchorOf(url: '   '));
	}//end testTheAnchorOfALinkAddress()

}//end class
