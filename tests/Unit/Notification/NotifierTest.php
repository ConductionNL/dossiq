<?php

/**
 * Dossiq Notifier Unit Tests
 *
 * Tests for the Dossiq INotifier implementation that renders the
 * `note_mention` notification (nc-vue #207 @mention → real NC
 * notification) for the bell menu.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ncvue-w2-leaves-adoption/specs/ncvue-w2-leaves-adoption/spec.md#REQ-W2L-003
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Notification;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Notification\Notifier;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\UnknownNotificationException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the Dossiq Notifier class.
 *
 * @covers \OCA\Dossiq\Notification\Notifier
 */
class NotifierTest extends TestCase {

	/**
	 * @var IFactory|\PHPUnit\Framework\MockObject\MockObject
	 */
	private IFactory $l10nFactory;

	/**
	 * @var IURLGenerator|\PHPUnit\Framework\MockObject\MockObject
	 */
	private IURLGenerator $urlGenerator;

	/**
	 * @var IL10N|\PHPUnit\Framework\MockObject\MockObject
	 */
	private IL10N $l10n;

	/**
	 * The notifier under test.
	 *
	 * @var Notifier
	 */
	private Notifier $notifier;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->l10nFactory = $this->createMock(IFactory::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->l10n = $this->createMock(IL10N::class);

		$this->l10nFactory->method('get')->willReturn($this->l10n);
		// Echo the source text back (with sprintf-style substitution) so
		// assertions can check on plain literal English text.
		$this->l10n->method('t')->willReturnCallback(
			static function (string $text, array $params = []): string {
				return $params === [] ? $text : vsprintf(str_replace('%s', '%s', $text), $params);
			}
		);

		$this->urlGenerator->method('imagePath')->willReturn('/img/app-dark.svg');
		$this->urlGenerator->method('getAbsoluteURL')->willReturnCallback(
			static fn (string $path): string => 'https://cloud.example.com' . $path
		);

		$this->notifier = new Notifier($this->l10nFactory, $this->urlGenerator);
	}//end setUp()

	/**
	 * getID returns the app id.
	 *
	 * @return void
	 */
	public function testGetIdReturnsAppId(): void {
		$this->assertSame(Application::APP_ID, $this->notifier->getID());
	}//end testGetIdReturnsAppId()

	/**
	 * A notification from a different app is rejected.
	 *
	 * @return void
	 */
	public function testPrepareRejectsForeignApp(): void {
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('some_other_app');

		$this->expectException(UnknownNotificationException::class);
		$this->notifier->prepare($notification, 'en');
	}//end testPrepareRejectsForeignApp()

	/**
	 * An unknown subject key is rejected.
	 *
	 * @return void
	 */
	public function testPrepareRejectsUnknownSubject(): void {
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn(Application::APP_ID);
		$notification->method('getSubject')->willReturn('some_unknown_subject');

		$this->expectException(UnknownNotificationException::class);
		$this->notifier->prepare($notification, 'en');
	}//end testPrepareRejectsUnknownSubject()

	/**
	 * A note_mention notification is parsed with the actor's display name
	 * in the subject, an absolute icon URL, and a non-empty message.
	 *
	 * @return void
	 */
	public function testPrepareRendersNoteMentionWithActorName(): void {
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn(Application::APP_ID);
		$notification->method('getSubject')->willReturn('note_mention');
		$notification->method('getSubjectParameters')->willReturn(
			[
				'actorUserId' => 'alice',
				'actorDisplayName' => 'Alice',
				'register' => 'dossiq',
				'schema' => 'case',
				'objectId' => 'case-1',
				'noteId' => 'note-9',
			]
		);

		$notification->expects($this->once())
			->method('setParsedSubject')
			->with('Alice mentioned you in a note')
			->willReturn($notification);

		$notification->expects($this->once())
			->method('setParsedMessage')
			->with($this->isType('string'))
			->willReturn($notification);

		// setIcon MUST receive an absolute URL (project convention — a
		// relative imagePath() silently renders no icon in the bell menu).
		$notification->expects($this->once())
			->method('setIcon')
			->with($this->stringStartsWith('https://'))
			->willReturn($notification);

		$result = $this->notifier->prepare($notification, 'en');
		$this->assertSame($notification, $result);
	}//end testPrepareRendersNoteMentionWithActorName()

	/**
	 * When the actor display name is missing, the subject falls back to
	 * the generic wording (never renders an empty "%s mentioned…").
	 *
	 * @return void
	 */
	public function testPrepareFallsBackWhenActorNameMissing(): void {
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn(Application::APP_ID);
		$notification->method('getSubject')->willReturn('note_mention');
		$notification->method('getSubjectParameters')->willReturn(
			[
				'actorUserId' => 'alice',
				'objectId' => 'case-1',
			]
		);

		$notification->expects($this->once())
			->method('setParsedSubject')
			->with('You were mentioned in a note')
			->willReturn($notification);
		$notification->method('setParsedMessage')->willReturn($notification);
		$notification->method('setIcon')->willReturn($notification);

		$this->notifier->prepare($notification, 'en');
	}//end testPrepareFallsBackWhenActorNameMissing()

	/**
	 * The subject a `notify` transition action dispatches is rendered, and
	 * rendered as itself rather than as mention wording.
	 *
	 * Without this the notification is refused in `prepare()` and dropped
	 * before the recipient ever sees it, which is the same silent no-op one
	 * layer along.
	 *
	 * @return void
	 */
	public function testPrepareRendersACaseStatusChange(): void {
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn(Application::APP_ID);
		$notification->method('getSubject')->willReturn(Notifier::SUBJECT_CASE_STATUS_CHANGED);
		$notification->method('getSubjectParameters')->willReturn(
			[
				'caseId' => 'case-1',
				'transitionLabel' => 'In behandeling',
				'message' => 'Pak deze zaak op.',
			]
		);

		$notification->expects($this->once())
			->method('setParsedSubject')
			->with('A case you handle changed status: In behandeling')
			->willReturn($notification);
		$notification->expects($this->once())
			->method('setParsedMessage')
			->with('Pak deze zaak op.')
			->willReturn($notification);
		$notification->method('setIcon')->willReturn($notification);

		$this->notifier->prepare($notification, 'en');
	}//end testPrepareRendersACaseStatusChange()

	/**
	 * Without a configured message the recipient still gets the next step.
	 *
	 * @return void
	 */
	public function testPrepareFallsBackWhenTheTransitionCarriesNoText(): void {
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn(Application::APP_ID);
		$notification->method('getSubject')->willReturn(Notifier::SUBJECT_CASE_STATUS_CHANGED);
		$notification->method('getSubjectParameters')->willReturn(['caseId' => 'case-1']);

		$notification->expects($this->once())
			->method('setParsedSubject')
			->with('A case you handle changed status')
			->willReturn($notification);
		$notification->expects($this->once())
			->method('setParsedMessage')
			->with('Open the case to see what changed.')
			->willReturn($notification);
		$notification->method('setIcon')->willReturn($notification);

		$this->notifier->prepare($notification, 'en');
	}//end testPrepareFallsBackWhenTheTransitionCarriesNoText()

	/**
	 * The subject a `notifyRole` automatic action dispatches is rendered.
	 *
	 * Without this the handler dispatches correctly into a channel that
	 * discards the result: an unlisted subject is refused here and Nextcloud
	 * drops the notification before the recipient sees it.
	 *
	 * The role slug does NOT reach the wording. It is workflow-configuration
	 * vocabulary, and a recipient who never opened the workflow editor cannot
	 * read it.
	 *
	 * @return void
	 */
	public function testPrepareRendersARoleNotification(): void {
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn(Application::APP_ID);
		$notification->method('getSubject')->willReturn(Notifier::SUBJECT_CASE_ROLE_NOTIFIED);
		$notification->method('getSubjectParameters')->willReturn(
			[
				'caseId' => 'case-1',
				'roleSlug' => 'behandelaar',
				'message' => 'Toets de ontvankelijkheid.',
			]
		);

		$notification->expects($this->once())
			->method('setParsedSubject')
			->with('A case you handle needs your attention')
			->willReturn($notification);
		$notification->expects($this->once())
			->method('setParsedMessage')
			->with('Toets de ontvankelijkheid.')
			->willReturn($notification);
		$notification->method('setIcon')->willReturn($notification);

		$this->notifier->prepare($notification, 'en');
	}//end testPrepareRendersARoleNotification()

	/**
	 * Without a configured message the recipient still gets the next step.
	 *
	 * @return void
	 */
	public function testPrepareFallsBackWhenTheRoleActionCarriesNoText(): void {
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn(Application::APP_ID);
		$notification->method('getSubject')->willReturn(Notifier::SUBJECT_CASE_ROLE_NOTIFIED);
		$notification->method('getSubjectParameters')->willReturn(['caseId' => 'case-1']);

		$notification->expects($this->once())
			->method('setParsedSubject')
			->with('A case you handle needs your attention')
			->willReturn($notification);
		$notification->expects($this->once())
			->method('setParsedMessage')
			->with('Open the case to see what to do next.')
			->willReturn($notification);
		$notification->method('setIcon')->willReturn($notification);

		$this->notifier->prepare($notification, 'en');
	}//end testPrepareFallsBackWhenTheRoleActionCarriesNoText()

	/**
	 * Every subject key the app dispatches is a subject this notifier renders.
	 *
	 * This is the test that would have caught all fourteen. `prepare()` throws
	 * on an unlisted key and Nextcloud then drops the notification, so a
	 * sender that adds a key without registering it ships a notification
	 * nobody ever receives, with nothing red anywhere. The check scans the
	 * senders rather than a hand-kept list, so the next sender is covered on
	 * the day it lands.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/ncvue-w2-leaves-adoption/spec.md#REQ-W2L-005
	 */
	public function testEverySubjectTheAppDispatchesCanBeRendered(): void {
		$dispatched = $this->dispatchedSubjectKeys();

		// A scanner that finds nothing would pass this test silently, which is
		// the failure mode it exists to catch. Fifteen literal keys are in the
		// tree today; a scan that comes back short has stopped reading the
		// senders, and that is a broken test rather than a clean repo.
		self::assertGreaterThanOrEqual(
			15,
			count($dispatched),
			'The sender scan found fewer subject keys than the tree holds, so it is no longer reading them.',
		);

		$unrenderable = [];
		foreach ($dispatched as $subjectKey => $senders) {
			$notification = $this->createMock(INotification::class);
			$notification->method('getApp')->willReturn(Application::APP_ID);
			$notification->method('getSubject')->willReturn($subjectKey);
			$notification->method('getSubjectParameters')->willReturn([]);
			$notification->method('setParsedSubject')->willReturn($notification);
			$notification->method('setParsedMessage')->willReturn($notification);
			$notification->method('setIcon')->willReturn($notification);

			try {
				$this->notifier->prepare($notification, 'en');
			} catch (UnknownNotificationException $e) {
				$unrenderable[] = $subjectKey . ' (' . implode(', ', $senders) . ')';
			}
		}

		self::assertSame(
			[],
			$unrenderable,
			"These subject keys are dispatched but not in Notifier::KNOWN_SUBJECTS, so Nextcloud drops them before anyone sees them:\n"
			. implode("\n", $unrenderable),
		);
	}//end testEverySubjectTheAppDispatchesCanBeRendered()

	/**
	 * A registered subject with none of its parameters set still tells the
	 * recipient what to do.
	 *
	 * A bell entry with an empty message is a dead end. Every renderer carries
	 * a fallback, and this pins that none of them was written without one.
	 *
	 * @dataProvider registeredSubjectProvider
	 *
	 * @param string $subjectKey The subject key under test.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/ncvue-w2-leaves-adoption/spec.md#REQ-W2L-005
	 */
	public function testABareSubjectStillYieldsANextStep(string $subjectKey): void {
		$parsedSubject = null;
		$parsedMessage = null;

		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn(Application::APP_ID);
		$notification->method('getSubject')->willReturn($subjectKey);
		$notification->method('getSubjectParameters')->willReturn([]);
		$notification->method('setParsedSubject')->willReturnCallback(
			function (string $text) use (&$parsedSubject, $notification): INotification {
				$parsedSubject = $text;

				return $notification;
			}
		);
		$notification->method('setParsedMessage')->willReturnCallback(
			function (string $text) use (&$parsedMessage, $notification): INotification {
				$parsedMessage = $text;

				return $notification;
			}
		);
		$notification->method('setIcon')->willReturn($notification);

		$this->notifier->prepare($notification, 'en');

		self::assertNotSame('', (string)$parsedSubject, $subjectKey . ' rendered an empty subject');
		self::assertNotSame('', (string)$parsedMessage, $subjectKey . ' rendered an empty message');
	}//end testABareSubjectStillYieldsANextStep()

	/**
	 * Each subject renders as itself, not as the wording of another.
	 *
	 * The renderer is one `match`, so a missing arm falls through to the
	 * mention wording and the recipient reads text about a note they were
	 * never mentioned in. This pins the arm per key.
	 *
	 * @dataProvider subjectWordingProvider
	 *
	 * @param string $subjectKey The subject key under test.
	 * @param array<string,mixed> $parameters The stored subject parameters.
	 * @param string $expectedSubject The wording the recipient should read.
	 * @param string $expectedMessage The message the recipient should read.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/ncvue-w2-leaves-adoption/spec.md#REQ-W2L-005
	 */
	public function testEachSubjectRendersAsItself(
		string $subjectKey,
		array $parameters,
		string $expectedSubject,
		string $expectedMessage,
	): void {
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn(Application::APP_ID);
		$notification->method('getSubject')->willReturn($subjectKey);
		$notification->method('getSubjectParameters')->willReturn($parameters);

		$notification->expects($this->once())
			->method('setParsedSubject')
			->with($expectedSubject)
			->willReturn($notification);
		$notification->expects($this->once())
			->method('setParsedMessage')
			->with($expectedMessage)
			->willReturn($notification);
		$notification->method('setIcon')->willReturn($notification);

		$this->notifier->prepare($notification, 'en');
	}//end testEachSubjectRendersAsItself()

	/**
	 * The wording each subject key owes its recipient.
	 *
	 * @return array<string, array{0:string,1:array<string,mixed>,2:string,3:string}>
	 */
	public static function subjectWordingProvider(): array {
		return [
			'milestone with a name and a count' => [
				Notifier::SUBJECT_MILESTONE_BOTTLENECK,
				['milestone' => 'Toets ontvankelijkheid', 'daysOverdue' => 4],
				'A case is stuck on Toets ontvankelijkheid',
				'Days past the planned date: 4. Open the case and move it on.',
			],
			'milestone without a name' => [
				Notifier::SUBJECT_MILESTONE_BOTTLENECK,
				[],
				'A case is stuck on a milestone',
				'Open the case and move it on.',
			],
			'reassignment digest' => [
				Notifier::SUBJECT_CASES_REASSIGNED,
				['fromUser' => 'bob', 'count' => 7],
				'Cases were transferred to you',
				'Cases from bob: 7. Open your case list to see them.',
			],
			'advice asked, transition key' => [
				Notifier::SUBJECT_ADVICE_REQUESTED_NL,
				['message' => 'Advies over de ontvankelijkheid'],
				'You were asked for advice',
				'Advies over de ontvankelijkheid',
			],
			'advice asked, creation key reads the same' => [
				Notifier::SUBJECT_ADVICE_REQUESTED,
				[],
				'You were asked for advice',
				'Open the request to see what is asked.',
			],
			'advice answered' => [
				Notifier::SUBJECT_ADVICE_RECEIVED,
				[],
				'Your advice request was answered',
				'Open the request to read the advice.',
			],
			'advice reminder' => [
				Notifier::SUBJECT_ADVICE_REMINDER,
				[],
				'An advice request is still open',
				'Open the request and send your advice.',
			],
			'woo warning with days left' => [
				Notifier::SUBJECT_WOO_DEADLINE_WARNING,
				['daysRemaining' => 3],
				'A Woo request is nearing its deadline',
				'Days left to decide: 3. Open the request.',
			],
			'woo warning without days left' => [
				Notifier::SUBJECT_WOO_DEADLINE_WARNING,
				[],
				'A Woo request is nearing its deadline',
				'Open the request and plan the decision.',
			],
			'woo overdue' => [
				Notifier::SUBJECT_WOO_DEADLINE_OVERDUE,
				['daysRemaining' => 0],
				'A Woo deadline has passed',
				'The legal term has run out. Decide on this request now.',
			],
			'permit warning' => [
				Notifier::SUBJECT_DSO_DEADLINE_WARNING,
				[],
				'A permit case is nearing its deadline',
				'Open the case and plan the decision.',
			],
			'permit critical' => [
				Notifier::SUBJECT_DSO_DEADLINE_CRITICAL,
				[],
				'A permit deadline is close',
				'Little time is left. Open the case and decide.',
			],
			'permit overdue' => [
				Notifier::SUBJECT_DSO_DEADLINE_OVERDUE,
				[],
				'A permit deadline has passed',
				'The legal term has run out. Decide on this case now.',
			],
			'stuf circuit open' => [
				Notifier::SUBJECT_STUF_CIRCUIT_OPEN,
				['endpointId' => 'ep-1'],
				'A StUF connection was switched off',
				'Too many failures in a row. Check the endpoint settings.',
			],
			'stuf timeout' => [
				Notifier::SUBJECT_STUF_TIMEOUT,
				['endpointId' => 'ep-1'],
				'A StUF message timed out',
				'The other system did not answer. Check the endpoint and try again.',
			],
			'stuf permanent error' => [
				Notifier::SUBJECT_STUF_PERMANENT_ERROR,
				['endpointId' => 'ep-1'],
				'A StUF message was refused',
				'The other system rejected it. Open the endpoint log to see why.',
			],
		];
	}//end subjectWordingProvider()

	/**
	 * Every subject key this notifier claims to know.
	 *
	 * Read off the class rather than retyped, so a key added to the notifier
	 * without a fallback message is caught by
	 * {@see self::testABareSubjectStillYieldsANextStep()} rather than skipped.
	 *
	 * @return array<string, array{0:string}>
	 */
	public static function registeredSubjectProvider(): array {
		$reflection = new \ReflectionClass(Notifier::class);
		$known = $reflection->getConstant('KNOWN_SUBJECTS');

		$cases = [];
		foreach ((array)$known as $subjectKey) {
			$cases[(string)$subjectKey] = [(string)$subjectKey];
		}

		return $cases;
	}//end registeredSubjectProvider()

	/**
	 * Every literal subject key the app's senders dispatch, and where from.
	 *
	 * Four shapes reach `IManager::setSubject()` in this tree, and all four
	 * are read: the direct call, a `subject:` named argument on a helper that
	 * forwards to it, a `$subject = '...'` assignment in a file that also
	 * builds notifications, and `NeedsInputDispatcher::dispatch(type: ...)`,
	 * which hands its type straight through as the subject.
	 *
	 * Keys held in a `Notifier::SUBJECT_*` constant are not scanned for: a
	 * constant cannot name a key the notifier does not have.
	 *
	 * @return array<string, array<int, string>> Subject key to sender files.
	 */
	private function dispatchedSubjectKeys(): array {
		$patterns = [
			'/setSubject\(\s*(?:subject:\s*)?[\'"]([a-z][a-z0-9_]*)[\'"]/',
			'/(?<![a-zA-Z])subject:\s*[\'"]([a-z][a-z0-9_]*)[\'"]/',
			'/needsInput(?:Dispatcher)?->dispatch\(\s*type:\s*[\'"]([a-z][a-z0-9_]*)[\'"]/',
		];

		$found = [];
		$root = dirname(__DIR__, 3) . '/lib';
		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
		foreach ($files as $file) {
			if ($file->isFile() === false || $file->getExtension() !== 'php') {
				continue;
			}

			$source = (string)file_get_contents($file->getPathname());
			$scan = $patterns;
			if (str_contains($source, 'createNotification(') === true) {
				$scan[] = '/\$subject\s*=\s*[\'"]([a-z][a-z0-9_]*)[\'"]/';
			}

			foreach ($scan as $pattern) {
				$matches = [];
				preg_match_all($pattern, $source, $matches);
				foreach ($matches[1] as $subjectKey) {
					$relative = str_replace(dirname($root) . '/', '', $file->getPathname());
					$found[$subjectKey][$relative] = true;
				}
			}
		}

		$out = [];
		foreach ($found as $subjectKey => $senders) {
			$out[$subjectKey] = array_keys($senders);
		}

		ksort($out);

		return $out;
	}//end dispatchedSubjectKeys()
}//end class
