<?php

/**
 * A concluded decision is traced to its record and case, and a deviation from the advice is recorded.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Service\DecisionDocument
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 *
 * @spec openspec/changes/dossiq-delegation-via-events/specs/contract-decision-delegation/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\DecisionDocument;

use OCA\Dossiq\Service\Bezwaar\AdvisoryCommitteeService;
use OCA\Dossiq\Service\Bezwaar\BezwaarEntryNotWrittenException;
use OCA\Dossiq\Service\DecisionDocument\ConcludedDecisionRecord;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\DecisionDocument\ConcludedDecisionRecord
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses   \OCA\Dossiq\Service\Bezwaar\BezwaarEntryNotWrittenException
 */
class ConcludedDecisionRecordTest extends TestCase {

	/**
	 * The advice-request audit writer.
	 *
	 * @var AdvisoryCommitteeService&MockObject
	 */
	private AdvisoryCommitteeService&MockObject $bac;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The class under test.
	 *
	 * @var ConcludedDecisionRecord
	 */
	private ConcludedDecisionRecord $records;

	/**
	 * Doubles for the audit writer and the logger.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->bac = $this->createMock(AdvisoryCommitteeService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->records = new ConcludedDecisionRecord(bacService: $this->bac, logger: $this->logger);
	}//end setUp()

	/**
	 * The record that carries the decision reference names the case and the existing decision.
	 *
	 * @return void
	 */
	public function testTheRecordNamesItsCaseAndDecision(): void {
		$register = new InMemoryRegister();
		$register->seed(schema: 'decision', uuid: 'rec-1', row: ['decisionRef' => 'dec-1', 'case' => 'case-9', 'besluitRef' => 'bes-2']);

		[$caseId, $besluitId, $record] = $this->records->resolveCaseAndDecision(
			objectService: $register,
			register: 'dossiq',
			schema: 'decision',
			decisionId: 'dec-1',
			subjectId: 'subject-1',
			externalRef: 'ext-1'
		);

		self::assertSame('case-9', $caseId);
		self::assertSame('bes-2', $besluitId);
		self::assertSame('dec-1', ($record['decisionRef'] ?? null));
	}//end testTheRecordNamesItsCaseAndDecision()

	/**
	 * Without a matching record the external reference, then the subject, is the case.
	 *
	 * @return void
	 */
	public function testWithoutARecordTheReferenceIsTheCase(): void {
		$register = new InMemoryRegister();

		self::assertSame(
			['ext-1', '', null],
			$this->records->resolveCaseAndDecision(objectService: $register, register: 'dossiq', schema: 'decision', decisionId: 'dec-1', subjectId: 'subject-1', externalRef: 'ext-1')
		);
		self::assertSame(
			['subject-1', '', null],
			$this->records->resolveCaseAndDecision(objectService: $register, register: '', schema: '', decisionId: 'dec-1', subjectId: 'subject-1', externalRef: '')
		);
	}//end testWithoutARecordTheReferenceIsTheCase()

	/**
	 * Only a decision that departs from the advice it was referred for is recorded.
	 *
	 * @return void
	 */
	public function testOnlyADeviationFromTheAdviceIsRecorded(): void {
		$this->bac->expects(self::once())->method('recordCouncilDeviation')->with('advice-1', 'rec-1', 'Different facts');

		$this->records->recordCouncilDeviation(decision: null, decisionId: 'bes-1', subjectId: 'subject-1');
		$this->records->recordCouncilDeviation(decision: ['followsAdvice' => false], decisionId: 'bes-1', subjectId: 'subject-1');
		$this->records->recordCouncilDeviation(decision: ['advisoryOpinion' => 'advice-1'], decisionId: 'bes-1', subjectId: 'subject-1');
		$this->records->recordCouncilDeviation(
			decision: ['id' => 'rec-1', 'advisoryOpinion' => 'advice-1', 'followsAdvice' => false, 'deviationRationale' => 'Different facts'],
			decisionId: '',
			subjectId: 'subject-1'
		);
	}//end testOnlyADeviationFromTheAdviceIsRecorded()

	/**
	 * An entry that cannot be written is logged with the entry, not thrown.
	 *
	 * @return void
	 */
	public function testAnEntryThatIsNotWrittenIsLogged(): void {
		$this->bac->method('recordCouncilDeviation')->willThrowException(
			new BezwaarEntryNotWrittenException(action: 'deviation', objectUuid: 'advice-1', entry: ['a' => 1], reason: 'refused')
		);
		$this->logger->expects(self::once())->method('error');

		$this->records->recordCouncilDeviation(
			decision: ['advisoryOpinion' => 'advice-1', 'followsAdvice' => false],
			decisionId: 'bes-1',
			subjectId: 'subject-1'
		);
	}//end testAnEntryThatIsNotWrittenIsLogged()
}//end class
