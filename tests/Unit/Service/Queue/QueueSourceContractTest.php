<?php

/**
 * The queue source contract: a name, a per-person read, a subject, a closing.
 *
 * The tests below are about the CONTRACT rather than about any one source,
 * because the contract is what makes an eighth mechanism cheap. Two of them
 * are the reason ADR-102 is cited on this change: a source that cannot be read
 * has to raise, and the queue has to name it, because the alternative is a
 * shorter queue that reads exactly like a quiet day.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Queue
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/one-personal-queue/specs/add-work-queue/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Queue;

use DateTimeImmutable;
use OCA\Dossiq\Service\Queue\PersonalQueueService;
use OCA\Dossiq\Service\Queue\QueueItem;
use OCA\Dossiq\Service\Queue\QueueOrdering;
use OCA\Dossiq\Service\Queue\QueueSource;
use OCA\Dossiq\Service\Queue\QueueSourceCatalogue;
use OCA\Dossiq\Service\Queue\QueueViewPreferences;
use OCA\Dossiq\Service\Task\EngineTaskInbox;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\WorkQueueService;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use OCP\Config\IUserConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A source that answers a fixed list, or throws.
 *
 * Declared as a real class rather than a double with added methods: a double
 * that invents the method it is asked for can only pass.
 */
class FakeQueueSource implements QueueSource {
	/**
	 * Constructor.
	 *
	 * @param string                $name    The source name.
	 * @param array<int, QueueItem> $items   What it answers.
	 * @param string|null           $failure The message it throws instead, or null.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly string $name,
		private readonly array $items = [],
		private readonly ?string $failure = null,
	) {
	}

	/**
	 * The source's name.
	 *
	 * @return string The name.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * What the reader sees above this group.
	 *
	 * @return string The label.
	 */
	public function label(): string {
		return 'Label for ' . $this->name;
	}

	/**
	 * What takes an item off the queue.
	 *
	 * @return string The closing condition.
	 */
	public function closesWhen(): string {
		return 'It leaves when the work behind it is done.';
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
	 *
	 * @throws RuntimeException When this source was built to fail.
	 */
	public function itemsFor(string $userId): array {
		if ($this->failure !== null) {
			throw new RuntimeException($this->failure);
		}

		return $this->items;
	}
}

/**
 * @covers \OCA\Dossiq\Service\Queue\PersonalQueueService
 * @covers \OCA\Dossiq\Service\Queue\QueueItem
 * @covers \OCA\Dossiq\Service\Queue\QueueOrdering
 */
class QueueSourceContractTest extends TestCase {
	use MakesCaseDateNormaliser;

	/**
	 * The preferences this fake user config holds.
	 *
	 * @var array<string, mixed>
	 */
	private array $prefs = [];

	/**
	 * Reset the stored preferences.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->prefs = [];
	}

	/**
	 * A queue service reading exactly these sources.
	 *
	 * @param array<int, QueueSource> $sources The sources.
	 *
	 * @return PersonalQueueService The service.
	 */
	private function queueOf(array $sources): PersonalQueueService {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($sources): object {
				foreach ($sources as $source) {
					if ($source->name() === $id) {
						return $source;
					}
				}

				throw new RuntimeException('Nothing is registered as ' . $id);
			}
		);

		$catalogue = new class($container, $sources) extends QueueSourceCatalogue {
			/**
			 * Constructor.
			 *
			 * @param ContainerInterface      $container The container.
			 * @param array<int, QueueSource> $sources   The sources to declare.
			 *
			 * @return void
			 */
			public function __construct(ContainerInterface $container, private readonly array $sources) {
				parent::__construct(container: $container);
			}

			/**
			 * The declared source names, which this container resolves.
			 *
			 * @return array<int, string> The names.
			 */
			public function declared(): array {
				return array_map(static fn (QueueSource $source): string => $source->name(), $this->sources);
			}

			/**
			 * Resolve the declared sources.
			 *
			 * @return array{sources: array<int, QueueSource>, unavailable: array<int, array<string, string>>} The result.
			 */
			public function resolve(): array {
				return ['sources' => $this->sources, 'unavailable' => []];
			}
		};

		return new PersonalQueueService(
			catalogue: $catalogue,
			ordering: new QueueOrdering(scorer: $this->scorer()),
			preferences: new QueueViewPreferences(
				userConfig: $this->userConfig(),
				logger: $this->createMock(LoggerInterface::class)
			),
			logger: $this->createMock(LoggerInterface::class)
		);
	}

	/**
	 * The real urgency rule, with its own dependencies doubled.
	 *
	 * The scorer is NOT doubled: the point of the ordering is that it is the
	 * same rule the case cards draw, and a doubled scorer would prove nothing
	 * about that.
	 *
	 * @return WorkQueueService The scorer.
	 */
	private function scorer(): WorkQueueService {
		return new WorkQueueService(
			settingsService: $this->createMock(SettingsService::class),
			engineTasks: $this->createMock(EngineTaskInbox::class),
			logger: $this->createMock(LoggerInterface::class),
			dates: $this->caseDates()
		);
	}

	/**
	 * A user config backed by an array.
	 *
	 * @return IUserConfig The config.
	 */
	private function userConfig(): IUserConfig {
		$config = $this->createMock(IUserConfig::class);
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
	}

	/**
	 * An item.
	 *
	 * @param string      $source  The source name.
	 * @param string      $id      The subject id.
	 * @param string|null $dueAt   Its date.
	 * @param string      $prio    Its priority.
	 * @param string|null $covered The colleague it belongs to.
	 *
	 * @return QueueItem The item.
	 */
	private function item(
		string $source,
		string $id,
		?string $dueAt = null,
		string $prio = 'normal',
		?string $covered = null,
	): QueueItem {
		return new QueueItem(
			source: $source,
			subjectType: 'case',
			subjectId: $id,
			title: 'Case ' . $id,
			priority: $prio,
			dueAt: $dueAt,
			coveredFor: $covered
		);
	}

	/**
	 * Every declared source contributes, so one page answers for all of them.
	 *
	 * @return void
	 */
	public function testEveryDeclaredSourceContributes(): void {
		$queue = $this->queueOf([
			new FakeQueueSource('assigned-cases', [$this->item('assigned-cases', 'a')]),
			new FakeQueueSource('tasks', [$this->item('tasks', 'b')]),
			new FakeQueueSource('consultations', [$this->item('consultations', 'c')]),
		])->forPerson(userId: 'alice', now: new DateTimeImmutable('2026-09-15'));

		self::assertSame(3, $queue['total']);

		// Every source is represented, and the groups follow the QUEUE's order
		// rather than the catalogue's: the most pressing work is in the first
		// group, so a reader does not scan four headings to find it.
		$keys = array_column($queue['groups'], 'key');
		sort($keys);
		self::assertSame(['assigned-cases', 'consultations', 'tasks'], $keys);
		self::assertSame(
			$queue['items'][0]['source'],
			$queue['groups'][0]['key'],
			'The first group holds the first item.'
		);
	}

	/**
	 * A source that cannot be read is named, and the rest still answer.
	 *
	 * @return void
	 */
	public function testAnUnreadableSourceIsNamedAndTheRestStillAnswer(): void {
		$queue = $this->queueOf([
			new FakeQueueSource('assigned-cases', [$this->item('assigned-cases', 'a')]),
			new FakeQueueSource('consultations', [], 'The advice register did not answer.'),
		])->forPerson(userId: 'alice', now: new DateTimeImmutable('2026-09-15'));

		self::assertSame(1, $queue['total'], 'The readable source still contributed.');
		self::assertSame(
			[['source' => 'consultations', 'label' => 'Label for consultations', 'reason' => 'The advice register did not answer.']],
			$queue['unavailable']
		);
	}

	/**
	 * An unreadable source is NOT an empty queue.
	 *
	 * The failure this guards is the one worth naming: a source that swallowed
	 * its error would leave a reader looking at a page saying nothing is
	 * waiting on them, on the morning something was.
	 *
	 * @return void
	 */
	public function testAnUnreadableSourceIsNotAnEmptyQueue(): void {
		$queue = $this->queueOf([
			new FakeQueueSource('tasks', [], 'The engine did not answer.'),
		])->forPerson(userId: 'alice', now: new DateTimeImmutable('2026-09-15'));

		self::assertSame(0, $queue['total']);
		self::assertNotSame([], $queue['unavailable'], 'An empty queue with no reason is the failure.');
	}

	/**
	 * The most pressing thing is first, across sources.
	 *
	 * @return void
	 */
	public function testTheNextThingToDoIsFirst(): void {
		$queue = $this->queueOf([
			new FakeQueueSource('assigned-cases', [
				$this->item('assigned-cases', 'later', '2026-12-01'),
				$this->item('assigned-cases', 'undated', null),
			]),
			new FakeQueueSource('tasks', [$this->item('tasks', 'late', '2026-09-01')]),
		])->forPerson(userId: 'alice', now: new DateTimeImmutable('2026-09-15'));

		self::assertSame(
			['tasks:case:late', 'assigned-cases:case:later', 'assigned-cases:case:undated'],
			array_column($queue['items'], 'id')
		);
		self::assertSame('overdue', $queue['items'][0]['tier']);
	}

	/**
	 * An item's id is the same on every read, so a reader keeps their place.
	 *
	 * @return void
	 */
	public function testAnItemsIdentityIsStable(): void {
		$item = $this->item('tasks', 'abc');

		self::assertSame('tasks:case:abc', $item->identity());
		self::assertSame($item->identity(), $this->item('tasks', 'abc')->identity());
	}

	/**
	 * Covered work is marked with whose it is.
	 *
	 * @return void
	 */
	public function testCoveredWorkIsMarked(): void {
		$queue = $this->queueOf([
			new FakeQueueSource('covered-work', [$this->item('covered-work', 'x', null, 'normal', 'bob')]),
		])->forPerson(userId: 'alice', now: new DateTimeImmutable('2026-09-15'));

		self::assertTrue($queue['items'][0]['covered']);
		self::assertSame('bob', $queue['items'][0]['coveredFor']);
	}

	/**
	 * A group the reader hid today is named as hidden, and comes back tomorrow.
	 *
	 * @return void
	 */
	public function testAGroupHiddenTodayComesBackTomorrow(): void {
		$preferences = new QueueViewPreferences(
			userConfig: $this->userConfig(),
			logger: $this->createMock(LoggerInterface::class)
		);

		$preferences->hideForToday(userId: 'alice', group: 'tasks', today: '2026-09-15');

		self::assertSame(['tasks'], $preferences->hiddenGroups(userId: 'alice', today: '2026-09-15'));
		self::assertSame([], $preferences->hiddenGroups(userId: 'alice', today: '2026-09-16'));
	}

	/**
	 * Every source dossiq declares implements the contract.
	 *
	 * @return void
	 */
	public function testEveryDeclaredSourceImplementsTheContract(): void {
		self::assertNotSame([], QueueSourceCatalogue::SOURCES);

		foreach (QueueSourceCatalogue::SOURCES as $class) {
			self::assertTrue(class_exists($class), $class . ' is declared and does not exist.');
			self::assertContains(
				QueueSource::class,
				class_implements($class),
				$class . ' is declared as a queue source and does not implement the contract.'
			);
		}
	}
}//end class
