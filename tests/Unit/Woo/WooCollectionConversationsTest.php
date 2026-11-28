<?php

/**
 * Woo Collection Conversations Test
 *
 * The collected e-mails of a Woo request grouped into the conversations they
 * belong to, oldest message first, and an exclusion refused when the
 * exclusion schema is not configured.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Woo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-collected-e-mails-are-grouped-into-the-conversation-they-belong-to-req-wrc-006
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Woo;

use OCA\Dossiq\Service\RecordTemplate\RecordTemplateReader;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\WOODocumentAssessmentService;
use OCA\Dossiq\Service\Zaakdossier\CaseDocumentProvenance;
use OCA\Dossiq\Service\Zaakdossier\DocumentRecordStore;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\Dossiq\Tests\Support\MakesCaseReviewerAccess;
use OCA\Dossiq\Woo\WooCaseDocuments;
use OCA\Dossiq\Woo\WooCollection;
use OCA\Dossiq\Woo\WooSearchPlans;
use OCP\Files\IRootFolder;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Woo\WooCollection
 * @uses   \OCA\Dossiq\Woo\WooSearchPlans
 * @uses   \OCA\Dossiq\Service\WOODocumentAssessmentService
 * @uses   \OCA\Dossiq\Service\AssessmentBatchTarget
 * @uses   \OCA\Dossiq\Service\Assessment\PartlyPublicRows
 * @uses   \OCA\Dossiq\Woo\WooRefusalGrounds
 * @uses   \OCA\Dossiq\Woo\WooCaseDocuments
 * @uses   \OCA\Dossiq\Service\Zaakdossier\DocumentRecordStore
 * @uses   \OCA\Dossiq\Service\Zaakdossier\CaseDocumentProvenance
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses   \OCA\Dossiq\Service\Settings\RegisterFragmentMerger
 * @uses   \OCA\Dossiq\Review\DisclosureAssessmentValidator
 * @uses   \OCA\Dossiq\Review\OutstandingDocuments
 * @uses   \OCA\Dossiq\Service\Access\CaseRecordLookup
 * @uses   \OCA\Dossiq\Service\Access\CaseReviewerAccess
 * @uses   \OCA\Dossiq\Service\Access\CaseRecordReaders
 * @uses   \OCA\Dossiq\Service\CaseTypeStore
 * @uses   \OCA\Dossiq\Service\Custody\DossierRegister
 * @uses   \OCA\Dossiq\Service\WooAssessmentLocation
 */
class WooCollectionConversationsTest extends TestCase {

	use MakesCaseReviewerAccess;

	private const CASE_ID = '11111111-1111-4111-8111-111111111111';

	private InMemoryRegister $register;

	/**
	 * The collection over one in-memory register.
	 *
	 * @param array<string, string> $config The config values, replacing the defaults.
	 *
	 * @return WooCollection The collection.
	 */
	private function collection(array $config = []): WooCollection {
		$values = array_merge(
			[
				'register' => 'dossiq',
				'dossier_informatieobject_schema' => 'informatieobject',
				'dossier_zaakinformatieobject_schema' => 'zaakinformatieobject',
				'woo_assessment_schema' => 'wooDocumentAssessment',
				'woo_exclusion_schema' => 'wooExclusion',
				'woo_search_plan_schema' => 'wooSearchPlan',
				'woo_request_configuration_schema' => 'wooRequestConfiguration',
			],
			$config
		);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->register);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => $values[$key] ?? $default
		);
		$logger = $this->createMock(LoggerInterface::class);
		$caseDocuments = new WooCaseDocuments(settingsService: $settings, rootFolder: $this->createMock(IRootFolder::class), logger: $logger);
		$access = $this->caseReviewerAccessOver($settings);

		return new WooCollection(
			settingsService: $settings,
			caseDocuments: $caseDocuments,
			store: new DocumentRecordStore(settingsService: $settings),
			assessments: new WOODocumentAssessmentService(settingsService: $settings, userSession: $this->createMock(IUserSession::class), logger: $logger, caseDocuments: $caseDocuments),
			plans: new WooSearchPlans(settingsService: $settings, templates: $this->createMock(RecordTemplateReader::class), access: $access),
			provenance: new CaseDocumentProvenance(store: new DocumentRecordStore(settingsService: $settings), caseDocuments: $caseDocuments),
			access: $access,
		);
	}//end collection()

	protected function setUp(): void {
		$this->register = new InMemoryRegister();
	}//end setUp()

	/**
	 * Put a collected mail on the case.
	 *
	 * @param string $id       The document uuid.
	 * @param string $threadId The conversation it belongs to, '' for none.
	 * @param string $sentAt   When it was sent, '' when unknown.
	 *
	 * @return void
	 */
	private function mail(string $id, string $threadId, string $sentAt): void {
		$this->register->seed('informatieobject', $id, [
			'provenance' => ['source' => 'microsoft365', 'custodian' => 'A', 'threadId' => $threadId, 'sentAt' => $sentAt],
		]);
		$this->register->seed('zaakinformatieobject', 'join-' . $id, ['case' => self::CASE_ID, 'informatieobject' => $id]);
	}//end mail()

	public function testMailsAreGroupedPerConversationOldestFirst(): void {
		$this->mail(id: 'mail-3', threadId: 'thread-b', sentAt: '2026-03-05T09:00:00Z');
		$this->mail(id: 'mail-2', threadId: 'thread-a', sentAt: '2026-03-02T09:00:00Z');
		$this->mail(id: 'mail-1', threadId: 'thread-a', sentAt: '2026-03-01T09:00:00Z');
		$this->mail(id: 'mail-4', threadId: 'thread-b', sentAt: '');
		$this->mail(id: 'note-1', threadId: '', sentAt: '2026-01-01T09:00:00Z');

		$report = $this->collection()->report(caseId: self::CASE_ID);

		self::assertSame(2, $report['conversations']);
		self::assertSame(5, $report['documents']);
		self::assertSame(
			[
				[
					'threadId' => 'thread-a',
					'messages' => [
						['documentId' => 'mail-1', 'sentAt' => '2026-03-01T09:00:00Z'],
						['documentId' => 'mail-2', 'sentAt' => '2026-03-02T09:00:00Z'],
					],
				],
				[
					'threadId' => 'thread-b',
					'messages' => [
						['documentId' => 'mail-3', 'sentAt' => '2026-03-05T09:00:00Z'],
						['documentId' => 'mail-4', 'sentAt' => ''],
					],
				],
			],
			$report['threads']
		);
	}//end testMailsAreGroupedPerConversationOldestFirst()

	public function testACaseWithoutMailHasNoConversations(): void {
		$this->mail(id: 'note-1', threadId: '', sentAt: '');

		$report = $this->collection()->report(caseId: self::CASE_ID);

		self::assertSame(0, $report['conversations']);
		self::assertSame([], $report['threads']);
	}//end testACaseWithoutMailHasNoConversations()

	public function testAnExclusionIsRefusedWithoutTheExclusionSchema(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('The Woo exclusion schema is not configured');

		$this->collection(config: ['woo_exclusion_schema' => ''])->record(caseId: self::CASE_ID, fields: ['source' => 'files', 'reason' => 'unreadable']);
	}//end testAnExclusionIsRefusedWithoutTheExclusionSchema()
}//end class
