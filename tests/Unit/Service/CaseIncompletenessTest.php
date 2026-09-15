<?php

/**
 * A required field left empty knowingly: recorded, named, and never hidden.
 *
 * A phone intake cannot always be complete, and refusing it loses the case.
 * What the case must not do is report itself complete, and what an act that
 * needs the missing data must not do is proceed.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Lifecycle\CaseIncompleteness;
use OCA\Dossiq\Service\Lifecycle\CaseJournal;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Recording incompleteness, and refusing what needs the data.
 *
 * @covers \OCA\Dossiq\Service\Lifecycle\CaseIncompleteness
 */
class CaseIncompletenessTest extends TestCase {

	/**
	 * The case as the store currently holds it.
	 *
	 * @var array<string, mixed>
	 */
	private array $case;

	/**
	 * The store.
	 *
	 * @var CaseStatusStore&MockObject
	 */
	private CaseStatusStore $store;

	/**
	 * The service under test.
	 *
	 * @var CaseIncompleteness
	 */
	private CaseIncompleteness $incompleteness;

	/**
	 * A case created over the phone with one required field empty.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->case = ['id' => 'case-1', 'caseType' => 'ct-1'];

		$this->store = $this->createMock(CaseStatusStore::class);
		$this->store->method('loadCase')->willReturnCallback(fn (): array => $this->case);
		$this->store->method('saveCase')->willReturnCallback(
			function (array $case): array {
				$this->case = $case;
				return $case;
			}
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('ahmed');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$this->incompleteness = new CaseIncompleteness(
			store: $this->store,
			journal: new CaseJournal(userSession: $session),
		);
	}//end setUp()

	/**
	 * The case is created, and it reads incomplete naming the field.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function testAPhoneIntakeIsKeptAndNamesWhatIsMissing(): void {
		$answer = $this->incompleteness->record(caseId: 'case-1', missing: ['applicantAddress']);

		$this->assertTrue($answer['incomplete']);
		$this->assertSame(['applicantAddress'], $answer['missingFields']);
		$this->assertTrue($this->case['isIncomplete']);
		$this->assertSame(['applicantAddress'], $this->incompleteness->missingOn(case: $this->case));
	}//end testAPhoneIntakeIsKeptAndNamesWhatIsMissing()

	/**
	 * A case with nothing missing does not report itself incomplete.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function testACompleteCaseReadsComplete(): void {
		$answer = $this->incompleteness->record(caseId: 'case-1', missing: []);

		$this->assertFalse($answer['incomplete']);
		$this->assertFalse($this->case['isIncomplete']);
	}//end testACompleteCaseReadsComplete()

	/**
	 * Blank and duplicated field names are dropped rather than stored.
	 *
	 * A refusal naming the same field twice, or naming an empty string, is a
	 * refusal a handler cannot act on.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function testTheNamedFieldsAreCleanedUp(): void {
		$answer = $this->incompleteness->record(
			caseId: 'case-1',
			missing: ['applicantAddress', '  ', 'applicantAddress', ' bsn '],
		);

		$this->assertSame(['applicantAddress', 'bsn'], $answer['missingFields']);
	}//end testTheNamedFieldsAreCleanedUp()

	/**
	 * An act that needs a missing field is refused, and the refusal names it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function testAnActThatNeedsTheFieldIsRefusedByName(): void {
		$this->incompleteness->record(caseId: 'case-1', missing: ['applicantAddress']);

		try {
			$this->incompleteness->requireFields(case: $this->case, needs: ['applicantAddress']);
			$this->fail('sending a besluit to an address nobody has must be refused');
		} catch (RefusedException $e) {
			$this->assertSame('incomplete-case', $e->getRule());
			$this->assertStringContainsString('applicantAddress', $e->getSentence());
		}
	}//end testAnActThatNeedsTheFieldIsRefusedByName()

	/**
	 * An act that needs a different field is not refused.
	 *
	 * The control: incompleteness blocks the acts that need the data and NOT
	 * everything, or the feature is just a broken case.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function testAnActThatNeedsSomethingElseStillRuns(): void {
		$this->incompleteness->record(caseId: 'case-1', missing: ['applicantAddress']);

		$this->incompleteness->requireFields(case: $this->case, needs: ['bsn']);
		$this->addToAssertionCount(1);
	}//end testAnActThatNeedsSomethingElseStillRuns()

	/**
	 * A case whose missing-field list will not parse reads as complete.
	 *
	 * Reading unparseable as "everything is missing" would block every act on
	 * a case whose record was written by something else, which is a worse
	 * answer than treating the record as absent.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function testAnUnreadableRecordIsNotAnAccusation(): void {
		$this->assertSame([], $this->incompleteness->missingOn(case: ['missingFields' => 'not json']));
	}//end testAnUnreadableRecordIsNotAnAccusation()
}//end class
