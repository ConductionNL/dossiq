<?php

/**
 * Work that has closed leaves the queue.
 *
 * `QueueItemLifecycle` has answered "does this item still stand" since the
 * personal queue shipped, with three closing reasons and the vocabularies every
 * mechanism actually uses, and NOTHING ASKED IT. So a case somebody finished,
 * or handed to a colleague, stayed on their work list until they pressed
 * something. A work list that shows work already done is one people stop
 * trusting, and then stop reading.
 *
 * 🔴 THIS SUITE RUNS THE REAL `PersonalQueueService::forPerson()`. The rule is
 * not restated here and compared with itself: every assertion below is about
 * which items came back from the queue the page actually reads. A helper that
 * re-implemented `hasClosed()` would pass whether or not the service called it,
 * which is the one thing this suite exists to establish.
 *
 * 🔴 THE SUBJECT TRAVELS WITH THE ITEM, AND AN ABSENT ONE MEANS "I DID NOT READ
 * ONE", NOT "IT IS GONE". Eight of the ten declared sources pass no subject
 * today, so the day anybody reads an absent subject as a closed one, every one
 * of their items disappears and every source still reports itself available.
 * `testASourceThatPassesNoSubjectKeepsItsItems` is the control that pins it.
 *
 * MUTATION-CHECKED 2026-09-18, and one of the three claims did not hold:
 *   - deleting the `hasClosed` call in `forPerson()` reddens
 *     testAFinishedCaseLeavesTheQueue ("0 => 'done'" appears) and
 *     testACaseHandedOnLeavesTheOldHoldersQueue;
 *   - INVERTING the `$item->subject === []` branch, so an unread subject counts
 *     as closed, reddens testASourceThatPassesNoSubjectKeepsItsItems;
 *   - but DELETING that branch reddens NOTHING, and the note in
 *     `hasClosed()` now says why: `stillStands()` only treats a NULL subject as
 *     withdrawn, so an empty array survives it by coincidence. The branch stays
 *     because that agreement is incidental, and this suite pins the behaviour
 *     whichever way the lifecycle later reads an empty array.
 * Restored after.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Queue
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
 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Queue;

use OCA\Dossiq\Service\Queue\PersonalQueueService;
use OCA\Dossiq\Service\Queue\QueueItem;
use OCA\Dossiq\Service\Queue\QueueOrdering;
use OCA\Dossiq\Service\Queue\QueueSource;
use OCA\Dossiq\Service\Queue\QueueSourceCatalogue;
use OCA\Dossiq\Service\Queue\QueueViewPreferences;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Task\EngineTaskInbox;
use OCA\Dossiq\Service\WorkQueueService;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use OCP\Config\IUserConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * A source answering a fixed list of items.
 *
 * A real class, not a double with methods added to it: a double that invents
 * the method it is asked for can only pass.
 */
class LifecycleFakeSource implements QueueSource {

	/**
	 * Constructor.
	 *
	 * @param array<int, QueueItem> $items What this source answers.
	 *
	 * @return void
	 */
	public function __construct(private readonly array $items) {
	}

	/**
	 * The source's name.
	 *
	 * @return string The name.
	 */
	public function name(): string {
		return 'assignedCases';
	}

	/**
	 * What the reader sees above this group.
	 *
	 * @return string The label.
	 */
	public function label(): string {
		return 'Zaken die op jou wachten';
	}

	/**
	 * What takes an item off this queue.
	 *
	 * @return string The closing condition.
	 */
	public function closesWhen(): string {
		return 'Het verdwijnt zodra de zaak klaar is of iemand anders hem overneemt.';
	}

	/**
	 * The mechanisms this source covers.
	 *
	 * @return array<int, string> The mechanism ids.
	 */
	public function mechanisms(): array {
		return [];
	}

	/**
	 * What is waiting on this person.
	 *
	 * @param string $userId The person.
	 *
	 * @return array<int, QueueItem> The items.
	 */
	public function itemsFor(string $userId): array {
		return $this->items;
	}
}

/**
 * The queue asks the lifecycle, and closed work is gone from what it answers.
 *
 * @covers \OCA\Dossiq\Service\Queue\PersonalQueueService
 * @uses \OCA\Dossiq\Service\Queue\QueueItem
 * @uses \OCA\Dossiq\Service\Queue\QueueItemLifecycle
 * @uses \OCA\Dossiq\Service\Queue\QueueOrdering
 * @uses \OCA\Dossiq\Service\Queue\QueueSourceCatalogue
 * @uses \OCA\Dossiq\Service\Queue\QueueViewPreferences
 * @uses \OCA\Dossiq\Service\WorkQueueService
 * @uses \OCA\Dossiq\Service\CaseDateNormaliser
 *
 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
 */
class QueueItemLifecycleIsConsultedTest extends TestCase {
	use MakesCaseDateNormaliser;

	/**
	 * The preferences the fake user config holds.
	 *
	 * @var array<string, mixed>
	 */
	private array $prefs = [];

	/**
	 * Start with no stored preferences.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->prefs = [];
	}//end setUp()

	/**
	 * A case somebody finished is not on their queue any more.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	public function testAFinishedCaseLeavesTheQueue(): void {
		$queue = $this->queueOver(
			items: [
				$this->item(id: 'open', subject: ['isFinalStatus' => false, 'assignee' => 'jan']),
				$this->item(id: 'done', subject: ['isFinalStatus' => true, 'assignee' => 'jan']),
			]
		)->forPerson(userId: 'jan');

		self::assertSame(
			expected: ['open'],
			actual: $this->subjectIdsOf(queue: $queue),
			message: 'A case Jan already closed is not work waiting on Jan.',
		);
	}//end testAFinishedCaseLeavesTheQueue()

	/**
	 * A case handed to a colleague leaves the queue of the person who had it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	public function testACaseHandedOnLeavesTheOldHoldersQueue(): void {
		$items = [$this->item(id: 'handed-on', subject: ['isFinalStatus' => false, 'assignee' => 'sofie'])];

		self::assertSame(
			expected: [],
			actual: $this->subjectIdsOf(queue: $this->queueOver(items: $items)->forPerson(userId: 'jan')),
			message: 'Jan handed it on, so it is not waiting on Jan.',
		);
		self::assertSame(
			expected: ['handed-on'],
			actual: $this->subjectIdsOf(queue: $this->queueOver(items: $items)->forPerson(userId: 'sofie')),
			message: 'And it is waiting on Sofie, who holds it now.',
		);
	}//end testACaseHandedOnLeavesTheOldHoldersQueue()

	/**
	 * Covered work stays with the person covering it.
	 *
	 * An item picked up through a substitution is assigned to the ABSENT
	 * colleague, so a plain assignee comparison would close every covered item
	 * the instant it appeared, and a fortnight of somebody's holiday cover would
	 * vanish off the stand-in's list.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	public function testCoveredWorkStaysWithTheStandIn(): void {
		$queue = $this->queueOver(
			items: [
				$this->item(
					id: 'covered',
					subject: ['isFinalStatus' => false, 'assignee' => 'sofie'],
					coveredFor: 'sofie',
				),
			]
		)->forPerson(userId: 'jan');

		self::assertSame(expected: ['covered'], actual: $this->subjectIdsOf(queue: $queue));
	}//end testCoveredWorkStaysWithTheStandIn()

	/**
	 * A source that passes no subject keeps its items.
	 *
	 * THE CONTROL THAT MATTERS MOST HERE. Eight of the ten declared sources pass
	 * no subject, so a queue that read an absent subject as a closed one would
	 * drop everything they raise: the work list would go blank and every source
	 * would still report itself available.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	public function testASourceThatPassesNoSubjectKeepsItsItems(): void {
		$queue = $this->queueOver(items: [$this->item(id: 'unread', subject: [])])->forPerson(userId: 'jan');

		self::assertSame(
			expected: ['unread'],
			actual: $this->subjectIdsOf(queue: $queue),
			message: 'An absent subject is "I did not read one", not "it is gone".',
		);
	}//end testASourceThatPassesNoSubjectKeepsItsItems()

	/**
	 * The two widened sources pass the row they have already read.
	 *
	 * The filter is only as real as the subject reaching the item: a queue that
	 * asks and sources that answer nothing is a filter that never fires.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	public function testTheWidenedSourcesPassTheirSubject(): void {
		foreach (['AssignedCasesSource', 'OpenIncidentSource'] as $source) {
			$source = (string)file_get_contents(
				__DIR__ . '/../../../../lib/Service/Queue/Source/' . $source . '.php'
			);

			self::assertMatchesRegularExpression(
				pattern: '/subject:\s*\$/',
				string: $source,
				message: 'The source has already read the row, so it passes it rather than leaving the queue to fetch it again.',
			);
		}
	}//end testTheWidenedSourcesPassTheirSubject()

	/**
	 * The subject ids the queue answered, in its own order.
	 *
	 * @param array<string, mixed> $queue What `forPerson()` returned.
	 *
	 * @return array<int, string> The subject ids.
	 */
	private function subjectIdsOf(array $queue): array {
		return array_map(
			static fn (array $item): string => (string)$item['subjectId'],
			$queue['items'],
		);
	}//end subjectIdsOf()

	/**
	 * One item over a subject.
	 *
	 * @param string               $id         The subject id, which is also what the assertions read.
	 * @param array<string, mixed> $subject    The subject row, or [] when the source read none.
	 * @param string|null          $coveredFor The absent colleague, when this is covered work.
	 *
	 * @return QueueItem The item.
	 */
	private function item(string $id, array $subject, ?string $coveredFor = null): QueueItem {
		return new QueueItem(
			source: 'assignedCases',
			subjectType: 'case',
			subjectId: $id,
			title: 'Dakkapel Prinsengracht 12',
			priority: 'normal',
			dueAt: null,
			coveredFor: $coveredFor,
			route: [],
			waiting: [],
			subject: $subject,
		);
	}//end item()

	/**
	 * The REAL queue service, over one source answering these items.
	 *
	 * The lifecycle is not passed and not doubled: the service builds its own,
	 * which is the production arrangement, and a doubled one would let a service
	 * that decided for itself pass.
	 *
	 * @param array<int, QueueItem> $items What the source answers.
	 *
	 * @return PersonalQueueService The service.
	 */
	private function queueOver(array $items): PersonalQueueService {
		$source = new LifecycleFakeSource(items: $items);

		$catalogue = new class($this->createMock(originalClassName: ContainerInterface::class), $source) extends QueueSourceCatalogue {

			/**
			 * Constructor.
			 *
			 * @param ContainerInterface $container The container.
			 * @param QueueSource        $source    The one source to declare.
			 *
			 * @return void
			 */
			public function __construct(ContainerInterface $container, private readonly QueueSource $source) {
				parent::__construct(container: $container);
			}

			/**
			 * The declared source classes.
			 *
			 * @return array<int, string> The names.
			 */
			public function declared(): array {
				return [$this->source->name()];
			}

			/**
			 * Resolve the declared sources.
			 *
			 * @return array{sources: array<int, QueueSource>, unavailable: array<int, array<string, string>>} The result.
			 */
			public function resolve(): array {
				return ['sources' => [$this->source], 'unavailable' => []];
			}
		};

		return new PersonalQueueService(
			catalogue: $catalogue,
			ordering: new QueueOrdering(scorer: $this->scorer()),
			preferences: new QueueViewPreferences(
				userConfig: $this->userConfig(),
				logger: $this->createMock(originalClassName: LoggerInterface::class),
			),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end queueOver()

	/**
	 * The real urgency rule, with its own dependencies doubled.
	 *
	 * @return WorkQueueService The scorer.
	 */
	private function scorer(): WorkQueueService {
		return new WorkQueueService(
			settingsService: $this->createMock(originalClassName: SettingsService::class),
			engineTasks: $this->createMock(originalClassName: EngineTaskInbox::class),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			dates: $this->caseDates(),
		);
	}//end scorer()

	/**
	 * A user config backed by an array.
	 *
	 * @return IUserConfig The config.
	 */
	private function userConfig(): IUserConfig {
		$config = $this->createMock(originalClassName: IUserConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $uid, string $app, string $key, string $default = ''): string
				=> (string)($this->prefs[$uid . '|' . $key] ?? $default)
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $uid, string $app, string $key, string $value): bool {
				$this->prefs[$uid . '|' . $key] = $value;

				return true;
			}
		);

		return $config;
	}//end userConfig()
}//end class
