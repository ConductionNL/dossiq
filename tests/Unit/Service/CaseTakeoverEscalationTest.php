<?php

/**
 * A request nobody answered goes to the unit, rather than expiring.
 *
 * The spec excludes this from e2e as time-dependent, so it is watched here
 * instead. The property is D-4: `escalated` is still OPEN. A request that
 * quietly expired would be indexed the same as one somebody refused, and the
 * two are not the same fact: one is an answer, the other is the absence of one.
 *
 * MUTATION-CHECKED 2026-09-18: setting the escalated status to `refused`
 * instead reddens testAnEscalatedRequestCanStillBeAnswered on the accept, and
 * dropping the case type's `takeoverAnswerPeriodDays` lookup reddens
 * testTheCaseTypeSaysHowLongTheHolderHas on the count. Restored after.
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

use OCA\Dossiq\Service\Custody\CaseCustodyChain;
use OCA\Dossiq\Service\Custody\CaseTakeoverRequest;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Task\EngineTaskGateway;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;

/**
 * Escalating an unanswered request to the holding unit.
 *
 * @covers \OCA\Dossiq\Service\Custody\CaseTakeoverRequest
 * @uses \OCA\Dossiq\Service\Custody\CaseCustodyChain
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses \OCA\Dossiq\Service\SettingsService
 * @uses \OCA\Dossiq\Service\Task\EngineTaskGateway
 * @uses \OCA\Dossiq\Service\CaseDateNormaliser
 *
 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md
 */
class CaseTakeoverEscalationTest extends TestCase {
	use MakesCaseDateNormaliser;


	/**
	 * The store every service under test reads and writes.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $store;

	/**
	 * One case on a case type that gives the holder two days.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryRegister();
		$this->store->seed(
			schema: 'caseType',
			uuid: 'ct-vergunning',
			row: ['title' => 'Vergunning', 'takeoverAnswerPeriodDays' => 2],
		);
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
	}//end setUp()

	/**
	 * Past the period, the request goes to the unit and says when.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md#requirement-a-colleague-may-ask-the-holder-for-a-case-req-cus-02
	 */
	public function testAnUnansweredRequestGoesToTheUnit(): void {
		$engine = $this->createMock(originalClassName: EngineTaskGateway::class);
		$engine->method('mirrorImport')->willReturn('engine-task-1');
		$engine->expects(self::once())
			->method('reassign')
			->with('engine-task-1', 'vergunningen', null)
			->willReturn(true);

		$takeovers = $this->takeovers(engine: $engine);
		$takeovers->request(caseId: 'case-1', requestedBy: 'sofie', reason: 'Mijn wijk');

		$escalated = $takeovers->escalateOverdue(now: $this->inDays(days: 3));

		self::assertCount(1, $escalated, 'Three days is past a two-day period.');
		self::assertSame('escalated', $escalated[0]['status']);
		self::assertNotSame('', ($escalated[0]['escalatedAt'] ?? ''), 'The escalation says when it happened.');
	}//end testAnUnansweredRequestGoesToTheUnit()

	/**
	 * Inside the period, nothing escalates.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md#requirement-a-colleague-may-ask-the-holder-for-a-case-req-cus-02
	 */
	public function testTheCaseTypeSaysHowLongTheHolderHas(): void {
		$takeovers = $this->takeovers();
		$takeovers->request(caseId: 'case-1', requestedBy: 'sofie', reason: 'Mijn wijk');

		self::assertSame(
			[],
			$takeovers->escalateOverdue(now: $this->inDays(days: 1)),
			'One day into a two-day period, the holder still has time to answer.',
		);
		self::assertCount(
			1,
			$takeovers->escalateOverdue(now: $this->inDays(days: 2)),
			'On the day the period runs out, the question moves.',
		);
	}//end testTheCaseTypeSaysHowLongTheHolderHas()

	/**
	 * An escalated request is still open, so the unit can still answer it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md#requirement-a-colleague-may-ask-the-holder-for-a-case-req-cus-02
	 */
	public function testAnEscalatedRequestCanStillBeAnswered(): void {
		$takeovers = $this->takeovers();
		$takeovers->request(caseId: 'case-1', requestedBy: 'sofie', reason: 'Mijn wijk');
		$escalated = $takeovers->escalateOverdue(now: $this->inDays(days: 5));

		$answered = $takeovers->refuse(
			takeoverId: $escalated[0]['id'],
			reason: 'De teamleider houdt hem bij Jan',
			refusedBy: 'teamleider',
		);

		self::assertSame('refused', $answered['status'], 'Escalated is the absence of an answer, not an answer.');
		self::assertSame('De teamleider houdt hem bij Jan', $answered['refusalReason']);
	}//end testAnEscalatedRequestCanStillBeAnswered()

	/**
	 * A request that was answered never escalates.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md#requirement-a-colleague-may-ask-the-holder-for-a-case-req-cus-02
	 */
	public function testAnAnsweredRequestDoesNotEscalate(): void {
		$takeovers = $this->takeovers();
		$record = $takeovers->request(caseId: 'case-1', requestedBy: 'sofie', reason: 'Mijn wijk');
		$takeovers->refuse(takeoverId: $record['id'], reason: 'Nee', refusedBy: 'jan');

		self::assertSame([], $takeovers->escalateOverdue(now: $this->inDays(days: 30)));
	}//end testAnAnsweredRequestDoesNotEscalate()

	/**
	 * A moment this many days from now, in ISO 8601.
	 *
	 * @param int $days How many days ahead.
	 *
	 * @return string The moment.
	 */
	private function inDays(int $days): string {
		return (new \DateTimeImmutable())->add(new \DateInterval('P' . $days . 'D'))->format('c');
	}//end inDays()

	/**
	 * The takeover service under test.
	 *
	 * @param EngineTaskGateway|null $engine The engine, or a default one.
	 *
	 * @return CaseTakeoverRequest The service.
	 */
	private function takeovers(?EngineTaskGateway $engine = null): CaseTakeoverRequest {
		if ($engine === null) {
			$engine = $this->createMock(originalClassName: EngineTaskGateway::class);
			$engine->method('mirrorImport')->willReturn('engine-task-1');
			$engine->method('reassign')->willReturn(true);
		}

		return new CaseTakeoverRequest(
			settingsService: $this->settings(),
			custody: new CaseCustodyChain(
				settingsService: $this->settings(),
				logger: $this->createMock(originalClassName: LoggerInterface::class),
				dates: $this->caseDates(),
			),
			tasks: $engine,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end takeovers()

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
