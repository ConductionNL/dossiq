<?php

/**
 * Asking the holder for a case.
 *
 * The distinction this watches is D-3: a case nobody holds is CLAIMED, a case
 * somebody holds is ASKED FOR. So the asker does not get the case here, no
 * matter what they write in the reason, and the request goes to the holder as a
 * task rather than being a note nobody sees.
 *
 * MUTATION-CHECKED 2026-09-18: dropping the `$holder === $requestedBy` refusal
 * lets a holder ask themselves for their own case and reddens
 * testYouCannotAskYourselfForTheCaseYouHold on the expected exception.
 * Restored after.
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
 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Custody\CaseCustodyChain;
use OCA\Dossiq\Service\Custody\CaseTakeoverRequest;
use OCA\Dossiq\Service\Custody\TakeoverStore;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Task\EngineTaskGateway;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;

/**
 * The pull: who is asked, what is recorded, and what is refused.
 *
 * @covers \OCA\Dossiq\Service\Custody\CaseTakeoverRequest
 * @uses \OCA\Dossiq\Service\Custody\CaseCustodyChain
 * @uses \OCA\Dossiq\Exception\RefusedException
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses \OCA\Dossiq\Service\SettingsService
 * @uses \OCA\Dossiq\Service\Task\EngineTaskGateway
 * @uses \OCA\Dossiq\Service\CaseDateNormaliser
 *
 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md
 */
class CaseTakeoverRequestTest extends TestCase {
	use MakesCaseDateNormaliser;


	/**
	 * The store every service under test reads and writes.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $store;

	/**
	 * The engine, recording what it was asked to carry.
	 *
	 * @var EngineTaskGateway&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $engine;

	/**
	 * One case, held by Jan for Vergunningen.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryRegister();
		$this->store->seed(
			schema: 'case',
			uuid: 'case-1',
			row: [
				'title' => 'Dakkapel Prinsengracht 12',
				'caseType' => 'ct-vergunning',
				'assignedGroup' => 'vergunningen',
				'assignee' => 'jan',
			],
		);

		$this->engine = $this->createMock(originalClassName: EngineTaskGateway::class);
		$this->engine->method('mirrorImport')->willReturn('engine-task-1');
	}//end setUp()

	/**
	 * The request names the holder, the unit and the reason, and the case does not move.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md#requirement-a-colleague-may-ask-the-holder-for-a-case-req-cus-02
	 */
	public function testARequestReachesTheHolderAndLeavesTheCaseAlone(): void {
		$record = $this->takeovers()->request(
			caseId: 'case-1',
			requestedBy: 'sofie',
			reason: 'Dit dossier hoort bij mijn wijk',
		);

		self::assertSame('pending', $record['status'], 'Nobody has answered yet.');
		self::assertSame('sofie', $record['requestedBy']);
		self::assertSame('jan', $record['holder'], 'The request is addressed to the person who has it.');
		self::assertSame('vergunningen', $record['holdingUnit'], 'And it remembers the unit, because that is where it escalates.');
		self::assertSame('Dit dossier hoort bij mijn wijk', $record['reason']);
		self::assertSame('engine-task-1', $record['taskId'], 'The question reaches the holder as a task, not as a note.');

		$case = $this->store->row(schema: 'case', uuid: 'case-1');
		self::assertSame('jan', $case['assignee'], 'Asking is not taking: the case stays with its holder.');
		self::assertSame('vergunningen', $case['assignedGroup']);
	}//end testARequestReachesTheHolderAndLeavesTheCaseAlone()

	/**
	 * The holder named on the request is the one the custody chain names.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md#requirement-a-colleague-may-ask-the-holder-for-a-case-req-cus-02
	 */
	public function testTheOpenHoldingWinsOverTheCaseSeat(): void {
		$this->chain()->begin(
			caseId: 'case-1',
			organisationUnit: 'toezicht',
			handler: 'els',
			from: '2026-03-03T09:00:00+01:00',
			reason: 'Handhaving',
			movedBy: 'jan',
		);

		$record = $this->takeovers()->request(caseId: 'case-1', requestedBy: 'sofie', reason: 'Mijn wijk');

		self::assertSame('els', $record['holder'], 'The chain is the record of who holds it, so it is what the request asks.');
		self::assertSame('toezicht', $record['holdingUnit']);
	}//end testTheOpenHoldingWinsOverTheCaseSeat()

	/**
	 * A request with no reason is not a request.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md#requirement-a-colleague-may-ask-the-holder-for-a-case-req-cus-02
	 */
	public function testARequestWithoutAReasonIsRefused(): void {
		$this->expectException(RefusedException::class);

		$this->takeovers()->request(caseId: 'case-1', requestedBy: 'sofie', reason: '   ');
	}//end testARequestWithoutAReasonIsRefused()

	/**
	 * You cannot ask yourself for the case you are already holding.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md#requirement-a-colleague-may-ask-the-holder-for-a-case-req-cus-02
	 */
	public function testYouCannotAskYourselfForTheCaseYouHold(): void {
		$this->expectException(RefusedException::class);

		$this->takeovers()->request(caseId: 'case-1', requestedBy: 'jan', reason: 'Ik wil hem houden');
	}//end testYouCannotAskYourselfForTheCaseYouHold()

	/**
	 * A case nobody can read is not asked for.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md#requirement-a-colleague-may-ask-the-holder-for-a-case-req-cus-02
	 */
	public function testAnUnknownCaseIsRefused(): void {
		$this->expectException(RefusedException::class);

		$this->takeovers()->request(caseId: 'case-404', requestedBy: 'sofie', reason: 'Mijn wijk');
	}//end testAnUnknownCaseIsRefused()

	/**
	 * A request the engine would not carry is still recorded.
	 *
	 * A delivery failure must not lose the question: the holder can still see
	 * it on the case, and an empty task id is what says the engine refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md#requirement-a-colleague-may-ask-the-holder-for-a-case-req-cus-02
	 */
	public function testARequestSurvivesAnEngineThatWillNotTakeTheTask(): void {
		$engine = $this->createMock(originalClassName: EngineTaskGateway::class);
		$engine->method('mirrorImport')->willReturn('');

		$record = $this->takeovers(engine: $engine)->request(
			caseId: 'case-1',
			requestedBy: 'sofie',
			reason: 'Mijn wijk',
		);

		self::assertSame('pending', $record['status']);
		self::assertSame('', ($record['taskId'] ?? ''), 'An empty task id is the delivery failure, said out loud.');
		self::assertCount(1, $this->store->all(schema: 'caseTakeover'), 'The question is on the record either way.');
	}//end testARequestSurvivesAnEngineThatWillNotTakeTheTask()

	/**
	 * Every request on a case reads back newest first.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md#requirement-a-colleague-may-ask-the-holder-for-a-case-req-cus-02
	 */
	public function testTheRequestsOnACaseReadBack(): void {
		$takeovers = $this->takeovers();
		$takeovers->request(caseId: 'case-1', requestedBy: 'sofie', reason: 'Mijn wijk');
		$takeovers->request(caseId: 'case-1', requestedBy: 'els', reason: 'Ik ken het gezin');

		$requests = $takeovers->onCase(caseId: 'case-1');

		self::assertCount(2, $requests);
		self::assertSame(
			['sofie', 'els'],
			array_column($requests, 'requestedBy'),
			'Two requests recorded in the same second still both read back.',
		);
	}//end testTheRequestsOnACaseReadBack()

	/**
	 * The takeover service under test.
	 *
	 * @param EngineTaskGateway|null $engine The engine, or the default one.
	 *
	 * @return CaseTakeoverRequest The service.
	 */
	private function takeovers(?EngineTaskGateway $engine = null): CaseTakeoverRequest {
		return new CaseTakeoverRequest(
			settingsService: $this->settings(),
			custody: $this->chain(),
			tasks: ($engine ?? $this->engine),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			// A REAL store over the SAME settings double the assertions read
			// through. Only the wiring line moved when it was split out.
			store: new TakeoverStore($this->settings(), $this->createMock(originalClassName: LoggerInterface::class)),
		);
	}//end takeovers()

	/**
	 * The chain the answers write into.
	 *
	 * @return CaseCustodyChain The chain.
	 */
	private function chain(): CaseCustodyChain {
		return new CaseCustodyChain(
			settingsService: $this->settings(),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			dates: $this->caseDates(),
		);
	}//end chain()

	/**
	 * A settings service that answers the in-memory store and the slugs it holds.
	 *
	 * @return SettingsService The settings service.
	 */
	private function settings(): SettingsService {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key, string $default = ''): string {
				$map = [
					'register' => 'dossiq',
					'case_schema' => 'case',
					'case_type_schema' => 'caseType',
					'case_custody_schema' => 'caseCustody',
					'case_takeover_schema' => 'caseTakeover',
				];

				return ($map[$key] ?? $default);
			}
		);

		return $settings;
	}//end settings()
}//end class
