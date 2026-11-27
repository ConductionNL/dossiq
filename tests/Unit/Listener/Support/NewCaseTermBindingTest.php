<?php

/**
 * NewCaseTermBinding Unit Tests
 *
 * A received case gets its statutory term from receipt under the resolved
 * definition and then its other clocks; a case type without a definition is
 * warned about and still gets its other clocks; any other failure is logged
 * as an error and binds nothing further.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener\Support;

use DateTimeImmutable;
use OCA\Dossiq\Exception\NoTermijnDefinitieException;
use OCA\Dossiq\Kernel\Contracts\CaseTermsServiceInterface;
use OCA\Dossiq\Kernel\Contracts\IntakeTermStartInterface;
use OCA\Dossiq\Kernel\Contracts\TermijnServiceInterface;
use OCA\Dossiq\Listener\NewCaseIdentity;
use OCA\Dossiq\Listener\Support\NewCaseTermBinding;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for NewCaseTermBinding.
 *
 * @covers \OCA\Dossiq\Listener\Support\NewCaseTermBinding
 * @uses   \OCA\Dossiq\Listener\CreatedCase
 * @uses   \OCA\Dossiq\Listener\NewCaseIdentity
 */
final class NewCaseTermBindingTest extends TestCase {

	/**
	 * The case every test binds.
	 *
	 * @return NewCaseIdentity
	 */
	private function identity(): NewCaseIdentity {
		return new NewCaseIdentity(caseId: 'case-1', caseTypeRef: 'ct-uuid', caseType: 'omgevingsvergunning');
	}//end identity()

	/**
	 * An intake that starts every term on the given day.
	 *
	 * @param DateTimeImmutable $day The receipt day.
	 *
	 * @return IntakeTermStartInterface
	 */
	private function intake(DateTimeImmutable $day): IntakeTermStartInterface {
		$intake = $this->createMock(originalClassName: IntakeTermStartInterface::class);
		$intake->method('termStartFor')->willReturn($day);
		return $intake;
	}//end intake()

	/**
	 * The statutory term starts at receipt under the resolution, then the other clocks bind with the planned start.
	 *
	 * @return void
	 */
	public function testTheTermStartsAtReceiptAndTheOtherClocksFollow(): void {
		$day = new DateTimeImmutable('2026-11-02');
		$terms = $this->createMock(originalClassName: TermijnServiceInterface::class);
		$terms->expects($this->once())->method('createTermijnInstance')
			->with('case-1', 'omgevingsvergunning', $day, ['definition' => ['id' => 'def-1']])
			->willReturn(['id' => 'inst-1']);
		$caseTerms = $this->createMock(originalClassName: CaseTermsServiceInterface::class);
		$caseTerms->expects($this->once())->method('bindForCase')
			->with('case-1', 'ct-uuid', $day, '2026-12-01', ['id' => 'planned-1'])
			->willReturn([]);

		$binding = new NewCaseTermBinding(
			termService: $terms,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			caseTerms: $caseTerms,
			intake: $this->intake(day: $day),
		);
		$binding->bind(
			identity: $this->identity(),
			payload: ['plannedStartDate' => ' 2026-12-01 '],
			resolved: ['statutory' => ['definition' => ['id' => 'def-1']], 'planned' => ['id' => 'planned-1']]
		);
	}//end testTheTermStartsAtReceiptAndTheOtherClocksFollow()

	/**
	 * A case type without a definition is warned about, and its other clocks still bind.
	 *
	 * @return void
	 */
	public function testNoDefinitionWarnsAndStillBindsTheOtherClocks(): void {
		$terms = $this->createMock(originalClassName: TermijnServiceInterface::class);
		$terms->method('createTermijnInstance')->willThrowException(new NoTermijnDefinitieException(message: 'none'));
		$caseTerms = $this->createMock(originalClassName: CaseTermsServiceInterface::class);
		$caseTerms->expects($this->once())->method('bindForCase')->willReturn([]);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with($this->stringContains(string: 'no active TermijnDefinitie for case type "omgevingsvergunning"'));
		$logger->expects($this->never())->method('error');

		$binding = new NewCaseTermBinding(termService: $terms, logger: $logger, caseTerms: $caseTerms);
		$binding->bind(identity: $this->identity(), payload: [], resolved: ['statutory' => null, 'planned' => null]);
	}//end testNoDefinitionWarnsAndStillBindsTheOtherClocks()

	/**
	 * Any other failure is an error naming the case, and the other clocks are not bound.
	 *
	 * @return void
	 */
	public function testAnyOtherFailureIsAnErrorAndBindsNothingFurther(): void {
		$terms = $this->createMock(originalClassName: TermijnServiceInterface::class);
		$terms->method('createTermijnInstance')->willThrowException(new RuntimeException('store down'));
		$caseTerms = $this->createMock(originalClassName: CaseTermsServiceInterface::class);
		$caseTerms->expects($this->never())->method('bindForCase');
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('error')
			->with($this->stringContains(string: 'could not bind a term to case case-1: store down'));

		$binding = new NewCaseTermBinding(termService: $terms, logger: $logger, caseTerms: $caseTerms);
		$binding->bind(identity: $this->identity(), payload: [], resolved: ['statutory' => null, 'planned' => null]);
	}//end testAnyOtherFailureIsAnErrorAndBindsNothingFurther()

	/**
	 * A failing bind of the other clocks is a warning, after the statutory term was made.
	 *
	 * @return void
	 */
	public function testAFailingOtherClockIsAWarning(): void {
		$terms = $this->createMock(originalClassName: TermijnServiceInterface::class);
		$terms->expects($this->once())->method('createTermijnInstance')->willReturn(['id' => 'inst-1']);
		$caseTerms = $this->createMock(originalClassName: CaseTermsServiceInterface::class);
		$caseTerms->method('bindForCase')->willThrowException(new RuntimeException('calendar down'));
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with($this->stringContains(string: 'the planned end and the internal target could not be bound to case case-1'));

		$binding = new NewCaseTermBinding(termService: $terms, logger: $logger, caseTerms: $caseTerms);
		$binding->bind(identity: $this->identity(), payload: [], resolved: ['statutory' => null, 'planned' => null]);
	}//end testAFailingOtherClockIsAWarning()
}//end class
