<?php

/**
 * Dossiq DependentTermOffer golden test.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Term
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Term;

use OCA\Dossiq\Service\DeadlineExtensionService;
use OCA\Dossiq\Service\Relation\CaseRelationStore;
use OCA\Dossiq\Service\Task\EngineTaskGateway;
use OCA\Dossiq\Service\Term\DependentTermOffer;
use OCA\Dossiq\Service\TermijnService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;

/**
 * Pins every offer, refusal and log line of the dependent term offer, exactly.
 *
 * @spec openspec/specs/related-case-linking/spec.md
 */
class DependentTermOfferGoldenTest extends TestCase {
	/**
	 * The reverse relation index.
	 *
	 * @var CaseRelationStore&MockObject
	 */
	private CaseRelationStore $store;

	/**
	 * Term instances.
	 *
	 * @var TermijnService&MockObject
	 */
	private TermijnService $terms;

	/**
	 * The one way a term moves.
	 *
	 * @var DeadlineExtensionService&MockObject
	 */
	private DeadlineExtensionService $extension;

	/**
	 * The engine task the offer is.
	 *
	 * @var EngineTaskGateway&MockObject
	 */
	private EngineTaskGateway $tasks;

	/**
	 * The log lines, in order.
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	private array $logged = [];

	/**
	 * Build the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store     = $this->createMock(CaseRelationStore::class);
		$this->terms     = $this->createMock(TermijnService::class);
		$this->extension = $this->createMock(DeadlineExtensionService::class);
		$this->tasks     = $this->createMock(EngineTaskGateway::class);
		$this->logged    = [];
	}//end setUp()

	/**
	 * The rows are read for waits only, deduplicated, and the task is written whole.
	 *
	 * @return void
	 */
	public function testTheOfferIsWrittenWhole(): void {
		$wait = ['property' => 'blockingCases', 'type' => 'waitsOn'];
		$this->store->expects($this->once())->method('relationRows')
			->with('case-a', true)
			->willReturn(
				[
					['id' => 'case-b', 'title' => 'Eerste', 'relation' => $wait],
					['relation' => 'not-a-relation', 'id' => 'case-x'],
					['id' => 'case-y', 'relation' => ['property' => 'relatedCases']],
					['id' => 'case-a', 'relation' => $wait],
					['id' => '', 'relation' => $wait],
					['uuid' => 'case-c', 'relation' => $wait],
					['id' => 'case-b', 'title' => 'Tweede', 'relation' => $wait],
					['id' => 'case-d', 'relation' => $wait],
					['id' => 'case-e', 'relation' => $wait],
				]
			);
		$this->terms->method('getTermijnInstanceForZaak')->willReturnCallback(
			static fn (string $caseId): ?array => match ($caseId) {
				'case-b' => ['id' => 'term-b', 'status' => 'verlengd'],
				'case-c' => ['status' => 'paused'],
				'case-d' => ['id' => 'term-d', 'status' => 'afgerond'],
				default => null,
			}
		);
		$written = [];
		$this->tasks->method('mirrorImport')->willReturnCallback(
			static function (array $task, string $caseId, ?string $actor) use (&$written): string {
				$written[] = [$task, $caseId, $actor];
				return ($caseId === 'case-c') ? '' : 'engine-1';
			}
		);

		$this->assertSame(1, $this->offer()->offer(sourceCaseId: 'case-a', sourceTitle: '', daysImpact: 7));
		$this->assertSame(
			[
				[
					[
						'id' => 'term-follow:case-b:case-a:term-b',
						'title' => 'De termijn van case-a is met 7 dagen verschoven',
						'description' => 'Deze zaak wacht op case-a. Die termijn is met 7 dagen verschoven. '
							. 'Wilt u de termijn van deze zaak net zo verschuiven? '
							. 'De aanvrager wordt daarover geinformeerd, zoals Awb 4:14 voorschrijft.',
						'status' => 'available',
						'metadata' => [
							'dossiq' => [
								'kind' => 'term-follow',
								'sourceCase' => 'case-a',
								'daysImpact' => 7,
								'deadlineInstance' => 'term-b',
							],
						],
					],
					'case-b',
					null,
				],
				[
					[
						'id' => 'term-follow:case-c:case-a:',
						'title' => 'De termijn van case-a is met 7 dagen verschoven',
						'description' => 'Deze zaak wacht op case-a. Die termijn is met 7 dagen verschoven. '
							. 'Wilt u de termijn van deze zaak net zo verschuiven? '
							. 'De aanvrager wordt daarover geinformeerd, zoals Awb 4:14 voorschrijft.',
						'status' => 'available',
						'metadata' => [
							'dossiq' => [
								'kind' => 'term-follow',
								'sourceCase' => 'case-a',
								'daysImpact' => 7,
								'deadlineInstance' => '',
							],
						],
					],
					'case-c',
					null,
				],
			],
			$written
		);
		$this->assertSame([], $this->logged);
	}//end testTheOfferIsWrittenWhole()

	/**
	 * The later title of a deduplicated case wins, and a named source is used in the text.
	 *
	 * @return void
	 */
	public function testANamedSourceAndAFailedWrite(): void {
		$wait = ['property' => 'blockingCases'];
		$this->store->method('relationRows')->willReturn(
			[
				['id' => 'case-b', 'title' => 'Eerste', 'relation' => $wait],
				['id' => 'case-b', 'title' => 'Tweede', 'relation' => $wait],
			]
		);
		$this->assertSame(
			[['caseId' => 'case-b', 'title' => 'Tweede']],
			$this->offer()->dependentsOf(sourceCaseId: 'case-a')
		);

		$this->terms->method('getTermijnInstanceForZaak')->willReturn(['id' => 'term-b', 'status' => 'lopend']);
		$titles = [];
		$this->tasks->method('mirrorImport')->willReturnCallback(
			static function (array $task) use (&$titles): string {
				$titles[] = $task['title'];
				throw new RuntimeException('engine down');
			}
		);

		$this->assertSame(0, $this->offer()->offer(sourceCaseId: 'case-a', sourceTitle: 'Bezwaar A', daysImpact: 3));
		$this->assertSame(['De termijn van Bezwaar A is met 3 dagen verschoven'], $titles);
		$this->assertSame(
			[['warning', 'Dossiq: the moved term of "case-a" could not be offered to "case-b": engine down']],
			$this->logged
		);
	}//end testANamedSourceAndAFailedWrite()

	/**
	 * No days, no source, or too many dependents.
	 *
	 * @return void
	 */
	public function testNothingToOfferAndTheBound(): void {
		$this->store->expects($this->once())->method('relationRows')->willReturn(
			array_map(
				static fn (int $i): array => ['id' => 'case-' . $i, 'relation' => ['property' => 'blockingCases']],
				range(1, 52)
			)
		);

		$this->assertSame(0, $this->offer()->offer(sourceCaseId: 'case-a', sourceTitle: 'A', daysImpact: 0));
		$this->assertSame([], $this->offer()->dependentsOf(sourceCaseId: '  '));
		$dependents = $this->offer()->dependentsOf(sourceCaseId: 'case-a');
		$this->assertCount(50, $dependents);
		$this->assertSame(['caseId' => 'case-1', 'title' => ''], $dependents[0]);
		$this->assertSame(['caseId' => 'case-50', 'title' => ''], $dependents[49]);
		$this->assertSame(
			[['warning', 'Dossiq: case "case-a" is waited on by 52 cases; only the first 50 are offered the move']],
			$this->logged
		);
	}//end testNothingToOfferAndTheBound()

	/**
	 * Every refusal accept gives, in order.
	 *
	 * @return void
	 */
	public function testEveryRefusalOfAccept(): void {
		$this->tasks->method('find')->willReturnCallback(
			static fn (string $taskId): ?array => match ($taskId) {
				'none' => null,
				'flat' => ['metadata' => ['dossiq' => 'x']],
				'other' => ['metadata' => ['dossiq' => ['kind' => 'other', 'daysImpact' => 3, 'deadlineInstance' => 't']]],
				'nodays' => ['metadata' => ['dossiq' => ['kind' => 'term-follow', 'daysImpact' => 0, 'deadlineInstance' => 't']]],
				'noterm' => ['metadata' => ['dossiq' => ['kind' => 'term-follow', 'daysImpact' => 3]]],
				default => [
					'objectUuid' => 'case-b',
					'metadata' => ['dossiq' => ['kind' => 'term-follow', 'sourceCase' => 'case-a', 'daysImpact' => '5', 'deadlineInstance' => $taskId]],
				],
			}
		);
		$this->terms->method('getTermijnInstance')->willReturnCallback(
			static fn (string $termInstanceId): ?array => match ($termInstanceId) {
				'gone' => null,
				'noend' => ['endDateCurrent' => ''],
				'baddate' => ['endDateCurrent' => 'not a date'],
				default => ['endDateCurrent' => '2026-03-02'],
			}
		);
		$this->extension->method('requestExtension')->willReturnCallback(
			static function (string $termInstanceId, string $rationale, string $newEndDate): array {
				if ($termInstanceId === 'ceiling') {
					throw new RuntimeException('maximum reached');
				}

				return ['type' => 'verleng', 'at' => $termInstanceId, 'rationale' => $rationale, 'end' => $newEndDate];
			}
		);
		$completed = [];
		$this->tasks->method('complete')->willReturnCallback(
			static function (string $taskId, array $data, string $outcome, ?string $actor) use (&$completed): bool {
				$completed[] = [$taskId, $data, $outcome, $actor];
				return true;
			}
		);

		$outcomes = [];
		foreach (['none', 'flat', 'other', 'nodays', 'noterm', 'gone', 'noend', 'baddate', 'ceiling', 'ok'] as $taskId) {
			$outcomes[$taskId] = $this->offer()->accept(taskId: $taskId, actor: 'jan');
		}

		$this->assertSame(
			[
				'none' => ['refused' => 'not-a-term-follow-task'],
				'flat' => ['refused' => 'not-a-term-follow-task'],
				'other' => ['refused' => 'not-a-term-follow-task'],
				'nodays' => ['refused' => 'not-a-term-follow-task'],
				'noterm' => ['refused' => 'not-a-term-follow-task'],
				'gone' => ['refused' => 'term-gone'],
				'noend' => ['refused' => 'term-has-no-end-date'],
				'baddate' => ['refused' => 'term-has-no-end-date'],
				'ceiling' => ['refused' => 'extension-refused'],
				'ok' => ['event' => ['type' => 'verleng', 'at' => 'ok', 'rationale' => 'follows case-a', 'end' => '2026-03-07']],
			],
			$outcomes
		);
		$this->assertSame([['ok', [], 'accepted', 'jan']], $completed);
		$this->assertSame(
			[['info', 'Dossiq: a followed extension was refused on "case-b": maximum reached']],
			$this->logged
		);
	}//end testEveryRefusalOfAccept()

	/**
	 * The service over the doubles, logging into this test.
	 *
	 * @return DependentTermOffer The service under test.
	 */
	private function offer(): DependentTermOffer {
		$logged = &$this->logged;
		$logger = new class ($logged) extends AbstractLogger {
			/**
			 * The log lines.
			 *
			 * @var array<int, array{0: string, 1: string}>
			 */
			private array $lines;

			/**
			 * Constructor.
			 *
			 * @param array $lines The lines, by reference.
			 */
			public function __construct(array &$lines) {
				$this->lines = &$lines;
			}//end __construct()

			/**
			 * Record one line.
			 *
			 * @param mixed              $level   The level.
			 * @param string|\Stringable $message The message.
			 * @param array              $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->lines[] = [(string) $level, (string) $message];
			}//end log()
		};

		return new DependentTermOffer(
			store: $this->store,
			terms: $this->terms,
			extension: $this->extension,
			tasks: $this->tasks,
			logger: $logger
		);
	}//end offer()
}//end class
