<?php

/**
 * CaseActionProvider::execute() on the payload edges the other suites leave open.
 *
 * A move on a case homed in another application is refused before the engine
 * is asked anything; `@self.deleted` is read in every shape OpenRegister can
 * hand over before a `case_not_found` is called a 404; and an integer input is
 * passed on as its decimal text, not dropped.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Lifecycle;

use OCA\Dossiq\Lifecycle\CaseActionList;
use OCA\Dossiq\Lifecycle\CaseActionProvider;
use OCA\Dossiq\Service\Access\OpenRegisterGrantsGateway;
use OCA\Dossiq\Service\Cases\ExternalHome;
use OCA\Dossiq\Service\Money\CasePaymentReader;
use OCA\Dossiq\Service\Money\CasePaymentState;
use OCA\Dossiq\Service\Money\UnpaidCaseGate;
use OCA\Dossiq\Service\StatusTransitionService;
use OCA\Dossiq\Service\Transitions\CaseResultWriter;
use OCA\Dossiq\Service\Transitions\CaseTypeReader;
use OCA\OpenRegister\Exception\LifecycleProviderException;
use OCA\OpenRegister\Exception\LifecycleSubjectNotFoundException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Payload edges of the lifecycle provider's write path.
 *
 * @covers \OCA\Dossiq\Lifecycle\CaseActionProvider
 * @uses   \OCA\Dossiq\Lifecycle\CaseActionList
 * @uses   \OCA\Dossiq\Service\Cases\ExternalHome
 * @uses   \OCA\Dossiq\Service\Money\UnpaidCaseGate
 * @uses   \OCA\Dossiq\Service\Money\CasePaymentState
 */
final class CaseActionProviderPayloadEdgesTest extends TestCase {

	private const CASE_ID = 'c7b1f0de-0a6c-4a1e-9a0e-3b1f0de0a6c4';

	private const CASE_PAYLOAD = [
		'id' => self::CASE_ID,
		'caseType' => 'ct-handhaving',
		'status' => 'st-intake',
		'@self' => ['id' => self::CASE_ID, 'deleted' => null],
	];

	private const ENGINE_REPORT = ['status' => 'ok', 'version' => 5];

	/**
	 * A provider over the given engine, with the real action list and home reader.
	 *
	 * @param StatusTransitionService $engine The engine double.
	 *
	 * @return CaseActionProvider The provider.
	 */
	private function providerOver(StatusTransitionService $engine): CaseActionProvider {
		return new CaseActionProvider(
			transitionEngine: $engine,
			grants: $this->createMock(OpenRegisterGrantsGateway::class),
			externalHome: new ExternalHome(),
			actions: new CaseActionList(
				resultWriter: $this->createMock(CaseResultWriter::class),
				externalHome: new ExternalHome(),
				caseTypes: $this->createMock(CaseTypeReader::class),
				unpaidCases: new UnpaidCaseGate(new CasePaymentState()),
				payments: $this->createMock(CasePaymentReader::class),
			),
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end providerOver()

	/**
	 * An engine that answers `case_not_found`.
	 *
	 * @return StatusTransitionService The engine double.
	 */
	private function engineWithoutTheCase(): StatusTransitionService {
		$engine = $this->createMock(StatusTransitionService::class);
		$engine->method('execute')->willThrowException(new RuntimeException('case_not_found'));

		return $engine;
	}//end engineWithoutTheCase()

	/**
	 * A case homed elsewhere is refused with the home sentence; the engine is never asked.
	 *
	 * @return void
	 */
	public function testAMoveOnACaseHomedElsewhereIsRefusedBeforeTheEngine(): void {
		$engine = $this->createMock(StatusTransitionService::class);
		$engine->expects($this->never())->method('execute');
		$case = self::CASE_PAYLOAD + ['externalApplication' => 'Squit', 'externalIdentifier' => 'Z-2026-001'];

		try {
			$this->providerOver(engine: $engine)->execute(object: $case, userId: 'behandelaar', action: 'lc-start', data: []);
			self::fail('A move on a case homed elsewhere must be refused.');
		} catch (RuntimeException $e) {
			self::assertSame('This case is handled in Squit, as Z-2026-001.', $e->getMessage());
			self::assertNotInstanceOf(LifecycleProviderException::class, $e);
		}
	}//end testAMoveOnACaseHomedElsewhereIsRefusedBeforeTheEngine()

	/**
	 * A `case_not_found` whose payload has no readable `@self` is a breakage, not a 404.
	 *
	 * @return void
	 */
	public function testANonArraySelfIsNotReadAsDeleted(): void {
		$case = self::CASE_PAYLOAD;
		$case['@self'] = 'not-metadata';

		$this->expectException(LifecycleProviderException::class);
		$this->providerOver(engine: $this->engineWithoutTheCase())->execute(object: $case, userId: 'behandelaar', action: 'lc-start', data: []);
	}//end testANonArraySelfIsNotReadAsDeleted()

	/**
	 * A deletion stamp given as a plain timestamp string makes the case gone.
	 *
	 * @return void
	 */
	public function testADeletionTimestampStringMakesTheCaseGone(): void {
		$case = self::CASE_PAYLOAD;
		$case['@self']['deleted'] = '2026-09-13T09:00:00+00:00';

		$this->expectException(LifecycleSubjectNotFoundException::class);
		$this->providerOver(engine: $this->engineWithoutTheCase())->execute(object: $case, userId: 'behandelaar', action: 'lc-start', data: []);
	}//end testADeletionTimestampStringMakesTheCaseGone()

	/**
	 * A blank deletion string or an empty deletion array is no deletion.
	 *
	 * @param mixed $deleted The `@self.deleted` value.
	 *
	 * @dataProvider notDeletedProvider
	 *
	 * @return void
	 */
	public function testAnEmptyDeletionMarkerIsABreakage(mixed $deleted): void {
		$case = self::CASE_PAYLOAD;
		$case['@self']['deleted'] = $deleted;

		try {
			$this->providerOver(engine: $this->engineWithoutTheCase())->execute(object: $case, userId: 'behandelaar', action: 'lc-start', data: []);
			self::fail('A case_not_found must not report as a completed move.');
		} catch (LifecycleProviderException $e) {
			self::assertNotInstanceOf(LifecycleSubjectNotFoundException::class, $e);
			self::assertSame('case_not_found', $e->getPrevious()?->getMessage());
		}
	}//end testAnEmptyDeletionMarkerIsABreakage()

	/**
	 * Deletion markers that do not mean the case is gone.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function notDeletedProvider(): array {
		return [
			'blank string' => ['   '],
			'empty array' => [[]],
			'boolean true' => [true],
		];
	}//end notDeletedProvider()

	/**
	 * An integer input is handed on as its decimal text; zero-length text is null.
	 *
	 * @return void
	 */
	public function testAnIntegerInputIsPassedOnAsText(): void {
		$engine = $this->createMock(StatusTransitionService::class);
		$engine->expects($this->once())->method('execute')->with(
			self::CASE_ID,
			'lc-afhandelen',
			null,
			'behandelaar',
			'42',
		)->willReturn(self::ENGINE_REPORT);

		$report = $this->providerOver(engine: $engine)->execute(
			object: self::CASE_PAYLOAD,
			userId: 'behandelaar',
			action: 'lc-afhandelen',
			data: ['comment' => '   ', 'resultTypeId' => 42],
		);

		self::assertSame(self::ENGINE_REPORT, $report);
	}//end testAnIntegerInputIsPassedOnAsText()
}//end class
