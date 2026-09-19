<?php

/**
 * A case homed in another application, and the acts dossiq stops offering on it.
 *
 * 🔑 THE MUTATION THIS FILE IS BUILT AGAINST. Before the change, a case homed
 * elsewhere still offered every lifecycle act, and a handler who took one moved
 * a status here that the specialist application would never hear about. Two
 * systems then disagree about the same case and neither knows it. So the test
 * that matters is not "the flag is set": it is that `execute()` REFUSES, so the
 * block cannot be a suggestion a client ignores.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Lifecycle\CaseActionProvider;
use OCA\Dossiq\Service\Access\OpenRegisterGrantsGateway;
use OCA\Dossiq\Service\Cases\ExternalHome;
use OCA\Dossiq\Service\StatusTransitionService;
use OCA\Dossiq\Service\Money\CasePaymentReader;
use OCA\Dossiq\Service\Money\CasePaymentState;
use OCA\Dossiq\Service\Money\UnpaidCaseGate;
use OCA\Dossiq\Service\Transitions\CaseTypeReader;
use OCA\Dossiq\Service\Transitions\CaseResultWriter;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The declaration, and what it does to the lifecycle menu.
 *
 * @covers \OCA\Dossiq\Service\Cases\ExternalHome
 * @uses \OCA\Dossiq\Lifecycle\CaseActionProvider
 * @uses \OCA\Dossiq\Service\Money\UnpaidCaseGate
 * @uses   \OCA\Dossiq\Service\Money\CasePaymentState
 *
 * @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md
 */
class ExternallyHomedCaseTest extends TestCase {

	/**
	 * A case whose work happens in a specialist application.
	 *
	 * @var array<string, mixed>
	 */
	private const HOMED_ELSEWHERE = [
		'id' => 'case-1',
		'title' => 'Bijstandsaanvraag',
		'status' => 'st-in-behandeling',
		'externalApplication' => 'Suite4Sociaal Domein',
		'externalIdentifier' => 'SD-2026-4417',
		'externalUrl' => 'https://suite.example.org/zaken/SD-2026-4417',
	];

	/**
	 * A generic case dossiq handles itself.
	 *
	 * @var array<string, mixed>
	 */
	private const HANDLED_HERE = [
		'id' => 'case-2',
		'title' => 'Dakkapel',
		'status' => 'st-in-behandeling',
	];

	/**
	 * The declaration names the application, the identifier and the link.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md#requirement-a-case-may-be-homed-in-another-application-req-hand-04
	 */
	public function testTheCasePointsAtItsHome(): void {
		$home = new ExternalHome();

		self::assertTrue($home->isHomedElsewhere(case: self::HOMED_ELSEWHERE));
		self::assertSame(
			[
				'application' => 'Suite4Sociaal Domein',
				'identifier' => 'SD-2026-4417',
				'url' => 'https://suite.example.org/zaken/SD-2026-4417',
			],
			$home->declarationOn(case: self::HOMED_ELSEWHERE),
		);
		self::assertStringContainsString('SD-2026-4417', $home->whereTheWorkIs(case: self::HOMED_ELSEWHERE));
	}//end testTheCasePointsAtItsHome()

	/**
	 * A generic case is not externally homed, and neither is a stray reference.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md#requirement-a-case-may-be-homed-in-another-application-req-hand-04
	 */
	public function testAStrayReferenceDoesNotStrandACase(): void {
		$home = new ExternalHome();

		self::assertFalse($home->isHomedElsewhere(case: self::HANDLED_HERE));
		self::assertFalse(
			$home->isHomedElsewhere(case: ['id' => 'case-3', 'externalIdentifier' => 'SD-1']),
			'An identifier with no application names a system nobody can ask about; disabling the lifecycle for it '
				. 'would strand the work here with no way to get it back.',
		);
		self::assertSame('', $home->whereTheWorkIs(case: self::HANDLED_HERE));
	}//end testAStrayReferenceDoesNotStrandACase()

	/**
	 * The acts stay in the list and come back disabled, saying where the work is.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md#requirement-a-case-may-be-homed-in-another-application-req-hand-04
	 */
	public function testTheLifecycleActsAreDisabledAndSayWhy(): void {
		$actions = $this->provider()->availableActions(object: self::HOMED_ELSEWHERE, userId: 'jan');

		self::assertCount(1, $actions, 'An act that vanished would read as a permission problem.');
		self::assertTrue($actions[0]['blocked']);
		self::assertStringContainsString('Suite4Sociaal Domein', $actions[0]['description']);
	}//end testTheLifecycleActsAreDisabledAndSayWhy()

	/**
	 * A case dossiq handles keeps every act it had.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md#requirement-a-case-may-be-homed-in-another-application-req-hand-04
	 */
	public function testAGenericCaseKeepsItsActs(): void {
		$actions = $this->provider()->availableActions(object: self::HANDLED_HERE, userId: 'jan');

		self::assertCount(1, $actions);
		self::assertFalse($actions[0]['blocked'], 'Nothing about a generic case changed.');
	}//end testAGenericCaseKeepsItsActs()

	/**
	 * Taking a disabled act anyway is refused, not performed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md#requirement-a-case-may-be-homed-in-another-application-req-hand-04
	 */
	public function testTakingADisabledActAnywayIsRefused(): void {
		$engine = $this->createMock(originalClassName: StatusTransitionService::class);
		$engine->expects(self::never())->method('execute');

		$provider = new CaseActionProvider(
			transitionEngine: $engine,
			resultWriter: $this->createMock(originalClassName: CaseResultWriter::class),
			grants: $this->createMock(originalClassName: OpenRegisterGrantsGateway::class),
			externalHome: new ExternalHome(),
			unpaidCases: new UnpaidCaseGate(new CasePaymentState()),
			payments: $this->createMock(CasePaymentReader::class),
			caseTypes: $this->createMock(CaseTypeReader::class),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/Suite4Sociaal Domein/');

		$provider->execute(object: self::HOMED_ELSEWHERE, userId: 'jan', action: 'tr-afronden', data: []);
	}//end testTakingADisabledActAnywayIsRefused()

	/**
	 * A provider over an engine offering one move.
	 *
	 * @return CaseActionProvider The provider under test.
	 */
	private function provider(): CaseActionProvider {
		$engine = $this->createMock(originalClassName: StatusTransitionService::class);
		$engine->method('getAvailableTransitions')->willReturn(
			[
				'current' => ['statusId' => 'st-in-behandeling', 'name' => 'In behandeling'],
				'transitions' => [
					[
						'id' => 'tr-afronden',
						'toStatus' => 'st-afgerond',
						'label' => 'Afronden',
						'guardsPassed' => true,
					],
				],
			]
		);

		$resultWriter = $this->createMock(originalClassName: CaseResultWriter::class);
		$resultWriter->method('isFinalStatus')->willReturn(false);

		return new CaseActionProvider(
			transitionEngine: $engine,
			resultWriter: $resultWriter,
			grants: $this->createMock(originalClassName: OpenRegisterGrantsGateway::class),
			externalHome: new ExternalHome(),
			unpaidCases: new UnpaidCaseGate(new CasePaymentState()),
			payments: $this->createMock(CasePaymentReader::class),
			caseTypes: $this->createMock(CaseTypeReader::class),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end provider()
}//end class
