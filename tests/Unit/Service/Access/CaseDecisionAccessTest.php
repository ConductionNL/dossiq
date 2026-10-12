<?php

/**
 * Tests for CaseDecisionAccess.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Access
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-a-decision-on-a-cases-file-is-changed-by-its-decider-or-a-supervisor-of-that-case-req-cra-006
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Access;

use OCA\Dossiq\Service\Access\CaseDecisionAccess;
use OCA\Dossiq\Service\Access\CaseRecordLookup;
use OCA\Dossiq\Service\Access\CaseReviewerAccess;
use OCA\Dossiq\Service\Access\ObjectReadGrants;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;

/**
 * REQ-CRA-006.
 *
 * @covers \OCA\Dossiq\Service\Access\CaseDecisionAccess
 * @uses   \OCA\Dossiq\Service\Access\CaseRecordLookup
 * @uses   \OCA\Dossiq\Service\Access\CaseRecordReaders
 * @uses   \OCA\Dossiq\Service\Access\CaseReviewerAccess
 * @uses   \OCA\Dossiq\Service\CaseTypeStore
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 */
class CaseDecisionAccessTest extends TestCase {

	private const GUARDED_CASE = '44444444-4444-4444-8444-444444444444';

	private const OPEN_CASE = '66666666-6666-4666-8666-666666666666';

	private const GUARDED_TYPE = '55555555-5555-4555-8555-555555555555';

	private const OPEN_TYPE = '77777777-7777-4777-8777-777777777777';

	private const DOCUMENT = 'd0c00000-0000-4000-8000-000000000001';

	private InMemoryRegister $register;

	private CaseDecisionAccess $access;

	protected function setUp(): void {
		$this->register = new InMemoryRegister();
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->register);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => [
				'register' => 'dossiq',
				'case_schema' => 'case',
				'case_document_schema' => 'caseDocument',
				'assessment_schema_key' => 'assessment',
			][$key] ?? $default
		);

		$resolver = $this->createMock(CaseTypeResolver::class);
		$resolver->method('effectiveCaseType')->willReturnCallback(
			static fn (string $id): array => $id === self::GUARDED_TYPE
				? ['reviewerAccess' => ['records' => [['schemaKey' => 'assessment_schema_key', 'caseField' => 'caseRef']], 'supervisorGroup' => 'woo-coordinators']]
				: []
		);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => $uid === 'admin');
		$groups->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $group): bool => ($uid === 'coordinator' && $group === 'woo-coordinators')
				|| ($uid === 'other-coordinator' && $group === 'dossiq-coordinators')
		);

		$store = new CaseTypeStore($settings);
		$this->access = new CaseDecisionAccess(
			settingsService: $settings,
			lookup: new CaseRecordLookup(settingsService: $settings, caseTypes: $store, resolver: $resolver),
			caseTypes: $store,
			access: new CaseReviewerAccess(
				settingsService: $settings,
				caseTypes: $store,
				resolver: $resolver,
				grants: $this->createMock(ObjectReadGrants::class),
				groupManager: $groups,
			),
		);

		$this->register->seed('case', self::GUARDED_CASE, ['caseType' => self::GUARDED_TYPE]);
		$this->register->seed('case', self::OPEN_CASE, ['caseType' => self::OPEN_TYPE]);
	}//end setUp()

	public function testAReviewerOnAGuardedCaseDocumentIsRefused(): void {
		$this->link(caseId: self::GUARDED_CASE);

		self::assertFalse($this->access->mayChange(objectUuid: self::DOCUMENT, userId: 'p.bakker'));
	}//end testAReviewerOnAGuardedCaseDocumentIsRefused()

	public function testASupervisorOfTheCaseTypeMay(): void {
		$this->link(caseId: self::GUARDED_CASE);

		self::assertTrue($this->access->mayChange(objectUuid: self::DOCUMENT, userId: 'coordinator'));
		self::assertTrue($this->access->mayChange(objectUuid: self::DOCUMENT, userId: 'admin'));
	}//end testASupervisorOfTheCaseTypeMay()

	public function testTheCaseTypesGroupDecidesNotTheSchemaDefault(): void {
		$this->link(caseId: self::GUARDED_CASE);

		self::assertFalse(
			$this->access->mayChange(objectUuid: self::DOCUMENT, userId: 'other-coordinator'),
			'a member of the schema\'s default group supervises no case whose case type names another'
		);
	}//end testTheCaseTypesGroupDecidesNotTheSchemaDefault()

	public function testADecisionOnTheCaseItselfIsGuardedToo(): void {
		self::assertFalse($this->access->mayChange(objectUuid: self::GUARDED_CASE, userId: 'p.bakker'));
		self::assertTrue($this->access->mayChange(objectUuid: self::GUARDED_CASE, userId: 'coordinator'));
	}//end testADecisionOnTheCaseItselfIsGuardedToo()

	public function testADocumentOnNoGuardedCaseHasNoOpinion(): void {
		self::assertNull($this->access->mayChange(objectUuid: self::DOCUMENT, userId: 'p.bakker'), 'on no case');

		$this->link(caseId: self::OPEN_CASE);
		self::assertNull($this->access->mayChange(objectUuid: self::DOCUMENT, userId: 'p.bakker'), 'on an open case');
		self::assertNull($this->access->mayChange(objectUuid: self::OPEN_CASE, userId: 'p.bakker'), 'the open case itself');
		self::assertNull($this->access->mayChange(objectUuid: '', userId: 'p.bakker'), 'no object');
	}//end testADocumentOnNoGuardedCaseHasNoOpinion()

	public function testOneGuardedCaseRefusesEvenWhenAnotherIsOpen(): void {
		$this->link(caseId: self::OPEN_CASE);
		$this->link(caseId: self::GUARDED_CASE);

		self::assertFalse($this->access->mayChange(objectUuid: self::DOCUMENT, userId: 'p.bakker'));
	}//end testOneGuardedCaseRefusesEvenWhenAnotherIsOpen()

	/**
	 * A case-type supervisor sees a hidden decision on their case's document; nobody else is shown it.
	 *
	 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-a-supervisor-of-the-case-sees-a-hidden-decision-on-its-file-req-cra-007
	 */
	public function testACaseTypeSupervisorSeesAHiddenDecision(): void {
		self::assertFalse($this->access->maySee(objectUuid: self::DOCUMENT, userId: 'coordinator'), 'on no case');

		$this->link(caseId: self::GUARDED_CASE);

		self::assertTrue($this->access->maySee(objectUuid: self::DOCUMENT, userId: 'coordinator'));
		self::assertTrue($this->access->maySee(objectUuid: self::GUARDED_CASE, userId: 'coordinator'));
		self::assertFalse($this->access->maySee(objectUuid: self::DOCUMENT, userId: 'p.bakker'));
		self::assertFalse($this->access->maySee(objectUuid: self::DOCUMENT, userId: 'other-coordinator'));
		self::assertFalse($this->access->maySee(objectUuid: self::OPEN_CASE, userId: 'coordinator'), 'an open case shows nothing extra');
	}//end testACaseTypeSupervisorSeesAHiddenDecision()

	private function link(string $caseId): void {
		$this->register->seed('caseDocument', 'link-' . $caseId, ['case' => $caseId, 'document' => self::DOCUMENT]);
	}//end link()
}//end class
