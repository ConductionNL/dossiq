<?php

/**
 * CaseChangesFeed and CaseChangesController tests: a board hears what others
 * changed since it last asked, and never about a case the reader may not read.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Board
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Board;

use DateTimeImmutable;
use OCA\Dossiq\Controller\CaseChangesController;
use OCA\Dossiq\Service\Board\CaseChangesFeed;
use OCA\Dossiq\Service\SettingsService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for CaseChangesFeed and CaseChangesController.
 *
 * @covers \OCA\Dossiq\Service\Board\CaseChangesFeed
 * @covers \OCA\Dossiq\Controller\CaseChangesController
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 *
 * @spec openspec/specs/realtime-updates-ui/spec.md
 */
class CaseChangesFeedTest extends TestCase {

	/**
	 * Others' changes after the moment come back once per case, newest first;
	 * the reader's own, older ones, unreadable cases and other case types do not.
	 *
	 * @return void
	 */
	public function testOthersChangesOnReadableCasesInScope(): void {
		$entries = [
			$this->entry(uuid: 'a5', caseId: 'c1', user: 'anouk', name: 'Anouk Bakker', at: '2026-11-05T10:05:00+00:00'),
			$this->entry(uuid: 'a4', caseId: 'c1', user: 'piet', name: 'Piet', at: '2026-11-05T10:04:00+00:00'),
			$this->entry(uuid: 'a3', caseId: 'c2', user: 'reader', name: 'Reader', at: '2026-11-05T10:03:00+00:00'),
			$this->entry(uuid: 'a2', caseId: 'secret', user: 'piet', name: 'Piet', at: '2026-11-05T10:02:00+00:00'),
			$this->entry(uuid: 'a1', caseId: 'c3', user: 'piet', name: 'Piet', at: '2026-11-05T10:01:00+00:00', action: 'create'),
			$this->entry(uuid: 'a0', caseId: 'c4', user: 'piet', name: 'Piet', at: '2026-11-05T10:01:00+00:00'),
			$this->entry(uuid: 'old', caseId: 'c5', user: 'piet', name: 'Piet', at: '2026-11-05T09:00:00+00:00'),
		];
		$cases = [
			'c1' => ['caseType' => 'woo'],
			'c2' => ['caseType' => 'woo'],
			'c3' => ['caseType' => ['id' => 'woo']],
			'c4' => ['caseType' => 'vth'],
			'c5' => ['caseType' => 'woo'],
		];

		$answer = $this->feed(entries: $entries, cases: $cases)->since(
			readerId: 'reader',
			since: new DateTimeImmutable('2026-11-05T10:00:00+00:00'),
			scope: ['caseType' => 'woo']
		);

		$this->assertSame(expected: ['c1', 'c3'], actual: array_column($answer['changes'], 'objectId'));
		$this->assertSame(
			expected: [
				'id' => 'a5',
				'actor' => 'Anouk Bakker',
				'actorId' => 'anouk',
				'text' => 'Updated by Anouk Bakker',
				'objectId' => 'c1',
				'at' => '2026-11-05T10:05:00+00:00',
			],
			actual: $answer['changes'][0]
		);
		$this->assertSame(expected: 'Created by Piet', actual: $answer['changes'][1]['text']);
	}//end testOthersChangesOnReadableCasesInScope()

	/**
	 * Without OpenRegister's audit trail the board hears nothing, rather than an error.
	 *
	 * @return void
	 */
	public function testNoAuditTrailMeansNoChanges(): void {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getOpenRegisterClass')->willReturn(null);

		$answer = (new CaseChangesFeed(settingsService: $settings, l10n: $this->l10n()))->since(
			readerId: 'reader',
			since: new DateTimeImmutable(),
			scope: []
		);

		$this->assertSame(expected: [], actual: $answer['changes']);
	}//end testNoAuditTrailMeansNoChanges()

	/**
	 * The route refuses an anonymous caller and a moment it cannot read before reading anything.
	 *
	 * @return void
	 */
	public function testTheRouteRefusesBeforeItReads(): void {
		$feed = $this->createMock(originalClassName: CaseChangesFeed::class);
		$feed->expects($this->never())->method('since');

		$this->assertSame(
			expected: Http::STATUS_UNAUTHORIZED,
			actual: $this->controller(feed: $feed, uid: null)->index(since: '2026-11-05T10:00:00Z')->getStatus()
		);
		$this->assertSame(
			expected: Http::STATUS_BAD_REQUEST,
			actual: $this->controller(feed: $feed, uid: 'reader')->index(since: 'yesterday-ish')->getStatus()
		);
	}//end testTheRouteRefusesBeforeItReads()

	/**
	 * A first poll without a moment reads nothing and learns the moment to ask from next.
	 *
	 * @return void
	 */
	public function testAFirstPollLearnsOnlyNow(): void {
		$feed = $this->createMock(originalClassName: CaseChangesFeed::class);
		$feed->expects($this->never())->method('since');

		$response = $this->controller(feed: $feed, uid: 'reader')->index();
		$body     = $response->getData();

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: [], actual: $body['changes']);
		$this->assertNotFalse(condition: date_create_immutable(datetime: $body['now']));
	}//end testAFirstPollLearnsOnlyNow()

	/**
	 * The route asks for the reader's own view, never further back than a day.
	 *
	 * @return void
	 */
	public function testTheRouteAsksForTheReaderWithinADay(): void {
		$feed = $this->createMock(originalClassName: CaseChangesFeed::class);
		$feed->expects($this->once())->method('since')->with(
			'reader',
			$this->callback(callback: static fn (DateTimeImmutable $since): bool => $since > new DateTimeImmutable('-25 hours')),
			['caseType' => 'woo', 'assignedGroup' => '']
		)->willReturn(['changes' => [], 'now' => 'x']);

		$response = $this->controller(feed: $feed, uid: 'reader')->index(since: '2020-01-01T00:00:00Z', caseType: 'woo');

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
	}//end testTheRouteAsksForTheReaderWithinADay()

	/**
	 * A board scoped to a group hears only that group's cases, matched by id or uuid.
	 *
	 * @return void
	 */
	public function testTheGroupScopeMatchesAnIdOrAUuid(): void {
		$entries = [
			$this->entry(uuid: 'e3', caseId: 'c1', user: 'piet', name: 'Piet', at: '2026-11-05T10:03:00+00:00'),
			$this->entry(uuid: 'e2', caseId: 'c2', user: 'piet', name: 'Piet', at: '2026-11-05T10:02:00+00:00'),
			$this->entry(uuid: 'e1', caseId: 'c3', user: 'piet', name: 'Piet', at: '2026-11-05T10:01:00+00:00'),
		];
		$cases = [
			'c1' => ['assignedGroup' => ['uuid' => 'team-a']],
			'c2' => ['assignedGroup' => 'team-b'],
			'c3' => ['assignedGroup' => 'team-a'],
		];

		$answer = $this->feed(entries: $entries, cases: $cases)->since(
			readerId: 'reader',
			since: new DateTimeImmutable('2026-11-05T10:00:00+00:00'),
			scope: ['assignedGroup' => ' team-a ']
		);

		$this->assertSame(expected: ['c1', 'c3'], actual: array_column($answer['changes'], 'objectId'));
	}//end testTheGroupScopeMatchesAnIdOrAUuid()

	/**
	 * An entry without a case id is skipped, and an empty scope lets every readable case through.
	 *
	 * @return void
	 */
	public function testAnEntryWithoutACaseIsSkipped(): void {
		$entries = [
			$this->entry(uuid: 'e2', caseId: '', user: 'piet', name: 'Piet', at: '2026-11-05T10:02:00+00:00'),
			$this->entry(uuid: 'e1', caseId: 'c1', user: 'piet', name: 'Piet', at: '2026-11-05T10:01:00+00:00'),
		];

		$answer = $this->feed(entries: $entries, cases: ['c1' => ['caseType' => 'vth']])->since(
			readerId: 'reader',
			since: new DateTimeImmutable('2026-11-05T10:00:00+00:00'),
			scope: []
		);

		$this->assertSame(expected: ['e1'], actual: array_column($answer['changes'], 'id'));
		$this->assertNotFalse(condition: date_create_immutable(datetime: $answer['now']));
	}//end testAnEntryWithoutACaseIsSkipped()

	/**
	 * One answer names at most fifty cases.
	 *
	 * @return void
	 */
	public function testOneAnswerNamesAtMostFiftyCases(): void {
		$entries = [];
		$cases = [];
		for ($i = 0; $i < 60; $i++) {
			$entries[] = $this->entry(uuid: 'e' . $i, caseId: 'c' . $i, user: 'piet', name: 'Piet', at: '2026-11-05T11:00:00+00:00');
			$cases['c' . $i] = [];
		}

		$answer = $this->feed(entries: $entries, cases: $cases)->since(
			readerId: 'reader',
			since: new DateTimeImmutable('2026-11-05T10:00:00+00:00'),
			scope: []
		);

		$this->assertCount(expectedCount: 50, haystack: $answer['changes']);
		$this->assertSame(expected: 'c49', actual: $answer['changes'][49]['objectId']);
	}//end testOneAnswerNamesAtMostFiftyCases()

	/**
	 * Without a configured case schema the board hears nothing, and the trail is not read.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredSchemaMeansNoChanges(): void {
		$mapper = new class {
			public bool $read = false;

			/**
			 * @return list<object>
			 */
			public function findAll(): array {
				$this->read = true;
				return [];
			}
		};
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getOpenRegisterClass')->willReturn($mapper);
		$settings->method('getObjectService')->willReturn(new \stdClass());
		$settings->method('getConfigValue')->willReturnMap([['register', '', '1'], ['case_schema', '', '']]);

		$answer = (new CaseChangesFeed(settingsService: $settings, l10n: $this->l10n()))->since(
			readerId: 'reader',
			since: new DateTimeImmutable(),
			scope: []
		);

		$this->assertSame(expected: [], actual: $answer['changes']);
		$this->assertFalse(condition: $mapper->read);
	}//end testAnUnconfiguredSchemaMeansNoChanges()

	/**
	 * A failing read answers a generic error, not the cause.
	 *
	 * @return void
	 */
	public function testAFailingReadAnswersAnInternalError(): void {
		$feed = $this->createMock(originalClassName: CaseChangesFeed::class);
		$feed->method('since')->willThrowException(new \RuntimeException('database gone'));

		$response = $this->controller(feed: $feed, uid: 'reader')->index(since: '2026-11-05T10:00:00Z');

		$this->assertSame(expected: Http::STATUS_INTERNAL_SERVER_ERROR, actual: $response->getStatus());
		$this->assertSame(expected: ['message' => 'Internal error'], actual: $response->getData());
	}//end testAFailingReadAnswersAnInternalError()

	/**
	 * The feed over a fake audit trail and object store.
	 *
	 * @param list<object>                        $entries The audit entries, newest first.
	 * @param array<string, array<string, mixed>> $cases   The cases the reader may read.
	 *
	 * @return CaseChangesFeed
	 */
	private function feed(array $entries, array $cases): CaseChangesFeed {
		$mapper = new class($entries) {
			/**
			 * @param list<object> $entries The entries.
			 */
			public function __construct(private array $entries) {
			}

			/**
			 * @param int|null                  $limit   Limit.
			 * @param array<string, mixed>|null $filters Filters.
			 * @param array<string, string>|null $sort   Sort.
			 * @return list<object>
			 */
			public function findAll(?int $limit = null, ?array $filters = [], ?array $sort = []): array {
				return $this->entries;
			}
		};
		$store = new class($cases) {
			/**
			 * @param array<string, array<string, mixed>> $cases The cases.
			 */
			public function __construct(private array $cases) {
			}

			/**
			 * @param string     $id       The id.
			 * @param int|string $register The register.
			 * @param int|string $schema   The schema.
			 * @return array<string, mixed>
			 */
			public function find(string $id, int|string $register, int|string $schema): array {
				if (isset($this->cases[$id]) === false) {
					throw new \OCP\AppFramework\Db\DoesNotExistException(msg: 'not found for this reader');
				}

				return $this->cases[$id];
			}
		};
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getOpenRegisterClass')->willReturn($mapper);
		$settings->method('getObjectService')->willReturn($store);
		$settings->method('getConfigValue')->willReturnMap([['register', '', '1'], ['case_schema', '', '7']]);

		return new CaseChangesFeed(settingsService: $settings, l10n: $this->l10n());
	}//end feed()

	/**
	 * One audit entry.
	 *
	 * @param string $uuid   The entry id.
	 * @param string $caseId The case.
	 * @param string $user   Who.
	 * @param string $name   Their name.
	 * @param string $at     When.
	 * @param string $action What.
	 *
	 * @return object
	 */
	private function entry(string $uuid, string $caseId, string $user, string $name, string $at, string $action = 'update'): object {
		return new class($uuid, $caseId, $user, $name, new DateTimeImmutable($at), $action) {
			/**
			 * @param string            $uuid   Id.
			 * @param string            $caseId Case.
			 * @param string            $user   User.
			 * @param string            $name   Name.
			 * @param DateTimeImmutable $at     When.
			 * @param string            $action Action.
			 */
			public function __construct(
				private string $uuid,
				private string $caseId,
				private string $user,
				private string $name,
				private DateTimeImmutable $at,
				private string $action
			) {
			}

			/** @return string */
			public function getUuid(): string {
				return $this->uuid;
			}

			/** @return string */
			public function getObjectUuid(): string {
				return $this->caseId;
			}

			/** @return string */
			public function getUser(): string {
				return $this->user;
			}

			/** @return string */
			public function getUserName(): string {
				return $this->name;
			}

			/** @return DateTimeImmutable */
			public function getCreated(): DateTimeImmutable {
				return $this->at;
			}

			/** @return string */
			public function getAction(): string {
				return $this->action;
			}
		};
	}//end entry()

	/**
	 * The route over a session.
	 *
	 * @param CaseChangesFeed $feed The feed.
	 * @param string|null     $uid  The reader, or null.
	 *
	 * @return CaseChangesController
	 */
	private function controller(CaseChangesFeed $feed, ?string $uid): CaseChangesController {
		$session = $this->createMock(originalClassName: IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		return new CaseChangesController(
			appName: 'dossiq',
			request: $this->createMock(originalClassName: IRequest::class),
			userSession: $session,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			feed: $feed,
		);
	}//end controller()

	/**
	 * A translator that formats the source text.
	 *
	 * @return IL10N
	 */
	private function l10n(): IL10N {
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));

		return $l10n;
	}//end l10n()
}//end class
