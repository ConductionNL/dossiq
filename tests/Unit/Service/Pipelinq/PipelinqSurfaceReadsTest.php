<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Pipelinq;

use OCA\Dossiq\Service\People\PartyVocabulary;
use OCA\Dossiq\Service\Pipelinq\PartyKindConsumer;
use OCA\Dossiq\Service\Pipelinq\PipelinqGateway;
use OCA\Dossiq\Service\Pipelinq\ProgrammeConsumer;
use OCA\Dossiq\Service\SettingsService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The reads the case surfaces need beyond what the consumers already had: the
 * whole kind vocabulary and a case type's declared acceptance for the case
 * type editor, and the programme a case hangs under, with its progress.
 *
 * Every pipelinq double here carries the REAL method signature of pipelinq's
 * service on development (PartyKindRegistryService::vocabulary(),
 * ::acceptanceFor(string $recordType), ProgrammePortfolioService::read(string
 * $schemaKey, array $filters), ::tasksOf(string $programmeId),
 * ::progressFor(array $programme, array $tasks = [], ?array $effort = null)),
 * because the gateway spreads named arguments and a renamed parameter fails
 * only at runtime.
 *
 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-hangs-under-a-programme-by-reference-and-the-progress-figure-names-its-mode-req-plq-08
 */
class PipelinqSurfaceReadsTest extends TestCase {

	/**
	 * The pipelinq services this instance has, keyed by class name.
	 *
	 * @var array<string, object>
	 */
	private array $services = [];

	/**
	 * A gateway over whatever `$this->services` holds.
	 *
	 * @return PipelinqGateway The gateway.
	 */
	private function gateway(): PipelinqGateway {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id): object {
				if (isset($this->services[$id]) === false) {
					throw new RuntimeException("nothing answers to {$id}");
				}

				return $this->services[$id];
			}
		);

		return new PipelinqGateway($container, $this->createMock(LoggerInterface::class));
	}//end gateway()

	/**
	 * A kind consumer over the current services.
	 *
	 * @return PartyKindConsumer The consumer.
	 */
	private function kinds(): PartyKindConsumer {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new PartyKindConsumer(
			$this->gateway(),
			new PartyVocabulary($l10n),
			$this->createMock(SettingsService::class),
			$this->createMock(LoggerInterface::class),
		);
	}//end kinds()

	/**
	 * Without pipelinq the editor offers dossiq's three and says whose they are.
	 *
	 * @return void
	 */
	public function testTheVocabularyFallsBackToDossiqsThree(): void {
		$answer = $this->kinds()->vocabulary();

		$this->assertSame('dossiq', $answer['source']);
		$this->assertSame(['person', 'organisation', 'address'], array_column($answer['kinds'], 'key'));
	}//end testTheVocabularyFallsBackToDossiqsThree()

	/**
	 * With pipelinq the editor offers every kind pipelinq holds, in a list.
	 *
	 * @return void
	 */
	public function testTheVocabularyIsPipelinqsWhenItAnswers(): void {
		$this->services[PipelinqGateway::PARTY_KINDS] = new class {
			/**
			 * @return array<string, array<string, mixed>> Keyed by code, as pipelinq answers.
			 */
			public function vocabulary(): array {
				return [
					'aanvrager' => ['code' => 'aanvrager', 'label' => 'Aanvrager'],
					'gemachtigde' => ['code' => 'gemachtigde', 'label' => 'Gemachtigde'],
				];
			}
		};

		$answer = $this->kinds()->vocabulary();

		$this->assertSame('pipelinq', $answer['source']);
		$this->assertSame(['aanvrager', 'gemachtigde'], array_column($answer['kinds'], 'code'));
		$this->assertSame([0, 1], array_keys($answer['kinds']), 'A list, not a map keyed by code: the editor iterates it.');
	}//end testTheVocabularyIsPipelinqsWhenItAnswers()

	/**
	 * A declared acceptance is read back in its declared order.
	 *
	 * @return void
	 */
	public function testTheDeclaredAcceptanceIsReadInOrder(): void {
		$asked = '';
		$this->services[PipelinqGateway::PARTY_KINDS] = new class($asked) {
			/**
			 * @param string $asked Captures the record type.
			 */
			public function __construct(public string &$asked) {
			}

			/**
			 * @param string $recordType The target.
			 *
			 * @return array<int, string>|null The declared codes.
			 */
			public function acceptanceFor(string $recordType): ?array {
				$this->asked = $recordType;

				return ['gemachtigde', 'aanvrager'];
			}
		};

		$this->assertSame(['gemachtigde', 'aanvrager'], $this->kinds()->acceptanceOf(caseType: 'ct-1'));
		$this->assertSame('dossiq:case:ct-1', $asked);
	}//end testTheDeclaredAcceptanceIsReadInOrder()

	/**
	 * Nothing declared, and no pipelinq, are both null rather than "accepts nothing".
	 *
	 * @return void
	 */
	public function testNoDeclarationIsNullNotEmpty(): void {
		$this->assertNull($this->kinds()->acceptanceOf(caseType: 'ct-1'), 'An absent pipelinq is not an empty acceptance.');

		$this->services[PipelinqGateway::PARTY_KINDS] = new class {
			/**
			 * @param string $recordType The target.
			 *
			 * @return array<int, string>|null Nothing declared.
			 */
			public function acceptanceFor(string $recordType): ?array {
				return null;
			}
		};

		$this->assertNull($this->kinds()->acceptanceOf(caseType: 'ct-1'));
	}//end testNoDeclarationIsNullNotEmpty()

	/**
	 * A programme double with pipelinq's real signatures.
	 *
	 * @param array<int, array<string, mixed>> $workItems The work items stored.
	 * @param array<int, array<string, mixed>> $programmes The programmes stored.
	 * @param array<int, array<string, mixed>> $tasks The programme tasks.
	 *
	 * @return object The double.
	 */
	private function portfolio(array $workItems, array $programmes, array $tasks = []): object {
		return new class($workItems, $programmes, $tasks) {
			/** @var array<int, array<string, mixed>> */
			public array $reads = [];

			/**
			 * @param array<int, array<string, mixed>> $workItems Work items.
			 * @param array<int, array<string, mixed>> $programmes Programmes.
			 * @param array<int, array<string, mixed>> $tasks Tasks.
			 */
			public function __construct(private array $workItems, private array $programmes, private array $tasks) {
			}

			/**
			 * @param string $schemaKey The schema config key.
			 * @param array<string, mixed> $filters The filters.
			 *
			 * @return array<int, array<string, mixed>> The rows.
			 */
			public function read(string $schemaKey, array $filters): array {
				$this->reads[] = ['schemaKey' => $schemaKey, 'filters' => $filters];

				if ($schemaKey === 'programmeWorkItem_schema') {
					return array_values(
						array_filter(
							$this->workItems,
							static fn (array $w): bool => $w['domainObjectType'] === ($filters['domainObjectType'] ?? '')
								&& $w['domainObjectRef'] === ($filters['domainObjectRef'] ?? '')
						)
					);
				}

				return $this->programmes;
			}

			/**
			 * @param string $programmeId The programme.
			 *
			 * @return array<int, array<string, mixed>> Its tasks.
			 */
			public function tasksOf(string $programmeId): array {
				return $this->tasks;
			}

			/**
			 * @param array<string, mixed> $programme The programme.
			 * @param array<int, array<string, mixed>> $tasks Its tasks.
			 * @param array<string, mixed>|null $effort Hours.
			 *
			 * @return array<string, mixed> The figure.
			 */
			public function progressFor(array $programme, array $tasks = [], ?array $effort = null): array {
				$closed = count(array_filter($tasks, static fn (array $t): bool => ($t['status'] ?? '') === 'closed'));
				if ($tasks === []) {
					return ['mode' => 'fromTasks', 'progress' => null, 'computable' => false, 'reason' => 'This programme has no tasks to derive progress from.'];
				}

				return ['mode' => 'fromTasks', 'progress' => (int)round($closed / count($tasks) * 100), 'computable' => true, 'reason' => ''];
			}
		};
	}//end portfolio()

	/**
	 * The programme a case hangs under, named, with its progress and mode.
	 *
	 * @return void
	 */
	public function testTheProgrammeOfACaseIsNamedWithItsProgress(): void {
		$portfolio = $this->portfolio(
			workItems: [['programme' => 'p-1', 'domainObjectType' => 'dossiq:case', 'domainObjectRef' => 'case-1']],
			programmes: [['id' => 'p-2', 'name' => 'Omgevingswet invoering'], ['id' => 'p-1', 'name' => 'Lindelaan leefbaar 2026']],
			tasks: [['status' => 'closed'], ['status' => 'open'], ['status' => 'closed'], ['status' => 'open'], ['status' => 'open']],
		);
		$this->services[PipelinqGateway::PROGRAMMES] = $portfolio;

		$consumer = new ProgrammeConsumer($this->gateway(), $this->createMock(LoggerInterface::class));
		$answer = $consumer->programmeOf(caseId: 'case-1');

		$this->assertTrue($answer['available']);
		$this->assertSame('p-1', $answer['programme']['id']);
		$this->assertSame('Lindelaan leefbaar 2026', $answer['programme']['name']);
		$this->assertTrue($answer['programme']['progress']['computable']);
		$this->assertSame(40, $answer['programme']['progress']['progress']);
		$this->assertSame('fromTasks', $answer['programme']['progress']['mode'], 'A figure without its mode is not a figure.');
		$this->assertSame(
			['schemaKey' => 'programmeWorkItem_schema', 'filters' => ['domainObjectType' => 'dossiq:case', 'domainObjectRef' => 'case-1']],
			$portfolio->reads[0],
			'The case is found by reference, named dossiq:case, never by copying it.'
		);
	}//end testTheProgrammeOfACaseIsNamedWithItsProgress()

	/**
	 * An uncomputable figure stays uncomputable on the way to the surface.
	 *
	 * @return void
	 */
	public function testAnUncomputableProgressIsNotZero(): void {
		$this->services[PipelinqGateway::PROGRAMMES] = $this->portfolio(
			workItems: [['programme' => 'p-1', 'domainObjectType' => 'dossiq:case', 'domainObjectRef' => 'case-1']],
			programmes: [['uuid' => 'p-1', 'name' => 'Lindelaan leefbaar 2026']],
		);

		$consumer = new ProgrammeConsumer($this->gateway(), $this->createMock(LoggerInterface::class));
		$progress = $consumer->programmeOf(caseId: 'case-1')['programme']['progress'];

		$this->assertFalse($progress['computable']);
		$this->assertNull($progress['progress'], 'Zero would read as "nothing has been done".');
		$this->assertSame('This programme has no tasks to derive progress from.', $progress['sentence']);
	}//end testAnUncomputableProgressIsNotZero()

	/**
	 * A case under no programme is a real empty; no pipelinq is unavailable.
	 *
	 * @return void
	 */
	public function testNoProgrammeAndNoPipelinqAreDifferentAnswers(): void {
		$consumer = new ProgrammeConsumer($this->gateway(), $this->createMock(LoggerInterface::class));
		$absent = $consumer->programmeOf(caseId: 'case-1');
		$this->assertFalse($absent['available']);
		$this->assertNull($absent['programme']);

		$this->services[PipelinqGateway::PROGRAMMES] = $this->portfolio(workItems: [], programmes: []);
		$consumer = new ProgrammeConsumer($this->gateway(), $this->createMock(LoggerInterface::class));
		$empty = $consumer->programmeOf(caseId: 'case-1');
		$this->assertTrue($empty['available']);
		$this->assertNull($empty['programme']);
	}//end testNoProgrammeAndNoPipelinqAreDifferentAnswers()

	/**
	 * The programmes a handler may pick from, as id and name.
	 *
	 * @return void
	 */
	public function testTheProgrammesAreListedByIdAndName(): void {
		$this->services[PipelinqGateway::PROGRAMMES] = $this->portfolio(
			workItems: [],
			programmes: [['id' => 'p-1', 'name' => 'Lindelaan leefbaar 2026', 'status' => 'active'], ['uuid' => 'p-2', 'name' => 'Omgevingswet invoering'], ['name' => 'no id']],
		);

		$consumer = new ProgrammeConsumer($this->gateway(), $this->createMock(LoggerInterface::class));
		$answer = $consumer->programmes();

		$this->assertTrue($answer['available']);
		$this->assertSame(
			[['id' => 'p-1', 'name' => 'Lindelaan leefbaar 2026'], ['id' => 'p-2', 'name' => 'Omgevingswet invoering']],
			$answer['programmes'],
			'A row without an id cannot be linked to and is not offered.'
		);
	}//end testTheProgrammesAreListedByIdAndName()
}//end class
