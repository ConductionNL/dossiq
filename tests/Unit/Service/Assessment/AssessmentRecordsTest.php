<?php

/**
 * Unit tests for AssessmentRecords: finding, reading partly public and changing stored assessments.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Assessment
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Assessment;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Access\CaseReviewerAccess;
use OCA\Dossiq\Service\Assessment\AssessmentRecords;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\LifecycleRegister;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The stored assessments of a case, read and changed through AssessmentRecords.
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class AssessmentRecordsTest extends TestCase {

	private const SCHEMA = 'wooDocumentAssessment';

	private const CONFIG = ['register' => 'dossiq', 'woo_assessment_schema' => self::SCHEMA];

	/**
	 * The register the assessments are stored in.
	 *
	 * @var LifecycleRegister
	 */
	private LifecycleRegister $register;

	/**
	 * A fresh register per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->register = new LifecycleRegister();
	}//end setUp()

	/**
	 * Records over the register with the given configuration.
	 *
	 * @param array<string, string>   $config The configuration values.
	 * @param CaseReviewerAccess|null $access The reviewer rule, or none.
	 * @param bool                    $withOr Whether OpenRegister is available.
	 *
	 * @return AssessmentRecords The records.
	 */
	private function records(array $config = self::CONFIG, ?CaseReviewerAccess $access = null, bool $withOr = true): AssessmentRecords {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$objectService = null;
		if ($withOr === true) {
			$objectService = $this->register;
		}

		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ($config[$key] ?? $default)
		);
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('reviewer-1');
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new AssessmentRecords(settingsService: $settings, userSession: $session, reviewerAccess: $access);
	}//end records()

	/**
	 * Find answers the case's assessment of the document, and null without one or without configuration.
	 *
	 * @return void
	 */
	public function testFindAnswersThePairsAssessment(): void {
		$this->register->seed(schema: self::SCHEMA, uuid: 'a-1', row: ['caseRef' => 'case-1', 'documentRef' => 'doc-1']);
		$this->register->seed(schema: self::SCHEMA, uuid: 'a-2', row: ['caseRef' => 'case-2', 'documentRef' => 'doc-1']);

		$this->assertSame(expected: 'a-1', actual: $this->records()->find(caseId: 'case-1', documentRef: 'doc-1')['id']);
		$this->assertNull(actual: $this->records()->find(caseId: 'case-1', documentRef: 'doc-9'));
		$this->assertNull(actual: $this->records(config: ['register' => 'dossiq'])->find(caseId: 'case-1', documentRef: 'doc-1'));
		$this->assertNull(actual: $this->records(withOr: false)->find(caseId: 'case-1', documentRef: 'doc-1'));
	}//end testFindAnswersThePairsAssessment()

	/**
	 * The partly public read answers the caller's rows; the copy tally reads past the caller.
	 *
	 * @return void
	 */
	public function testPartlyPublicIsScopedAndTheTallyIsNot(): void {
		$this->register->seed(
			schema: self::SCHEMA,
			uuid: 'a-1',
			row: ['caseRef' => 'case-1', 'documentRef' => 'doc-1', 'classification' => 'deels_openbaar']
		);
		$this->register->seed(
			schema: self::SCHEMA,
			uuid: 'a-2',
			row: ['caseRef' => 'case-1', 'documentRef' => 'doc-2', 'classification' => 'deels_openbaar', 'redactedDocumentRef' => 'doc-2r']
		);
		$this->register->seed(schema: self::SCHEMA, uuid: 'a-3', row: ['caseRef' => 'case-1', 'documentRef' => 'doc-3', 'classification' => 'openbaar']);

		$this->assertSame(expected: ['a-1', 'a-2'], actual: array_column($this->records()->partlyPublic(caseId: 'case-1'), 'id'));
		$this->assertSame(expected: [], actual: $this->records(config: ['register' => 'dossiq'])->partlyPublic(caseId: 'case-1'));

		$this->register->hiddenFromCaller = [self::SCHEMA];
		$this->assertSame(expected: [], actual: $this->records()->partlyPublic(caseId: 'case-1'));
		$this->assertSame(expected: ['partlyPublic' => 2, 'waiting' => ['doc-1']], actual: $this->records()->partlyPublicWithoutCopy(caseId: 'case-1'));
		$this->assertSame(
			expected: ['partlyPublic' => 0, 'waiting' => []],
			actual: $this->records(config: ['register' => 'dossiq'])->partlyPublicWithoutCopy(caseId: 'case-1')
		);
	}//end testPartlyPublicIsScopedAndTheTallyIsNot()

	/**
	 * The copy tally refuses without OpenRegister.
	 *
	 * @return void
	 */
	public function testTheTallyNeedsOpenRegister(): void {
		$this->expectException(exception: RuntimeException::class);
		$this->records(withOr: false)->partlyPublicWithoutCopy(caseId: 'case-1');
	}//end testTheTallyNeedsOpenRegister()

	/**
	 * An update patches the stored assessment, after the reviewer rule let the caller through.
	 *
	 * @return void
	 */
	public function testAnUpdatePatchesTheStoredAssessmentUnderTheReviewerRule(): void {
		$this->register->seed(
			schema: self::SCHEMA,
			uuid: 'a-1',
			row: ['caseRef' => 'case-1', 'documentRef' => 'doc-1', 'classification' => 'deels_openbaar']
		);
		$access = $this->createMock(originalClassName: CaseReviewerAccess::class);
		$access->expects($this->once())->method('assertMayChange')
			->with('case-1', $this->callback(callback: static fn (array $record): bool => $record['id'] === 'a-1'), 'reviewer-1');

		$saved = $this->records(access: $access)->update(caseId: 'case-1', documentRef: 'doc-1', fields: ['redactedDocumentRef' => 'doc-1r']);

		$this->assertSame(expected: 'doc-1r', actual: $saved['redactedDocumentRef']);
		$this->assertSame(expected: 'doc-1r', actual: $this->register->row(self::SCHEMA, 'a-1')['redactedDocumentRef']);
		$this->assertSame(expected: 1, actual: $this->register->patches);
	}//end testAnUpdatePatchesTheStoredAssessmentUnderTheReviewerRule()

	/**
	 * A refused reviewer changes nothing.
	 *
	 * @return void
	 */
	public function testARefusedReviewerChangesNothing(): void {
		$this->register->seed(schema: self::SCHEMA, uuid: 'a-1', row: ['caseRef' => 'case-1', 'documentRef' => 'doc-1']);
		$access = $this->createMock(originalClassName: CaseReviewerAccess::class);
		$refusal = new RefusedException(rule: 'owned-by-another-reviewer', sentence: 'Not yours.', status: RefusedException::STATUS_FORBIDDEN);
		$access->method('assertMayChange')->willThrowException($refusal);

		try {
			$this->records(access: $access)->update(caseId: 'case-1', documentRef: 'doc-1', fields: ['redaction' => ['x' => 1]]);
			$this->fail(message: 'The refusal should have been thrown.');
		} catch (RefusedException $e) {
			$this->assertSame(expected: 403, actual: $e->getStatus());
		}

		$this->assertSame(expected: 0, actual: $this->register->patches);
	}//end testARefusedReviewerChangesNothing()

	/**
	 * An update needs an assessed document and OpenRegister.
	 *
	 * @return void
	 */
	public function testAnUpdateNeedsAnAssessedDocument(): void {
		try {
			$this->records()->update(caseId: 'case-1', documentRef: 'doc-1', fields: []);
			$this->fail(message: 'An unassessed document should be refused.');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString(needle: 'must be assessed', haystack: $e->getMessage());
		}

		$this->expectExceptionMessage(message: 'OpenRegister is not available');
		$this->records(withOr: false)->update(caseId: 'case-1', documentRef: 'doc-1', fields: []);
	}//end testAnUpdateNeedsAnAssessedDocument()
}//end class
