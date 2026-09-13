<?php

/**
 * The case lifecycle provider takes the move it offered, and says what failed.
 *
 * OpenRegister answers a provider's throw with one of three status codes, and
 * picks which by the TYPE it catches: an ordinary `RuntimeException` is 422
 * "that move was refused", `LifecycleProviderException` is 502 "the provider
 * could not answer", `LifecycleSubjectNotFoundException` is 404 "the object is
 * gone". dossiq's engine reports all three as a `RuntimeException` carrying a
 * snake_case sentinel, so the classification lives in CaseActionProvider and
 * every assertion below is about that classification.
 *
 * 🔴 WHY THE TYPE ASSERTIONS LOOK PEDANTIC. `LifecycleProviderException` and
 * `LifecycleSubjectNotFoundException` both EXTEND `RuntimeException`, so
 * `expectException(RuntimeException::class)` passes on all three and proves
 * nothing. Each refusal test therefore also asserts that the escaping
 * exception is NOT one of the two subclasses.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Lifecycle;

use OCA\Dossiq\Lifecycle\CaseActionProvider;
use OCA\Dossiq\Service\StatusTransitionService;
use OCA\Dossiq\Service\Transitions\CaseResultWriter;
use OCA\Dossiq\Service\Transitions\GuardFailedException;
use OCA\OpenRegister\Exception\LifecycleProviderException;
use OCA\OpenRegister\Exception\LifecycleSubjectNotFoundException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use TypeError;

/**
 * Maps the transition engine's write outcome onto OpenRegister's contract.
 *
 * @covers \OCA\Dossiq\Lifecycle\CaseActionProvider
 * @uses \OCA\Dossiq\Service\Transitions\GuardFailedException
 */
class CaseActionProviderExecuteTest extends TestCase {

	/**
	 * A live case, as OpenRegister hands it to the provider.
	 *
	 * @var array<string, mixed>
	 */
	private const CASE_PAYLOAD = [
		'id' => 'c7b1f0de-0a6c-4a1e-9a0e-3b1f0de0a6c4',
		'title' => 'Handhavingsverzoek Kerkstraat 12',
		'caseType' => 'ct-handhaving',
		'status' => 'st-intake',
		'@self' => ['id' => 'c7b1f0de-0a6c-4a1e-9a0e-3b1f0de0a6c4', 'version' => 4, 'deleted' => null],
	];

	/**
	 * The report `StatusTransitionService::execute()` answers with.
	 *
	 * @var array<string, mixed>
	 */
	private const ENGINE_REPORT = [
		'status' => 'ok',
		'statusRecord' => ['id' => 'sr-1', 'fromStatus' => 'st-intake', 'toStatus' => 'st-behandeling'],
		'dispatchedActions' => [['type' => 'notify', 'ok' => true]],
		'version' => 5,
	];

	/**
	 * Build the provider over an engine we dictate.
	 *
	 * @param StatusTransitionService $engine The engine double.
	 *
	 * @return CaseActionProvider
	 */
	private function providerOver(StatusTransitionService $engine): CaseActionProvider {
		return new CaseActionProvider(
			transitionEngine: $engine,
			resultWriter: $this->createMock(CaseResultWriter::class),
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end providerOver()

	/**
	 * An engine whose write throws the given failure.
	 *
	 * @param Throwable $failure What the engine throws.
	 *
	 * @return StatusTransitionService&MockObject
	 */
	private function engineThrowing(Throwable $failure): StatusTransitionService {
		$engine = $this->createMock(StatusTransitionService::class);
		$engine->method('execute')->willThrowException($failure);

		return $engine;
	}//end engineThrowing()

	/**
	 * The report the engine wrote is handed back key for key.
	 *
	 * OpenRegister reads `to` off the report and the engine names none — its
	 * `status` is the literal `ok` — so the fallback to the re-read object's
	 * lifecycle field is what answers the client. The provider must therefore
	 * pass the report through UNTOUCHED: an invented `to` would be a second
	 * claim about the move that storage never made.
	 *
	 * @return void
	 */
	public function testASuccessfulMoveReturnsTheEnginesReportVerbatim(): void {
		$engine = $this->createMock(StatusTransitionService::class);
		$engine->expects($this->once())->method('execute')->willReturn(self::ENGINE_REPORT);

		$report = $this->providerOver(engine: $engine)->execute(
			object: self::CASE_PAYLOAD,
			userId: 'behandelaar',
			action: 'lc-start',
			data: [],
		);

		self::assertSame(
			self::ENGINE_REPORT,
			$report,
			'The engine owns the write and its report is what OpenRegister re-reads against.',
		);
	}//end testASuccessfulMoveReturnsTheEnginesReportVerbatim()

	/**
	 * The move reaches the engine as the engine's own arguments.
	 *
	 * The provider is a delegate, so the one thing worth pinning about the
	 * happy path besides its report is that nothing is dropped or renamed on
	 * the way in: the action is the transition id, and the two published
	 * inputs arrive as `comment` and `resultTypeId`.
	 *
	 * @return void
	 */
	public function testTheEngineIsCalledWithTheCallersOwnInputs(): void {
		$engine = $this->createMock(StatusTransitionService::class);
		$engine->expects($this->once())->method('execute')->with(
			'c7b1f0de-0a6c-4a1e-9a0e-3b1f0de0a6c4',
			'lc-afhandelen',
			'Afgerond na hercontrole',
			'behandelaar',
			'rt-gegrond',
		)->willReturn(self::ENGINE_REPORT);

		$this->providerOver(engine: $engine)->execute(
			object: self::CASE_PAYLOAD,
			userId: 'behandelaar',
			action: 'lc-afhandelen',
			data: ['comment' => 'Afgerond na hercontrole', 'resultTypeId' => 'rt-gegrond'],
		);
	}//end testTheEngineIsCalledWithTheCallersOwnInputs()

	/**
	 * An absent session user is handed on as null, not as an empty uid.
	 *
	 * The engine resolves null from IUserSession, which is the identity its
	 * authorization gate judges. An empty string would be judged as a user
	 * named "".
	 *
	 * @return void
	 */
	public function testAnAbsentSessionUserIsHandedOnAsNull(): void {
		$engine = $this->createMock(StatusTransitionService::class);
		$engine->expects($this->once())->method('execute')->with(
			self::anything(),
			self::anything(),
			null,
			null,
			null,
		)->willReturn(self::ENGINE_REPORT);

		$this->providerOver(engine: $engine)->execute(
			object: self::CASE_PAYLOAD,
			userId: '',
			action: 'lc-start',
			data: [],
		);
	}//end testAnAbsentSessionUserIsHandedOnAsNull()

	/**
	 * An input that is not text is dropped rather than cast.
	 *
	 * `(string)['rt-1']` is the string `Array` plus a warning, and the engine
	 * would store it as the case's result. Dropping it leaves the engine to
	 * refuse the move for the result it is actually missing.
	 *
	 * @return void
	 */
	public function testANonTextInputIsNotCastOntoTheEngine(): void {
		$engine = $this->createMock(StatusTransitionService::class);
		$engine->expects($this->once())->method('execute')->with(
			self::anything(),
			self::anything(),
			self::anything(),
			self::anything(),
			null,
		)->willReturn(self::ENGINE_REPORT);

		$this->providerOver(engine: $engine)->execute(
			object: self::CASE_PAYLOAD,
			userId: 'behandelaar',
			action: 'lc-afhandelen',
			data: ['resultTypeId' => ['rt-gegrond']],
		);
	}//end testANonTextInputIsNotCastOntoTheEngine()

	/**
	 * A guard refusal escapes as itself, guards and all.
	 *
	 * It must stay a plain `RuntimeException` so OpenRegister answers 422, and
	 * it must stay the SAME OBJECT so the failed-guard snapshots a client
	 * renders are not lost to a wrapper.
	 *
	 * @return void
	 */
	public function testAGuardRefusalStaysARefusal(): void {
		$refusal = new GuardFailedException(
			failedGuards: [['type' => 'requiredField', 'passed' => false, 'failureMessage' => 'Vul de aanvrager in.']],
		);

		try {
			$this->providerOver(engine: $this->engineThrowing(failure: $refusal))->execute(
				object: self::CASE_PAYLOAD,
				userId: 'behandelaar',
				action: 'lc-afhandelen',
				data: [],
			);
			self::fail('A refused move must not report as a completed one.');
		} catch (Throwable $e) {
			self::assertSame($refusal, $e, 'The guard snapshots must reach the client, so the refusal is not wrapped.');
			self::assertNotInstanceOf(
				LifecycleProviderException::class,
				$e,
				'A refusal reported as a breakage tells a handler the engine is down when a guard simply said no.',
			);
		}
	}//end testAGuardRefusalStaysARefusal()

	/**
	 * Every named refusal escapes as itself, and as nothing more specific.
	 *
	 * @dataProvider refusalProvider
	 *
	 * @param string $code The engine's sentinel.
	 *
	 * @return void
	 */
	public function testANamedRefusalStaysARefusal(string $code): void {
		$refusal = new RuntimeException($code);

		try {
			$this->providerOver(engine: $this->engineThrowing(failure: $refusal))->execute(
				object: self::CASE_PAYLOAD,
				userId: 'behandelaar',
				action: 'lc-afhandelen',
				data: [],
			);
			self::fail(sprintf('"%s" is a refusal and must not report as a completed move.', $code));
		} catch (Throwable $e) {
			self::assertSame($refusal, $e, sprintf('"%s" is a verdict the engine reached, so it travels unwrapped.', $code));
			self::assertNotInstanceOf(LifecycleProviderException::class, $e, sprintf('"%s" is not a breakage.', $code));
			self::assertNotInstanceOf(LifecycleSubjectNotFoundException::class, $e, sprintf('"%s" is not a missing case.', $code));
		}
	}//end testANamedRefusalStaysARefusal()

	/**
	 * The engine's four sentinel refusals.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function refusalProvider(): array {
		return [
			'the case already left that status' => ['transition_from_status_mismatch'],
			'the caller is not in the transition group' => ['transition_unauthorized'],
			'another transition landed first' => ['transition_conflict'],
			'a closing move arrived without a result' => ['result_type_required'],
		];
	}//end refusalProvider()

	/**
	 * A breakage is reported as a breakage, never as a refused move.
	 *
	 * 🔴 THIS IS THE ROW THAT MATTERS. Each of these is the engine failing to
	 * ANSWER, and each arrives as the same `RuntimeException` a refusal does.
	 * Left unclassified they reach a handler as "that move is not allowed", who
	 * then tries another move and concludes the process forbids it.
	 *
	 * @dataProvider breakageProvider
	 *
	 * @param string $code The engine's sentinel.
	 *
	 * @return void
	 */
	public function testABrokenEngineBecomesABreakage(string $code): void {
		$failure = new RuntimeException($code);

		try {
			$this->providerOver(engine: $this->engineThrowing(failure: $failure))->execute(
				object: self::CASE_PAYLOAD,
				userId: 'behandelaar',
				action: 'lc-afhandelen',
				data: [],
			);
			self::fail(sprintf('"%s" is a breakage and must not report as a completed move.', $code));
		} catch (Throwable $e) {
			self::assertInstanceOf(
				LifecycleProviderException::class,
				$e,
				sprintf('"%s" is the engine failing to answer, and a refusal is a lie about the process.', $code),
			);
			self::assertSame($failure, $e->getPrevious(), 'The original failure must travel, or the log names nothing.');
		}
	}//end testABrokenEngineBecomesABreakage()

	/**
	 * The failures that mean "the engine could not answer".
	 *
	 * `transition_not_found` sits here rather than with the refusals because
	 * the engine reports it both when a template does not declare the move and
	 * when the case's workflowTemplate could not be read or parsed at all. The
	 * two are one sentinel, and of the two readings the breakage is the one
	 * that must not be lost.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function breakageProvider(): array {
		return [
			'the workflow could not be read' => ['transition_not_found'],
			'the template declares a transition with no target' => ['transition_missing_to_status'],
			'OpenRegister is not available' => ['storage_unavailable'],
			'the case schema is not configured' => ['case_schema_not_configured'],
			'the statusRecord schema is not configured' => ['status_record_schema_not_configured'],
			'the result schema is not configured' => ['result_schema_not_configured'],
			'the result row could not be written' => ['result_not_written'],
			'a sentinel this class has never seen' => ['some_failure_mode_added_later'],
		];
	}//end breakageProvider()

	/**
	 * An unplanned failure is a breakage, because it is never a verdict.
	 *
	 * @return void
	 */
	public function testAnUnplannedFailureBecomesABreakage(): void {
		$failure = new TypeError('CaseStatusStore::saveCase(): Argument #1 must be of type array');

		$this->expectException(LifecycleProviderException::class);
		$this->providerOver(engine: $this->engineThrowing(failure: $failure))->execute(
			object: self::CASE_PAYLOAD,
			userId: 'behandelaar',
			action: 'lc-start',
			data: [],
		);
	}//end testAnUnplannedFailureBecomesABreakage()

	/**
	 * A case OpenRegister handed over as deleted is reported missing.
	 *
	 * This is the one reading of `case_not_found` the provider can prove:
	 * OpenRegister hands over the object it read, and a soft-deleted object
	 * carries its deletion in `@self.deleted`, which dossiq's own reader does
	 * not return. So the row is gone rather than unreachable, and 404 is the
	 * truth.
	 *
	 * @return void
	 */
	public function testADeletedCaseBecomesTheNotFoundType(): void {
		$failure = new RuntimeException('case_not_found');
		$deleted = self::CASE_PAYLOAD;
		$deleted['@self']['deleted'] = ['deleted' => '2026-09-13T09:00:00+00:00', 'deletedBy' => 'archivaris'];

		try {
			$this->providerOver(engine: $this->engineThrowing(failure: $failure))->execute(
				object: $deleted,
				userId: 'behandelaar',
				action: 'lc-start',
				data: [],
			);
			self::fail('A deleted case must not report as a completed move.');
		} catch (Throwable $e) {
			self::assertInstanceOf(
				LifecycleSubjectNotFoundException::class,
				$e,
				'A deleted case is a 404, and answering 422 tells a handler the move is merely disallowed.',
			);
			self::assertNotInstanceOf(
				LifecycleProviderException::class,
				$e,
				'A case that is provably gone is not a provider that could not answer.',
			);
		}
	}//end testADeletedCaseBecomesTheNotFoundType()

	/**
	 * `case_not_found` on a case nothing says was deleted is a breakage.
	 *
	 * The engine's null comes back the same way whether OpenRegister is
	 * absent, the register is unconfigured, the read threw, or the row really
	 * is gone. With no deletion on the payload the provider cannot tell those
	 * apart, and it takes the safer half: a storage failure reported as a
	 * breakage is retried, while one reported as a deletion sends a handler
	 * looking for a case nobody removed.
	 *
	 * @return void
	 */
	public function testACaseNotFoundOnALiveObjectBecomesABreakage(): void {
		$failure = new RuntimeException('case_not_found');

		try {
			$this->providerOver(engine: $this->engineThrowing(failure: $failure))->execute(
				object: self::CASE_PAYLOAD,
				userId: 'behandelaar',
				action: 'lc-start',
				data: [],
			);
			self::fail('An unreadable case must not report as a completed move.');
		} catch (Throwable $e) {
			self::assertInstanceOf(
				LifecycleProviderException::class,
				$e,
				'An unexplained case_not_found is a failure to answer, not a proven deletion.',
			);
			self::assertNotInstanceOf(
				LifecycleSubjectNotFoundException::class,
				$e,
				'Claiming a deletion that was never proven is the lie this branch exists to avoid.',
			);
		}
	}//end testACaseNotFoundOnALiveObjectBecomesABreakage()

	/**
	 * An empty soft-delete block is a live case, not a deleted one.
	 *
	 * OpenRegister writes `deleted` as an empty array on a live row, so reading
	 * the key's presence rather than its content would call every case deleted
	 * and answer 404 for every storage failure.
	 *
	 * @return void
	 */
	public function testAnEmptyDeletedBlockIsNotADeletion(): void {
		$live = self::CASE_PAYLOAD;
		$live['@self']['deleted'] = [];

		$this->expectException(LifecycleProviderException::class);
		$this->providerOver(engine: $this->engineThrowing(failure: new RuntimeException('case_not_found')))->execute(
			object: $live,
			userId: 'behandelaar',
			action: 'lc-start',
			data: [],
		);
	}//end testAnEmptyDeletedBlockIsNotADeletion()

	/**
	 * A payload with no case id never reaches the engine.
	 *
	 * @return void
	 */
	public function testAnUnidentifiablePayloadIsABreakageAndIsNotAttempted(): void {
		$engine = $this->createMock(StatusTransitionService::class);
		$engine->expects($this->never())->method('execute');

		$this->expectException(LifecycleProviderException::class);
		$this->providerOver(engine: $engine)->execute(
			object: ['title' => 'Nameless'],
			userId: 'behandelaar',
			action: 'lc-start',
			data: [],
		);
	}//end testAnUnidentifiablePayloadIsABreakageAndIsNotAttempted()
}//end class
