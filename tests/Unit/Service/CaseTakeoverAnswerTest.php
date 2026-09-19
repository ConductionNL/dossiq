<?php

/**
 * The answer the holder gives, and what each answer does to the case.
 *
 * Accept and refuse are not symmetrical and these tests say so: accept MOVES
 * the case and opens a holding naming the asker; refuse leaves the case exactly
 * where it is and records why. A refusal with no reason is not an answer at
 * all, which is the whole difference between this and a timeout.
 *
 * MUTATION-CHECKED 2026-09-18: dropping the `$this->custody->move(...)` call
 * from CaseTakeoverRequest::accept() reddens
 * testAcceptingOpensAHoldingNamingTheAsker on the handler assertion; dropping
 * the empty-reason refusal reddens testARefusalWithoutAReasonIsNotAnAnswer on
 * the expected exception. Restored after.
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
 * Accepting, refusing, and answering twice.
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
class CaseTakeoverAnswerTest extends TestCase {
	use MakesCaseDateNormaliser;


	/**
	 * The store every service under test reads and writes.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $store;

	/**
	 * The request Jan has to answer.
	 *
	 * @var array<string, mixed>
	 */
	private array $request;

	/**
	 * One case held by Jan, with Sofie already asking for it.
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

		$this->chain()->begin(
			caseId: 'case-1',
			organisationUnit: 'vergunningen',
			handler: 'jan',
			from: '2026-03-03T09:00:00+01:00',
			reason: 'Registered',
			movedBy: 'jan',
		);

		$this->request = $this->takeovers()->request(
			caseId: 'case-1',
			requestedBy: 'sofie',
			reason: 'Dit dossier hoort bij mijn wijk',
		);
	}//end setUp()

	/**
	 * Accepting moves the case to the asker and opens a holding naming them.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md#requirement-a-colleague-may-ask-the-holder-for-a-case-req-cus-02
	 */
	public function testAcceptingOpensAHoldingNamingTheAsker(): void {
		$answered = $this->takeovers()->accept(takeoverId: $this->request['id'], acceptedBy: 'jan');

		self::assertSame('accepted', $answered['status']);
		self::assertSame('jan', $answered['answeredBy']);
		self::assertNotSame('', ($answered['answeredAt'] ?? ''), 'An answer without a moment cannot be read back in order.');

		$open = $this->chain()->openHoldingFor(caseId: 'case-1');
		self::assertNotNull($open);
		self::assertSame('sofie', $open['handler'], 'The case is now held by the person who asked for it.');
		self::assertSame(2, (int)$open['sequence'], 'And that is the second holding, not a rewrite of the first.');

		$case = $this->store->row(schema: 'case', uuid: 'case-1');
		self::assertSame('sofie', $case['assignee'], 'The seat follows the holding.');
	}//end testAcceptingOpensAHoldingNamingTheAsker()

	/**
	 * Refusing leaves the case where it is, with the reason on the record.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md#requirement-a-colleague-may-ask-the-holder-for-a-case-req-cus-02
	 */
	public function testRefusingKeepsTheCaseAndRecordsWhy(): void {
		$answered = $this->takeovers()->refuse(
			takeoverId: $this->request['id'],
			reason: 'Ik ben er al mee bezig, de hoorzitting is volgende week',
			refusedBy: 'jan',
		);

		self::assertSame('refused', $answered['status']);
		self::assertSame(
			'Ik ben er al mee bezig, de hoorzitting is volgende week',
			$answered['refusalReason'],
			'A refusal is only an answer when it says why.',
		);

		$case = $this->store->row(schema: 'case', uuid: 'case-1');
		self::assertSame('jan', $case['assignee'], 'The case stays with the holder.');

		$open = $this->chain()->openHoldingFor(caseId: 'case-1');
		self::assertSame('jan', $open['handler'], 'And so does the holding.');
		self::assertSame(1, (int)$open['sequence'], 'A refusal writes no new holding.');
	}//end testRefusingKeepsTheCaseAndRecordsWhy()

	/**
	 * A refusal with no reason is refused itself.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md#requirement-a-colleague-may-ask-the-holder-for-a-case-req-cus-02
	 */
	public function testARefusalWithoutAReasonIsNotAnAnswer(): void {
		$this->expectException(RefusedException::class);

		$this->takeovers()->refuse(takeoverId: $this->request['id'], reason: '  ', refusedBy: 'jan');
	}//end testARefusalWithoutAReasonIsNotAnAnswer()

	/**
	 * A request that was already answered cannot be answered again.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md#requirement-a-colleague-may-ask-the-holder-for-a-case-req-cus-02
	 */
	public function testAnAnsweredRequestIsNotAnsweredTwice(): void {
		$takeovers = $this->takeovers();
		$takeovers->refuse(takeoverId: $this->request['id'], reason: 'Nee', refusedBy: 'jan');

		$this->expectException(RefusedException::class);
		$takeovers->accept(takeoverId: $this->request['id'], acceptedBy: 'jan');
	}//end testAnAnsweredRequestIsNotAnsweredTwice()

	/**
	 * A request nobody recorded cannot be answered.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md#requirement-a-colleague-may-ask-the-holder-for-a-case-req-cus-02
	 */
	public function testAnUnknownRequestIsRefused(): void {
		$this->expectException(RefusedException::class);

		$this->takeovers()->accept(takeoverId: 'takeover-404', acceptedBy: 'jan');
	}//end testAnUnknownRequestIsRefused()

	/**
	 * The takeover service under test.
	 *
	 * @return CaseTakeoverRequest The service.
	 */
	private function takeovers(): CaseTakeoverRequest {
		$engine = $this->createMock(originalClassName: EngineTaskGateway::class);
		$engine->method('mirrorImport')->willReturn('engine-task-1');

		return new CaseTakeoverRequest(
			custody: $this->chain(),
			tasks: $engine,
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
