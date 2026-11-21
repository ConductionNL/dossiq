<?php

/**
 * Unit tests for FederatedShareSnapshot.
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

use JsonSerializable;
use OCA\Dossiq\Service\CaseSharingService;
use OCA\Dossiq\Service\Sharing\FederatedShareRequest;
use OCA\Dossiq\Service\Sharing\FederatedShareSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * A snapshot carries only allowed fields the case has and documents on the case, and names its case.
 *
 * @covers \OCA\Dossiq\Service\Sharing\FederatedShareSnapshot
 * @uses   \OCA\Dossiq\Service\Sharing\FederatedShareRequest
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class FederatedShareSnapshotTest extends TestCase {

	/**
	 * A request for the given fields and documents.
	 *
	 * @param array<string> $fields    The requested fields.
	 * @param array<string> $documents The requested documents.
	 *
	 * @return FederatedShareRequest The request.
	 */
	private function request(array $fields, array $documents): FederatedShareRequest {
		return new FederatedShareRequest(
			caseId: 'case-1',
			remoteCloudId: 'org@remote',
			sharedFields: $fields,
			sharedDocuments: $documents,
			permissionLevel: 'view',
			createdBy: 'alice'
		);
	}//end request()

	/**
	 * Every allowed field passes; anything else is refused, named.
	 *
	 * @return void
	 */
	public function testOnlyAllowedFieldsMayCross(): void {
		$snapshot = new FederatedShareSnapshot();

		self::assertNull(actual: $snapshot->fieldRefusal(sharedFields: CaseSharingService::FEDERATION_ALLOWED_FIELDS));
		self::assertSame(
			expected: ['error' => 'Field(s) not shareable across a federation boundary: @self, internalRiskScore'],
			actual: $snapshot->fieldRefusal(sharedFields: ['@self', 'internalRiskScore'])
		);
	}//end testOnlyAllowedFieldsMayCross()

	/**
	 * The record keeps the fields the case has and its own documents; a foreign document is refused.
	 *
	 * @return void
	 */
	public function testTheRecordCarriesOnlyWhatTheCaseHas(): void {
		$snapshot = new FederatedShareSnapshot();
		$case = ['title' => 'Bouwvergunning', 'status' => 'open', 'documents' => ['doc-1', 'doc-2']];

		self::assertSame(
			expected: [
				'caseId' => 'case-1',
				'remoteCloudId' => 'org@remote',
				'sharedFields' => ['title', 'description'],
				'sharedDocuments' => ['doc-2'],
				'fieldSnapshot' => ['title' => 'Bouwvergunning'],
				'permissionLevel' => 'view',
				'status' => 'pending',
				'createdBy' => 'alice',
			],
			actual: $snapshot->record(request: $this->request(fields: ['title', 'description'], documents: ['doc-2']), caseData: $case)
		);
		self::assertSame(
			expected: ['error' => 'Document(s) not attached to this case: doc-9'],
			actual: $snapshot->record(request: $this->request(fields: [], documents: ['doc-1', 'doc-9']), caseData: $case)
		);
	}//end testTheRecordCarriesOnlyWhatTheCaseHas()

	/**
	 * The case of a stored snapshot, array or entity; none when not found or not named.
	 *
	 * @return void
	 */
	public function testTheCaseOfAStoredSnapshot(): void {
		$snapshot = new FederatedShareSnapshot();
		$entity = new class implements JsonSerializable {
			/**
			 * The stored row.
			 *
			 * @return array<string, string>
			 */
			public function jsonSerialize(): array {
				return ['caseId' => 'case-2'];
			}//end jsonSerialize()
		};

		self::assertSame(expected: 'case-1', actual: $snapshot->caseIdOf(stored: ['caseId' => 'case-1']));
		self::assertSame(expected: 'case-2', actual: $snapshot->caseIdOf(stored: $entity));
		self::assertNull(actual: $snapshot->caseIdOf(stored: ['status' => 'active']));
		self::assertNull(actual: $snapshot->caseIdOf(stored: null));
	}//end testTheCaseOfAStoredSnapshot()
}//end class
