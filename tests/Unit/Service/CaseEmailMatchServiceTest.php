<?php

/**
 * Email-to-case matching: what links, what does not, and as whom.
 *
 * The tests that matter most here are the ones asserting that NOTHING happened.
 * A matcher that writes on a miss, runs on an unconfigured register, or links a
 * mail to a case its recipient cannot read reports exactly the same green as
 * one that behaves, unless a test counts the writes. So every refusal below
 * counts them: leaf links, OpenRegister searches, mailbox reads.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use Generator;
use InvalidArgumentException;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\CaseEmailMatchService;
use OCA\Dossiq\Service\Email\MailMessageSource;
use OCA\Dossiq\Service\SettingsService;
use OCP\Config\IUserConfig;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

/**
 * Unit tests for CaseEmailMatchService.
 *
 * @covers \OCA\Dossiq\Service\CaseEmailMatchService
 */
class CaseEmailMatchServiceTest extends TestCase {

	/**
	 * The mailbox owner every run is for.
	 *
	 * @var string
	 */
	private const OWNER = 'alice';

	/**
	 * Alice's Mail account.
	 *
	 * @var int
	 */
	private const ACCOUNT = 7;

	/**
	 * Per-user preferences, by user then key.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $prefs = [];

	/**
	 * App config values the SettingsService answers.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * Case rows OpenRegister holds, by the identifier a search asks for.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $cases = [];

	/**
	 * Messages in Alice's account.
	 *
	 * @var array<int, array{id: int, uid: string, subject: string, preview: string}>
	 */
	private array $mail = [];

	/**
	 * Case uuids the access guard refuses.
	 *
	 * @var array<int, string>
	 */
	private array $unreadable = [];

	/**
	 * Case uuids whose access check throws.
	 *
	 * @var array<int, string>
	 */
	private array $guardThrowsFor = [];

	/**
	 * Whether Alice owns the configured account.
	 *
	 * @var boolean
	 */
	private bool $ownsAccount = true;

	/**
	 * How many times the mailbox was listed.
	 *
	 * @var integer
	 */
	private int $mailboxReads = 0;

	/**
	 * The fake OpenRegister object service.
	 *
	 * @var object
	 */
	private object $objectService;

	/**
	 * The fake email leaf.
	 *
	 * @var object
	 */
	private object $leaf;

	/**
	 * Every log record written.
	 *
	 * @var array<int, array{level: string, message: string}>
	 */
	private array $logs = [];

	/**
	 * Whether the SettingsService was asked for the object service.
	 *
	 * @var boolean
	 */
	private bool $objectServiceRequested = false;

	/**
	 * Set up an instance where matching is on and Alice has opted in.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->config = [
			CaseEmailMatchService::INSTANCE_TOGGLE_KEY => 'yes',
			'register' => '23',
			'case_schema' => '172',
		];
		$this->prefs = [
			self::OWNER => [
				CaseEmailMatchService::PREF_ENABLED => true,
				CaseEmailMatchService::PREF_ACCOUNT => self::ACCOUNT,
				CaseEmailMatchService::PREF_CURSOR => 100,
			],
		];
		$this->cases = [
			'2026-0042' => [$this->caseRow(uuid: 'case-42', identifier: '2026-0042')],
			'2026-0043' => [$this->caseRow(uuid: 'case-43', identifier: '2026-0043')],
		];
		$this->mail = [];
		$this->unreadable = [];
		$this->guardThrowsFor = [];
		$this->ownsAccount = true;
		$this->mailboxReads = 0;
		$this->logs = [];
		$this->objectServiceRequested = false;
		$this->objectService = $this->fakeObjectService(withRunAs: true);
		$this->leaf = $this->fakeLeaf(objectService: $this->objectService);
	}//end setUp()

	/**
	 * A case row as OpenRegister serialises it.
	 *
	 * @param string $uuid       The case uuid.
	 * @param string $identifier The case number.
	 * @param array  $self       Overrides for the `@self` block.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function caseRow(string $uuid, string $identifier, array $self = []): array {
		return [
			'id' => $uuid,
			'identifier' => $identifier,
			'@self' => ($self + ['id' => $uuid, 'register' => '23', 'schema' => '172']),
		];
	}//end caseRow()

	/**
	 * An object service that answers searches from $this->cases and records who asked.
	 *
	 * @param boolean $withRunAs Whether it exposes runAs().
	 *
	 * @return object The fake.
	 */
	private function fakeObjectService(bool $withRunAs): object {
		$cases = &$this->cases;

		if ($withRunAs === false) {
			return new class($cases) {
				/**
				 * Searches made.
				 *
				 * @var array<int, array<string, mixed>>
				 */
				public array $searches = [];

				/**
				 * @param array<string, array<int, array<string, mixed>>> $cases The case rows.
				 */
				public function __construct(private array &$cases) {
				}

				/**
				 * @param array<string, mixed> $query The query.
				 *
				 * @return array<int, array<string, mixed>> The rows.
				 */
				public function searchObjects(array $query): array {
					$this->searches[] = $query;
					return ($this->cases[(string)($query['identifier'] ?? '')] ?? []);
				}
			};
		}//end if

		return new class($cases) {
			/**
			 * Searches made, each with the user it ran as.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $searches = [];

			/**
			 * Objects written. The matcher must never write one.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $writes = [];

			/**
			 * The user a runAs() scope is currently acting as.
			 *
			 * @var string|null
			 */
			public ?string $actingAs = null;

			/**
			 * @param array<string, array<int, array<string, mixed>>> $cases The case rows.
			 */
			public function __construct(private array &$cases) {
			}

			/**
			 * @param array<string, mixed> $query The query.
			 *
			 * @return array<int, array<string, mixed>> The rows.
			 */
			public function searchObjects(array $query): array {
				$this->searches[] = ($query + ['_as' => $this->actingAs]);
				return ($this->cases[(string)($query['identifier'] ?? '')] ?? []);
			}

			/**
			 * @param string               $register The register slug.
			 * @param string               $schema   The schema slug.
			 * @param array<string, mixed> $filters  The filters.
			 *
			 * @return array<int, array<string, mixed>> The rows.
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
				return $this->searchObjects($filters + ['_slug' => $register . '/' . $schema]);
			}

			/**
			 * @param IUser    $user      The user to act as.
			 * @param callable $operation The operation.
			 *
			 * @return mixed What the operation returns.
			 */
			public function runAs(IUser $user, callable $operation): mixed {
				$previous = $this->actingAs;
				$this->actingAs = $user->getUID();
				try {
					return $operation();
				} finally {
					$this->actingAs = $previous;
				}
			}

			/**
			 * Records the write instead of throwing, so a write the matcher
			 * catches and logs is still counted by the test.
			 *
			 * @param array<string, mixed> $object The object.
			 *
			 * @return array<string, mixed> The object.
			 */
			public function saveObject(array $object): array {
				$this->writes[] = $object;
				return $object;
			}
		};
	}//end fakeObjectService()

	/**
	 * An email leaf that records every link and who made it.
	 *
	 * @param object $objectService The object service whose runAs() scope names the actor.
	 *
	 * @return object The fake.
	 */
	private function fakeLeaf(object $objectService): object {
		return new class($objectService) {
			/**
			 * Links made, in order.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $links = [];

			/**
			 * Links that already existed before the run.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $existing = [];

			/**
			 * Case uuids whose link throws.
			 *
			 * @var array<int, string>
			 */
			public array $failFor = [];

			/**
			 * @param object $objectService The object service.
			 */
			public function __construct(private object $objectService) {
			}

			/**
			 * @param string $objectUuid    The case.
			 * @param int    $registerId    The register id.
			 * @param int    $schemaId      The schema id.
			 * @param int    $mailAccountId The account.
			 * @param string $messageId     The message id.
			 * @param string $messageUid    The message uid.
			 *
			 * @return array<string, mixed> The link.
			 */
			public function linkEmail(
				string $objectUuid,
				int $registerId,
				int $schemaId,
				int $mailAccountId,
				string $messageId,
				string $messageUid,
			): array {
				if (in_array($objectUuid, $this->failFor, true) === true) {
					throw new RuntimeException('leaf refused');
				}

				$link = [
					'objectUuid' => $objectUuid,
					'registerId' => $registerId,
					'schemaId' => $schemaId,
					'mailAccountId' => $mailAccountId,
					'mailMessageId' => (int)$messageId,
					'mailMessageUid' => $messageUid,
					'linkedBy' => ($this->objectService->actingAs ?? null),
				];
				$this->links[] = $link;

				return $link;
			}

			/**
			 * @param string      $objectUuid The case.
			 * @param string|null $cursor     The page cursor.
			 * @param int         $limit      The page size.
			 *
			 * @return array{items: array<int, array<string, mixed>>, total: int, nextCursor: null} The page.
			 */
			public function getLinkedEmails(string $objectUuid, ?string $cursor = null, int $limit = 50): array {
				$items = array_values(
					array_filter(
						array_merge($this->existing, $this->links),
						static fn (array $link): bool => $link['objectUuid'] === $objectUuid
					)
				);

				return ['items' => $items, 'total' => count($items), 'nextCursor' => null];
			}
		};
	}//end fakeLeaf()

	/**
	 * The service under test, over the fakes.
	 *
	 * @param boolean $leafPresent Whether the container resolves the email leaf.
	 *
	 * @return CaseEmailMatchService The service.
	 */
	private function service(bool $leafPresent = true): CaseEmailMatchService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			fn (string $key, string $default = ''): string => ($this->config[$key] ?? $default)
		);
		$settings->method('getObjectService')->willReturnCallback(
			function (): object {
				$this->objectServiceRequested = true;
				return $this->objectService;
			}
		);

		$userConfig = $this->createMock(IUserConfig::class);
		$userConfig->method('getValueBool')->willReturnCallback(
			fn (string $uid, string $app, string $key, bool $default = false): bool => (bool)($this->prefs[$uid][$key] ?? $default)
		);
		$userConfig->method('getValueInt')->willReturnCallback(
			fn (string $uid, string $app, string $key, int $default = 0): int => (int)($this->prefs[$uid][$key] ?? $default)
		);
		$userConfig->method('getValueString')->willReturnCallback(
			fn (string $uid, string $app, string $key, string $default = ''): string => (string)($this->prefs[$uid][$key] ?? $default)
		);
		$store = function (string $uid, string $app, string $key, mixed $value): bool {
			$this->prefs[$uid][$key] = $value;
			return true;
		};
		$userConfig->method('setValueBool')->willReturnCallback($store);
		$userConfig->method('setValueInt')->willReturnCallback($store);
		$userConfig->method('setValueString')->willReturnCallback($store);
		$userConfig->method('searchUsersByValueBool')->willReturnCallback(
			function (string $app, string $key, bool $value): Generator {
				foreach ($this->prefs as $uid => $prefs) {
					if (($prefs[$key] ?? null) === $value) {
						yield $uid;
					}
				}
			}
		);

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturnCallback(
			function (string $uid): IUser {
				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn($uid);
				return $user;
			}
		);

		$messages = $this->createMock(MailMessageSource::class);
		$messages->method('ownsAccount')->willReturnCallback(fn (): bool => $this->ownsAccount);
		$messages->method('maxMessageId')->willReturn(500);
		$messages->method('listMessagesSince')->willReturnCallback(
			function (int $accountId, int $sinceId, int $limit): array {
				$this->mailboxReads++;
				return array_values(array_filter($this->mail, static fn (array $m): bool => $m['id'] > $sinceId));
			}
		);

		$guard = $this->createMock(CaseAccessGuard::class);
		$guard->method('hasCaseReadAccess')->willReturnCallback(
			function (string $caseId, IUser $user): bool {
				if (in_array($caseId, $this->guardThrowsFor, true) === true) {
					throw new RuntimeException('guard broke');
				}

				return in_array($caseId, $this->unreadable, true) === false;
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($leafPresent): object {
				if ($leafPresent === false) {
					throw new RuntimeException('no such service');
				}

				return $this->leaf;
			}
		);

		$logs = &$this->logs;
		$logger = new class($logs) extends AbstractLogger {
			/**
			 * @param array<int, array{level: string, message: string}> $logs The log sink.
			 */
			public function __construct(private array &$logs) {
			}

			/**
			 * @param mixed             $level   The level.
			 * @param string|Stringable $message The message.
			 * @param array             $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|Stringable $message, array $context = []): void {
				$this->logs[] = ['level' => (string)$level, 'message' => (string)$message];
			}
		};

		return new CaseEmailMatchService(
			settingsService: $settings,
			userConfig: $userConfig,
			userManager: $userManager,
			messages: $messages,
			caseAccess: $guard,
			container: $container,
			logger: $logger
		);
	}//end service()

	/**
	 * Put one message in Alice's mailbox.
	 *
	 * @param int    $id      The message id.
	 * @param string $subject The subject.
	 * @param string $preview The cached body preview.
	 *
	 * @return void
	 */
	private function receive(int $id, string $subject, string $preview = ''): void {
		$this->mail[] = ['id' => $id, 'uid' => 'imap-' . $id, 'subject' => $subject, 'preview' => $preview];
	}//end receive()

	/**
	 * The case uuids the leaf linked, in order.
	 *
	 * @return array<int, string> The uuids.
	 */
	private function linkedCases(): array {
		return array_column($this->leaf->links, 'objectUuid');
	}//end linkedCases()

	/**
	 * Alice's recorded status error.
	 *
	 * @return string|null The error code.
	 */
	private function statusError(): ?string {
		$status = json_decode((string)($this->prefs[self::OWNER][CaseEmailMatchService::PREF_STATUS] ?? '{}'), true);

		return ($status['error'] ?? null);
	}//end statusError()

	/**
	 * The texts the spec's first scenario names, and the candidates they yield.
	 *
	 * @return array<string, array{0: string, 1: array<int, string>}> The cases.
	 */
	public static function recognisedTexts(): array {
		return [
			'bare' => ['Betreft zaak 2026-0042', ['2026-0042']],
			'bracketed prefixed tag' => ['Re: [ZAAK-2026-000142] aanvulling', ['2026-000142']],
			'prefixed' => ['ZAAK-2026-0042 stukken', ['2026-0042']],
			'bracketed bare' => ['[2026-0042]', ['2026-0042']],
			'two cases' => ['Samenhang 2026-0042 en 2026-0043', ['2026-0042', '2026-0043']],
			'repeated case' => ['2026-0042, nogmaals 2026-0042', ['2026-0042']],
			'end of sentence' => ['Het gaat om 2026-0042.', ['2026-0042']],
		];
	}//end recognisedTexts()

	/**
	 * The default pattern yields exactly the spec's candidates.
	 *
	 * @param string             $text     The text.
	 * @param array<int, string> $expected The candidates.
	 *
	 * @return void
	 */
	#[DataProvider('recognisedTexts')]
	public function testDefaultPatternYieldsTheIdentifierTheSchemaHolds(string $text, array $expected): void {
		$this->assertSame(
			$expected,
			$this->service()->extractCaseNumberCandidates(text: $text, pattern: CaseEmailMatchService::DEFAULT_PATTERN)
		);
	}//end testDefaultPatternYieldsTheIdentifierTheSchemaHolds()

	/**
	 * Texts the boundary guards and the year anchor must keep out.
	 *
	 * @return array<string, array{0: string}> The cases.
	 */
	public static function rejectedTexts(): array {
		return [
			'digit before' => ['12026-0042'],
			'longer run of digits' => ['12026-00421'],
			'letter before' => ['x2026-0042'],
			'trailing segment' => ['2026-0042-01'],
			'letters after' => ['2026-0042abc'],
			'too few sequence digits' => ['2026-004'],
			'too many sequence digits' => ['2026-0042123'],
			'year outside 19xx or 20xx' => ['1899-0042'],
			'phone number fragment' => ['bel 020-2026-0042'],
			'lowercase prefix' => ['zaak-2026-0042'],
			'prefix without a hyphen' => ['ZAAK2026-0042'],
			'no case number' => ['Re: onze afspraak van dinsdag'],
		];
	}//end rejectedTexts()

	/**
	 * The boundary-guarded negatives yield nothing.
	 *
	 * @param string $text The text.
	 *
	 * @return void
	 */
	#[DataProvider('rejectedTexts')]
	public function testDefaultPatternRefusesLookalikes(string $text): void {
		$this->assertSame(
			[],
			$this->service()->extractCaseNumberCandidates(text: $text, pattern: CaseEmailMatchService::DEFAULT_PATTERN)
		);
	}//end testDefaultPatternRefusesLookalikes()

	/**
	 * Patterns that must be refused, and patterns that must be accepted.
	 *
	 * @return array<string, array{0: string, 1: boolean}> Pattern, and whether it is usable.
	 */
	public static function patterns(): array {
		return [
			'the default' => [CaseEmailMatchService::DEFAULT_PATTERN, true],
			'a named group' => ['/(?<nummer>\d{4}-\d{4})/', true],
			'bracket delimiters' => ['{(\d{4}-\d{4})}', true],
			'empty' => ['', false],
			'does not compile' => ['/(\d{4}/', false],
			'no delimiters' => ['(\d{4}-\d{4})', false],
			'no capture group' => ['/\d{4}-\d{4}/', false],
			'only non-capturing groups' => ['/(?:\d{4})-(?:\d{4})/', false],
			'only a lookbehind' => ['/(?<![\w-])\d{4}-\d{4}/', false],
		];
	}//end patterns()

	/**
	 * Validation accepts a compiling pattern with a capture group and nothing else.
	 *
	 * @param string  $pattern The pattern.
	 * @param boolean $usable  Whether it is usable.
	 *
	 * @return void
	 */
	#[DataProvider('patterns')]
	public function testPatternValidation(string $pattern, bool $usable): void {
		$reason = $this->service()->validatePattern(pattern: $pattern);

		if ($usable === true) {
			$this->assertNull($reason);
			return;
		}

		$this->assertIsString($reason);
	}//end testPatternValidation()

	/**
	 * An invalid configured pattern refuses the run: no mailbox read, no link.
	 *
	 * @return void
	 */
	public function testAnInvalidConfiguredPatternRefusesTheRun(): void {
		$this->config[CaseEmailMatchService::PATTERN_KEY] = '/(\d{4}/';
		$this->receive(id: 101, subject: 'Aanvulling zaak 2026-0042');

		$result = $this->service()->runForUser(userId: self::OWNER);

		$this->assertSame(['linked' => 0, 'scanned' => 0], $result);
		$this->assertSame(0, $this->mailboxReads);
		$this->assertSame([], $this->leaf->links);
		$this->assertSame('pattern_invalid', $this->statusError());
		$this->assertContains('error', array_column($this->logs, 'level'));
	}//end testAnInvalidConfiguredPatternRefusesTheRun()

	/**
	 * A case number in the subject links, and the body preview is not consulted.
	 *
	 * @return void
	 */
	public function testASubjectMatchLinksWithoutReadingTheBody(): void {
		$this->receive(id: 101, subject: 'Aanvulling zaak 2026-0042', preview: 'zie ook 2026-0043');

		$result = $this->service()->runForUser(userId: self::OWNER);

		$this->assertSame(['case-42'], $this->linkedCases());
		$this->assertSame(['linked' => 1, 'scanned' => 1], $result);
	}//end testASubjectMatchLinksWithoutReadingTheBody()

	/**
	 * A case number only in the body still links.
	 *
	 * @return void
	 */
	public function testACaseNumberOnlyInTheBodyStillLinks(): void {
		$this->receive(id: 101, subject: 'Re: onze afspraak', preview: 'dit betreft zaak 2026-0042');

		$this->service()->runForUser(userId: self::OWNER);

		$this->assertSame(['case-42'], $this->linkedCases());
	}//end testACaseNumberOnlyInTheBodyStillLinks()

	/**
	 * A subject candidate that resolves to nothing falls through to the body.
	 *
	 * @return void
	 */
	public function testAnUnresolvedSubjectCandidateFallsThroughToTheBody(): void {
		$this->receive(id: 101, subject: 'Factuur 2099-9999', preview: 'over zaak 2026-0043');

		$this->service()->runForUser(userId: self::OWNER);

		$this->assertSame(['case-43'], $this->linkedCases());
	}//end testAnUnresolvedSubjectCandidateFallsThroughToTheBody()

	/**
	 * A mail naming two cases is linked to both.
	 *
	 * @return void
	 */
	public function testAMailNamingTwoCasesIsLinkedToBoth(): void {
		$this->receive(id: 101, subject: 'Samenhang 2026-0042 en 2026-0043');

		$result = $this->service()->runForUser(userId: self::OWNER);

		$this->assertSame(['case-42', 'case-43'], $this->linkedCases());
		$this->assertSame(2, $result['linked']);
	}//end testAMailNamingTwoCasesIsLinkedToBoth()

	/**
	 * One case refusing its link does not stop the other.
	 *
	 * @return void
	 */
	public function testOneRefusedLinkDoesNotStopTheOther(): void {
		$this->leaf->failFor = ['case-42'];
		$this->receive(id: 101, subject: 'Samenhang 2026-0042 en 2026-0043');

		$result = $this->service()->runForUser(userId: self::OWNER);

		$this->assertSame(['case-43'], $this->linkedCases());
		$this->assertSame(1, $result['linked']);
	}//end testOneRefusedLinkDoesNotStopTheOther()

	/**
	 * A message already linked to its case is not linked again, and is not counted.
	 *
	 * @return void
	 */
	public function testAnAlreadyLinkedMailIsNotLinkedTwice(): void {
		$this->leaf->existing = [['objectUuid' => 'case-42', 'mailAccountId' => self::ACCOUNT, 'mailMessageId' => 101]];
		$this->receive(id: 101, subject: 'Aanvulling zaak 2026-0042');

		$result = $this->service()->runForUser(userId: self::OWNER);

		$this->assertSame([], $this->leaf->links);
		$this->assertSame(0, $result['linked']);
	}//end testAnAlreadyLinkedMailIsNotLinkedTwice()

	/**
	 * Reprocessing a message after a cursor reset makes no new link.
	 *
	 * @return void
	 */
	public function testARerunOverTheSameMailMakesNoNewLinks(): void {
		$this->receive(id: 101, subject: 'Aanvulling zaak 2026-0042');
		$service = $this->service();

		$service->runForUser(userId: self::OWNER);
		$this->prefs[self::OWNER][CaseEmailMatchService::PREF_CURSOR] = 100;
		$second = $service->runForUser(userId: self::OWNER);

		$this->assertCount(1, $this->leaf->links);
		$this->assertSame(['linked' => 0, 'scanned' => 1], $second);
	}//end testARerunOverTheSameMailMakesNoNewLinks()

	/**
	 * A mail with no case number causes no write and no OpenRegister search at all.
	 *
	 * @return void
	 */
	public function testAMailWithoutACaseNumberIsLeftAlone(): void {
		$this->receive(id: 101, subject: 'Re: onze afspraak', preview: 'tot dinsdag');

		$result = $this->service()->runForUser(userId: self::OWNER);

		$this->assertSame([], $this->leaf->links);
		$this->assertSame([], $this->objectService->searches);
		$this->assertSame([], $this->objectService->writes, 'a mail without a case number created something');
		$this->assertSame(['linked' => 0, 'scanned' => 1], $result);
	}//end testAMailWithoutACaseNumberIsLeftAlone()

	/**
	 * A candidate that resolves to no case is skipped with no write.
	 *
	 * @return void
	 */
	public function testACandidateThatResolvesToNoCaseIsSkipped(): void {
		$this->receive(id: 101, subject: 'Zaak 2099-9999');

		$this->service()->runForUser(userId: self::OWNER);

		$this->assertSame([], $this->leaf->links);
		$this->assertSame([], $this->objectService->writes, 'an unresolvable case number created something');
		$this->assertCount(1, $this->objectService->searches);
		$this->assertSame('2099-9999', $this->objectService->searches[0]['identifier']);
	}//end testACandidateThatResolvesToNoCaseIsSkipped()

	/**
	 * An unconfigured register refuses before any OpenRegister call, with one warning.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredRegisterRefusesBeforeAnyOpenRegisterCall(): void {
		$this->config['register'] = '';
		$this->receive(id: 101, subject: 'Aanvulling zaak 2026-0042');

		$result = $this->service()->runForUser(userId: self::OWNER);

		$this->assertSame(['linked' => 0, 'scanned' => 0], $result);
		$this->assertFalse($this->objectServiceRequested, 'OpenRegister was reached with no register configured');
		$this->assertSame([], $this->objectService->searches);
		$this->assertSame([], $this->leaf->links);
		$this->assertSame(0, $this->mailboxReads);
		$this->assertSame('register_unconfigured', $this->statusError());
		$this->assertCount(1, array_filter($this->logs, static fn (array $log): bool => $log['level'] === 'warning'));
	}//end testAnUnconfiguredRegisterRefusesBeforeAnyOpenRegisterCall()

	/**
	 * With the instance toggle off, nothing is read.
	 *
	 * @return void
	 */
	public function testTheInstanceToggleOffStopsEverything(): void {
		$this->config[CaseEmailMatchService::INSTANCE_TOGGLE_KEY] = 'no';
		$this->receive(id: 101, subject: 'Aanvulling zaak 2026-0042');

		$result = $this->service()->runForUser(userId: self::OWNER);

		$this->assertSame(['linked' => 0, 'scanned' => 0], $result);
		$this->assertSame(0, $this->mailboxReads);
		$this->assertSame([], $this->leaf->links);
	}//end testTheInstanceToggleOffStopsEverything()

	/**
	 * The instance toggle is off unless it says yes.
	 *
	 * @return void
	 */
	public function testTheInstanceToggleDefaultsToOff(): void {
		unset($this->config[CaseEmailMatchService::INSTANCE_TOGGLE_KEY]);

		$this->assertFalse($this->service()->isInstanceEnabled());
	}//end testTheInstanceToggleDefaultsToOff()

	/**
	 * A user who has not opted in is not scanned, and is not offered to the job.
	 *
	 * @return void
	 */
	public function testAUserWhoHasNotOptedInIsNotScanned(): void {
		$this->prefs['bob'] = [
			CaseEmailMatchService::PREF_ENABLED => false,
			CaseEmailMatchService::PREF_ACCOUNT => 9,
			CaseEmailMatchService::PREF_CURSOR => 100,
		];
		$this->receive(id: 101, subject: 'Aanvulling zaak 2026-0042');
		$service = $this->service();

		$result = $service->runForUser(userId: 'bob');

		$this->assertSame(['linked' => 0, 'scanned' => 0], $result);
		$this->assertSame(0, $this->mailboxReads);
		$this->assertSame([self::OWNER], iterator_to_array($service->optedInUsers(), false));
	}//end testAUserWhoHasNotOptedInIsNotScanned()

	/**
	 * One message that throws does not stop the batch, and is logged.
	 *
	 * @return void
	 */
	public function testOnePoisonedMessageDoesNotStopTheBatch(): void {
		$this->guardThrowsFor = ['case-43'];
		$this->cases['2026-0044'] = [$this->caseRow(uuid: 'case-44', identifier: '2026-0044')];
		$this->receive(id: 101, subject: 'Zaak 2026-0042');
		$this->receive(id: 102, subject: 'Zaak 2026-0043');
		$this->receive(id: 103, subject: 'Zaak 2026-0044');

		$result = $this->service()->runForUser(userId: self::OWNER);

		$this->assertSame(['case-42', 'case-44'], $this->linkedCases());
		$this->assertSame(['linked' => 2, 'scanned' => 3], $result);
		$this->assertSame(103, $this->prefs[self::OWNER][CaseEmailMatchService::PREF_CURSOR]);
		$this->assertContains('warning', array_column($this->logs, 'level'));
	}//end testOnePoisonedMessageDoesNotStopTheBatch()

	/**
	 * The cursor advances past what was processed, and the next run starts there.
	 *
	 * @return void
	 */
	public function testTheCursorAdvancesPastTheProcessedMail(): void {
		$this->receive(id: 101, subject: 'Zaak 2026-0042');
		$this->receive(id: 104, subject: 'Hallo');

		$this->service()->runForUser(userId: self::OWNER);

		$this->assertSame(104, $this->prefs[self::OWNER][CaseEmailMatchService::PREF_CURSOR]);
	}//end testTheCursorAdvancesPastTheProcessedMail()

	/**
	 * A first run starts at the account's newest message and links nothing old.
	 *
	 * @return void
	 */
	public function testAFirstRunStartsAtTheNewestMessage(): void {
		unset($this->prefs[self::OWNER][CaseEmailMatchService::PREF_CURSOR]);
		$this->receive(id: 101, subject: 'Zaak 2026-0042');

		$result = $this->service()->runForUser(userId: self::OWNER);

		$this->assertSame(['linked' => 0, 'scanned' => 0], $result);
		$this->assertSame(0, $this->mailboxReads);
		$this->assertSame([], $this->leaf->links);
		$this->assertSame(500, $this->prefs[self::OWNER][CaseEmailMatchService::PREF_CURSOR]);
	}//end testAFirstRunStartsAtTheNewestMessage()

	/**
	 * An account id that is not the user's own refuses the run before anything is read.
	 *
	 * @return void
	 */
	public function testSomebodyElsesAccountIsNeverScanned(): void {
		$this->ownsAccount = false;
		$this->receive(id: 101, subject: 'Zaak 2026-0042');

		$result = $this->service()->runForUser(userId: self::OWNER);

		$this->assertSame(['linked' => 0, 'scanned' => 0], $result);
		$this->assertSame(0, $this->mailboxReads);
		$this->assertFalse($this->objectServiceRequested);
		$this->assertSame([], $this->leaf->links);
		$this->assertSame('account_not_owned', $this->statusError());
	}//end testSomebodyElsesAccountIsNeverScanned()

	/**
	 * A case the mailbox owner may not read is not linked, whatever the subject says.
	 *
	 * @return void
	 */
	public function testACaseTheOwnerMayNotReadIsNotLinked(): void {
		$this->unreadable = ['case-42'];
		$this->receive(id: 101, subject: 'Zaak 2026-0042');

		$this->service()->runForUser(userId: self::OWNER);

		$this->assertSame([], $this->leaf->links);
	}//end testACaseTheOwnerMayNotReadIsNotLinked()

	/**
	 * A case number that resolves to two visible cases links to neither.
	 *
	 * @return void
	 */
	public function testACaseNumberThatResolvesTwiceLinksToNeither(): void {
		$this->cases['2026-0042'][] = $this->caseRow(uuid: 'case-42-other-org', identifier: '2026-0042');
		$this->receive(id: 101, subject: 'Zaak 2026-0042');

		$this->service()->runForUser(userId: self::OWNER);

		$this->assertSame([], $this->leaf->links);
	}//end testACaseNumberThatResolvesTwiceLinksToNeither()

	/**
	 * A search row whose identifier is not exactly the candidate is not a match.
	 *
	 * @return void
	 */
	public function testALooseSearchHitIsNotALink(): void {
		$this->cases['2026-0042'] = [$this->caseRow(uuid: 'case-420', identifier: '2026-00420')];
		$this->receive(id: 101, subject: 'Zaak 2026-0042');

		$this->service()->runForUser(userId: self::OWNER);

		$this->assertSame([], $this->leaf->links);
	}//end testALooseSearchHitIsNotALink()

	/**
	 * Resolution and linking both run as the mailbox owner.
	 *
	 * @return void
	 */
	public function testCasesAreResolvedAndLinkedAsTheMailboxOwner(): void {
		$this->receive(id: 101, subject: 'Zaak 2026-0042');

		$this->service()->runForUser(userId: self::OWNER);

		$this->assertSame(self::OWNER, $this->objectService->searches[0]['_as']);
		$this->assertSame(self::OWNER, $this->leaf->links[0]['linkedBy']);
		$this->assertNull($this->objectService->actingAs, 'the owner identity leaked out of the run');
	}//end testCasesAreResolvedAndLinkedAsTheMailboxOwner()

	/**
	 * The link carries the case's numeric register and schema, the account and the message.
	 *
	 * @return void
	 */
	public function testTheLinkNamesTheCaseTheAccountAndTheMessage(): void {
		$this->receive(id: 101, subject: 'Zaak 2026-0042');

		$this->service()->runForUser(userId: self::OWNER);

		$link = $this->leaf->links[0];
		$this->assertSame(
			['case-42', 23, 172, self::ACCOUNT, 101, 'imap-101'],
			[$link['objectUuid'], $link['registerId'], $link['schemaId'], $link['mailAccountId'], $link['mailMessageId'], $link['mailMessageUid']]
		);
	}//end testTheLinkNamesTheCaseTheAccountAndTheMessage()

	/**
	 * Without runAs() there is no way to scope to the owner, so nothing runs.
	 *
	 * @return void
	 */
	public function testWithoutAnOwnerScopeNothingIsResolved(): void {
		$this->objectService = $this->fakeObjectService(withRunAs: false);
		$this->receive(id: 101, subject: 'Zaak 2026-0042');

		$result = $this->service()->runForUser(userId: self::OWNER);

		$this->assertSame(['linked' => 0, 'scanned' => 0], $result);
		$this->assertSame([], $this->objectService->searches);
		$this->assertSame([], $this->leaf->links);
		$this->assertSame('scope_unavailable', $this->statusError());
	}//end testWithoutAnOwnerScopeNothingIsResolved()

	/**
	 * Without the email leaf nothing is linked and the run says why.
	 *
	 * @return void
	 */
	public function testWithoutTheEmailLeafNothingIsLinked(): void {
		$this->receive(id: 101, subject: 'Zaak 2026-0042');

		$this->service(leafPresent: false)->runForUser(userId: self::OWNER);

		$this->assertSame([], $this->leaf->links);
		$this->assertSame(0, $this->mailboxReads);
		$this->assertSame('email_leaf_unavailable', $this->statusError());
	}//end testWithoutTheEmailLeafNothingIsLinked()

	/**
	 * A slug register is never cast to id 0; the row's own numeric id is used instead.
	 *
	 * @return void
	 */
	public function testASlugRegisterIsNeverCastToZero(): void {
		$this->config['register'] = 'dossiq';
		$this->config['case_schema'] = 'case';
		$this->cases['2026-0042'] = [$this->caseRow(uuid: 'case-42', identifier: '2026-0042', self: ['register' => null, 'schema' => null])];
		$this->cases['2026-0043'] = [$this->caseRow(uuid: 'case-43', identifier: '2026-0043')];
		$this->receive(id: 101, subject: 'Zaak 2026-0042 en 2026-0043');

		$this->service()->runForUser(userId: self::OWNER);

		$this->assertSame(['case-43'], $this->linkedCases());
		$this->assertSame(23, $this->leaf->links[0]['registerId']);
	}//end testASlugRegisterIsNeverCastToZero()

	/**
	 * Saving somebody else's account is refused and stores nothing.
	 *
	 * @return void
	 */
	public function testSavingSomebodyElsesAccountIsRefused(): void {
		$this->ownsAccount = false;
		$this->prefs = [];

		try {
			$this->service()->saveUserSettings(userId: self::OWNER, enabled: true, account: 99);
			$this->fail('A foreign account was accepted.');
		} catch (InvalidArgumentException $e) {
			$this->assertSame([], $this->prefs);
		}
	}//end testSavingSomebodyElsesAccountIsRefused()

	/**
	 * Switching matching on starts the cursor at the account's newest message.
	 *
	 * @return void
	 */
	public function testSwitchingMatchingOnStartsAtTheNewestMessage(): void {
		$this->prefs = [];

		$saved = $this->service()->saveUserSettings(userId: self::OWNER, enabled: true, account: self::ACCOUNT);

		$this->assertSame(['enabled' => true, 'account' => self::ACCOUNT], $saved);
		$this->assertSame(500, $this->prefs[self::OWNER][CaseEmailMatchService::PREF_CURSOR]);
	}//end testSwitchingMatchingOnStartsAtTheNewestMessage()

	/**
	 * Saving unchanged settings does not move the cursor.
	 *
	 * @return void
	 */
	public function testSavingUnchangedSettingsKeepsTheCursor(): void {
		$this->service()->saveUserSettings(userId: self::OWNER, enabled: true, account: self::ACCOUNT);

		$this->assertSame(100, $this->prefs[self::OWNER][CaseEmailMatchService::PREF_CURSOR]);
	}//end testSavingUnchangedSettingsKeepsTheCursor()
}//end class
