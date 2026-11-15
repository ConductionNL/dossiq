<?php

/**
 * Who reads a private record of a case, from the register as it ships
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use OCA\Dossiq\Tests\Support\ShippedPrivateRecords;
use PHPUnit\Framework\TestCase;

/**
 * A record that became private: the colleague who read it before is refused, the people on the case are not.
 *
 * Runs the shipped case type and the shipped schema blocks through the real
 * CaseReviewerAccess. The verdict itself is a model of OpenRegister's rule
 * (see ShippedPrivateRecords); the refusal on a running instance is task 6.4
 * of woo-review-triage (live pass, decision 139).
 *
 * @covers \OCA\Dossiq\Service\Access\CaseReviewerAccess
 * @covers \OCA\Dossiq\Service\Access\CaseRecordReaders
 * @uses   \OCA\Dossiq\Service\Access\CaseRecordLookup
 * @uses   \OCA\Dossiq\Service\CaseTypeStore
 * @uses   \OCA\Dossiq\Service\Settings\RegisterFragmentMerger
 * @uses   \OCA\Dossiq\Service\Settings\SchemaSlugMap
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 *
 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-a-case-type-says-per-record-who-on-the-case-may-update-it-req-cra-010
 */
class CaseTypePrivateRecordAccessTest extends TestCase {

	private const CASE_ID = '44444444-4444-4444-8444-444444444444';

	private const STAFF = ['dossiq-medewerkers'];

	private ShippedPrivateRecords $shipped;

	/**
	 * One case of the shipped Woo request type: a handler, two batches with a reviewer each,
	 * a colleague in the staff group who is not on the case, and somebody outside the staff group.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->shipped = new ShippedPrivateRecords($this);
		$this->shipped->groups = [
			'j.devries' => self::STAFF,
			'p.bakker' => self::STAFF,
			'a.smit' => self::STAFF,
			'colleague' => self::STAFF,
			'coordinator' => ['dossiq-medewerkers', 'dossiq-coordinators'],
			'administrator' => ['admin'],
			'outsider' => [],
		];
		$caseType = $this->shipped->caseType(slug: 'woo-verzoek');
		self::assertNotSame([], $caseType, 'The register ships the Woo request type.');

		$rows = $this->shipped->rows;
		$rows->seed('case', self::CASE_ID, ['caseType' => (string)$caseType['id'], 'assignee' => 'j.devries']);
		$rows->seed('reviewBatch', 'batch-1', ['case' => self::CASE_ID, 'assignee' => 'p.bakker', '@self' => ['owner' => 'j.devries']]);
		$rows->seed('reviewBatch', 'batch-2', ['case' => self::CASE_ID, 'assignee' => 'a.smit', '@self' => ['owner' => 'j.devries']]);
		// A review the upgrade's repair step made (no owner), one a rule made for the handler, one a reviewer made.
		$rows->seed('documentReview', 'review-1', ['case' => self::CASE_ID, 'documentRef' => 'doc-1', 'batch' => 'batch-1', '@self' => ['owner' => '']]);
		$rows->seed('documentReview', 'review-2', ['case' => self::CASE_ID, 'documentRef' => 'doc-2', 'batch' => 'batch-2', '@self' => ['owner' => 'j.devries']]);
		$rows->seed('documentReview', 'review-3', ['case' => self::CASE_ID, 'documentRef' => 'doc-3', '@self' => ['owner' => 'a.smit']]);
		$rows->seed('documentReview', 'review-other', ['case' => 'another-case', 'documentRef' => 'doc-1', 'batch' => 'batch-1']);
	}//end setUp()

	/**
	 * Before the reconcile nobody but an administrator and the record's own author reads a review:
	 * that is what an administrator's reconcile after the upgrade ends.
	 *
	 * @return void
	 */
	public function testBeforeTheReconcileAReviewWaitsForAnAdministrator(): void {
		self::assertFalse($this->shipped->admits(schema: 'documentReview', uuid: 'review-1', user: 'j.devries', action: 'read'));
		self::assertFalse($this->shipped->admits(schema: 'documentReview', uuid: 'review-1', user: 'p.bakker', action: 'update'));
		self::assertTrue($this->shipped->admits(schema: 'documentReview', uuid: 'review-1', user: 'administrator', action: 'read'));
		self::assertTrue($this->shipped->admits(schema: 'documentReview', uuid: 'review-3', user: 'a.smit', action: 'update'), 'its author');
	}//end testBeforeTheReconcileAReviewWaitsForAnAdministrator()

	/**
	 * A colleague in the staff group read every review while the schema was open to staff. Not any more.
	 *
	 * @return void
	 */
	public function testAColleagueOffTheCaseNoLongerReadsAReview(): void {
		self::assertSame('reconciled', $this->shipped->reconcile(caseTypeSlug: 'woo-verzoek', caseId: self::CASE_ID)['status']);

		foreach (['review-1', 'review-2', 'review-3'] as $uuid) {
			self::assertFalse($this->shipped->admits(schema: 'documentReview', uuid: $uuid, user: 'colleague', action: 'read'), $uuid);
			self::assertFalse($this->shipped->admits(schema: 'documentReview', uuid: $uuid, user: 'colleague', action: 'update'), $uuid);
		}

		self::assertArrayNotHasKey('review-other', $this->shipped->grants, 'a review of another case is not touched');
	}//end testAColleagueOffTheCaseNoLongerReadsAReview()

	/**
	 * The assignee still reads every review of the case, and still changes it.
	 *
	 * @return void
	 */
	public function testTheAssigneeStillReadsAndUpdatesEveryReview(): void {
		$this->shipped->reconcile(caseTypeSlug: 'woo-verzoek', caseId: self::CASE_ID);

		foreach (['review-1', 'review-2', 'review-3'] as $uuid) {
			self::assertTrue($this->shipped->admits(schema: 'documentReview', uuid: $uuid, user: 'j.devries', action: 'read'), $uuid);
			self::assertTrue($this->shipped->admits(schema: 'documentReview', uuid: $uuid, user: 'j.devries', action: 'update'), $uuid);
		}
	}//end testTheAssigneeStillReadsAndUpdatesEveryReview()

	/**
	 * A batch reviewer reads and updates the reviews of their own batch, and no other.
	 *
	 * @return void
	 */
	public function testABatchReviewerReadsAndUpdatesOnlyTheReviewsOfTheirBatch(): void {
		$this->shipped->reconcile(caseTypeSlug: 'woo-verzoek', caseId: self::CASE_ID);

		self::assertTrue($this->shipped->admits(schema: 'documentReview', uuid: 'review-1', user: 'p.bakker', action: 'read'));
		self::assertTrue($this->shipped->admits(schema: 'documentReview', uuid: 'review-1', user: 'p.bakker', action: 'update'));
		self::assertFalse($this->shipped->admits(schema: 'documentReview', uuid: 'review-2', user: 'p.bakker', action: 'read'), 'the other batch');
		self::assertFalse($this->shipped->admits(schema: 'documentReview', uuid: 'review-3', user: 'p.bakker', action: 'read'), 'a review in no batch');
		self::assertSame(['j.devries' => 3, 'p.bakker' => 3], $this->shipped->grants['review-1']);
		self::assertSame(['j.devries' => 3], $this->shipped->grants['review-3']);
	}//end testABatchReviewerReadsAndUpdatesOnlyTheReviewsOfTheirBatch()

	/**
	 * The supervising group owns the reviews, and a grant never reaches past the staff group.
	 *
	 * @return void
	 */
	public function testSupervisorsReadAndNobodyOutsideTheStaffGroupDoes(): void {
		$this->shipped->reconcile(caseTypeSlug: 'woo-verzoek', caseId: self::CASE_ID);
		$this->shipped->grants['review-1']['outsider'] = 3;

		self::assertTrue($this->shipped->admits(schema: 'documentReview', uuid: 'review-2', user: 'coordinator', action: 'update'));
		self::assertFalse($this->shipped->admits(schema: 'documentReview', uuid: 'review-1', user: 'outsider', action: 'read'), 'the schema is the ceiling of a grant');
	}//end testSupervisorsReadAndNobodyOutsideTheStaffGroupDoes()

	/**
	 * The batch and the rule keep their table: the assignee updates both, a reviewer reads only their own batch.
	 *
	 * @return void
	 */
	public function testTheAssigneeUpdatesABatchAndAReviewerOnlyReadsTheirOwn(): void {
		$this->shipped->rows->seed('triageRule', 'rule-1', ['case' => self::CASE_ID, 'name' => 'Nieuwsbrieven', '@self' => ['owner' => 'former-handler']]);
		$this->shipped->reconcile(caseTypeSlug: 'woo-verzoek', caseId: self::CASE_ID);

		self::assertSame(['j.devries' => 3, 'p.bakker' => 1], $this->shipped->grants['batch-1']);
		self::assertFalse($this->shipped->admits(schema: 'reviewBatch', uuid: 'batch-1', user: 'p.bakker', action: 'update'));
		self::assertFalse($this->shipped->admits(schema: 'reviewBatch', uuid: 'batch-2', user: 'p.bakker', action: 'read'));
		self::assertTrue($this->shipped->admits(schema: 'triageRule', uuid: 'rule-1', user: 'j.devries', action: 'update'));
		self::assertFalse($this->shipped->admits(schema: 'triageRule', uuid: 'rule-1', user: 'p.bakker', action: 'read'));
	}//end testTheAssigneeUpdatesABatchAndAReviewerOnlyReadsTheirOwn()

	/**
	 * The three records of the collection: the plan, an exclusion and a stored query.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function collectionRecords(): array {
		return ['the search plan' => ['wooSearchPlan'], 'an exclusion' => ['wooExclusion'], 'a stored query' => ['wooCollectionQuery']];
	}//end collectionRecords()

	/**
	 * REQ-WRT-006: a record of the collection is read and changed by the assignee and the
	 * supervisors of its case, and by nobody else on the staff.
	 *
	 * @param string $schema The record's schema in the shipped register.
	 *
	 * @return void
	 *
	 * @dataProvider collectionRecords
	 */
	public function testARecordOfTheCollectionIsForTheAssigneeAndTheSupervisors(string $schema): void {
		$this->shipped->rows->seed($schema, 'record-1', ['case' => self::CASE_ID, '@self' => ['owner' => 'former-handler']]);
		$this->shipped->rows->seed($schema, 'record-other', ['case' => 'another-case', '@self' => ['owner' => 'former-handler']]);
		self::assertFalse($this->shipped->admits(schema: $schema, uuid: 'record-1', user: 'j.devries', action: 'read'), 'before the reconcile it waits');

		self::assertSame('reconciled', $this->shipped->reconcile(caseTypeSlug: 'woo-verzoek', caseId: self::CASE_ID)['status']);

		self::assertSame(['j.devries' => 3], $this->shipped->grants['record-1'], 'the assignee reads and updates; no reviewer is granted');
		self::assertArrayNotHasKey('record-other', $this->shipped->grants, 'a record of another case is not touched');
		foreach (['read', 'update'] as $action) {
			self::assertTrue($this->shipped->admits(schema: $schema, uuid: 'record-1', user: 'j.devries', action: $action), 'the assignee: ' . $action);
			self::assertTrue($this->shipped->admits(schema: $schema, uuid: 'record-1', user: 'coordinator', action: $action), 'a supervisor: ' . $action);
			self::assertTrue($this->shipped->admits(schema: $schema, uuid: 'record-1', user: 'administrator', action: $action), 'an administrator: ' . $action);
			self::assertFalse($this->shipped->admits(schema: $schema, uuid: 'record-1', user: 'colleague', action: $action), 'a colleague off the case: ' . $action);
			self::assertFalse($this->shipped->admits(schema: $schema, uuid: 'record-1', user: 'p.bakker', action: $action), 'a batch reviewer: ' . $action);
		}

		$this->shipped->grants['record-1']['outsider'] = 3;
		self::assertFalse($this->shipped->admits(schema: $schema, uuid: 'record-1', user: 'outsider', action: 'read'), 'the schema is the ceiling of a grant');
	}//end testARecordOfTheCollectionIsForTheAssigneeAndTheSupervisors()

	/**
	 * The configuration a next request starts from is not private (REQ-WRC-005).
	 *
	 * @return void
	 */
	public function testTheConfigurationANextRequestStartsFromIsNotListed(): void {
		$listed = array_column((array)$this->shipped->caseType(slug: 'woo-verzoek')['reviewerAccess']['records'], 'schemaKey');

		self::assertNotContains('woo_request_configuration_schema', $listed);
		self::assertContains('woo_search_plan_schema', $listed);
	}//end testTheConfigurationANextRequestStartsFromIsNotListed()
}//end class
