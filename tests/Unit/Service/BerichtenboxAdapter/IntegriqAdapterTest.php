<?php

/**
 * Unit tests for the integriq digital post adapter.
 *
 * Every letter dossiq composed was delivered to nothing until this class
 * existed: `lib/Service/BerichtenboxAdapter/` held an interface and a mock,
 * and the mock's `sendMessage` answers `status: sent` with a generated id.
 * These arms are what keep that from coming back in another shape.
 *
 * The doubles use `onlyMethods`, never `addMethods`: a double that can invent
 * a method the real class lacks passes on a call production would fatal on.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\BerichtenboxAdapter
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/digital-post-reaches-integriq/specs/berichtenbox-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\BerichtenboxAdapter;

use OCA\Dossiq\Service\BerichtenboxAdapter\IntegriqAdapter;
use OCP\App\IAppManager;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests that the adapter reaches integriq and refuses rather than simulating.
 *
 * @covers \OCA\Dossiq\Service\BerichtenboxAdapter\IntegriqAdapter
 * @uses \OCA\Dossiq\Support\FleetAppId
 */
class IntegriqAdapterTest extends TestCase {
	/**
	 * @var IEventDispatcher|MockObject
	 */
	private $dispatcher;

	/**
	 * @var IAppManager|MockObject
	 */
	private $appManager;

	/**
	 * @var IAppConfig|MockObject
	 */
	private $appConfig;

	/**
	 * The event the dispatcher was handed, for assertions on the envelope.
	 *
	 * @var Event|null
	 */
	private ?Event $dispatched = null;

	/**
	 * Set up the collaborators with integriq present by default.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->dispatcher = $this->createMock(IEventDispatcher::class);
		$this->appManager = $this->createMock(IAppManager::class);
		$this->appConfig = $this->createMock(IAppConfig::class);

		// FleetAppId resolves the ID first (`isInstalled`, newest candidate
		// first) and only then asks whether it is enabled. Doubling only the
		// second of the two answers false at the first step, which is how the
		// first draft of this file had three tests passing on the
		// integriq-missing branch while claiming to test the others.
		$this->appManager->method('isInstalled')->willReturn(true);
		$this->appManager->method('isEnabledForUser')->willReturn(true);
		$this->appConfig->method('getValueString')->willReturn('digital-post-source-1');
	}//end setUp()

	/**
	 * Build the subject under test.
	 *
	 * @return IntegriqAdapter
	 */
	private function adapter(): IntegriqAdapter {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('handler');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new IntegriqAdapter(
			$this->dispatcher,
			$this->appManager,
			$this->appConfig,
			$session,
			$this->createMock(LoggerInterface::class)
		);
	}//end adapter()

	/**
	 * Answer the dispatched event the way an integriq listener would.
	 *
	 * @param callable(Event): void $answer What the listener does to the slot.
	 *
	 * @return void
	 */
	private function answerWith(callable $answer): void {
		$this->dispatcher->method('dispatchTyped')->willReturnCallback(
			function (Event $event) use ($answer): void {
				$this->dispatched = $event;
				$answer($event);
			}
		);
	}//end answerWith()

	/**
	 * A tracked message id is a send, and the envelope names dossiq.
	 *
	 * @return void
	 */
	public function testATrackedMessageIdIsRecordedAsSent(): void {
		$this->answerWith(
			static function (Event $event): void {
				$event->setHandled(true);
				$event->setMessageId('dp-4711');
			}
		);

		$result = $this->adapter()->sendMessage('123456782', 'Besluit', 'De tekst', 'BESLUIT');

		$this->assertSame('dp-4711', $result['messageId']);
		$this->assertSame('sent', $result['status']);
		$this->assertArrayNotHasKey('refused', $result);

		$this->assertNotNull($this->dispatched);
		$this->assertSame('dossiq', $this->dispatched->getSourceApp());
		$this->assertSame('123456782', $this->dispatched->getRecipient());
		$this->assertSame('handler', $this->dispatched->getRequestedBy());
	}//end testATrackedMessageIdIsRecordedAsSent()

	/**
	 * A structured refusal is a refusal, carrying integriq's own reason.
	 *
	 * @return void
	 */
	public function testAStructuredRefusalIsNotADelivery(): void {
		$this->answerWith(
			static function (Event $event): void {
				$event->setHandled(true);
				$event->setRefusal('No PKIoverheid certificate is configured.', 'missing-certificate');
			}
		);

		$result = $this->adapter()->sendMessage('123456782', 'Besluit', 'De tekst', 'BESLUIT');

		// THE ASSERTION THAT MATTERS: no message id at all, and the word the
		// caller branches on. A refusal carrying an id would be recorded as a
		// send with a reference nothing can follow.
		$this->assertArrayNotHasKey('messageId', $result);
		$this->assertTrue($result['refused']);
		$this->assertSame('refused', $result['status']);
		$this->assertSame('missing-certificate', $result['code']);
		$this->assertStringContainsString('PKIoverheid', $result['error']);
	}//end testAStructuredRefusalIsNotADelivery()

	/**
	 * An unanswered slot is a refusal, never a send.
	 *
	 * integriq's contract says a handled event always carries one or the other,
	 * so this is the arm that decides what happens when the contract is broken.
	 * Treating it as a send is how a letter that went nowhere reads as
	 * delivered.
	 *
	 * @return void
	 */
	public function testAnUnansweredSlotIsARefusal(): void {
		$this->answerWith(
			static function (Event $event): void {
				$event->setHandled(false);
			}
		);

		$result = $this->adapter()->sendMessage('123456782', 'Besluit', 'De tekst', 'BESLUIT');

		$this->assertArrayNotHasKey('messageId', $result);
		$this->assertTrue($result['refused']);
		$this->assertSame('unhandled', $result['code']);
	}//end testAnUnansweredSlotIsARefusal()

	/**
	 * A handled event with neither an id nor a refusal is a refusal too.
	 *
	 * @return void
	 */
	public function testAHandledEventWithNoTrackedMessageIsARefusal(): void {
		$this->answerWith(
			static function (Event $event): void {
				$event->setHandled(true);
			}
		);

		$result = $this->adapter()->sendMessage('123456782', 'Besluit', 'De tekst', 'BESLUIT');

		$this->assertArrayNotHasKey('messageId', $result);
		$this->assertSame('no-tracked-message', $result['code']);
	}//end testAHandledEventWithNoTrackedMessageIsARefusal()

	/**
	 * With no integriq the send is refused and the reason names the app.
	 *
	 * Nothing is dispatched either: an instance without integriq has no
	 * listener to reach, and dispatching anyway would make the refusal depend
	 * on the dispatcher rather than on the probe.
	 *
	 * @return void
	 */
	public function testWithNoIntegriqTheSendIsRefusedAndNothingIsDispatched(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(false);
		$appManager->method('isEnabledForUser')->willReturn(false);
		$this->appManager = $appManager;

		$this->dispatcher->expects($this->never())->method('dispatchTyped');

		$result = $this->adapter()->sendMessage('123456782', 'Besluit', 'De tekst', 'BESLUIT');

		$this->assertTrue($result['refused']);
		$this->assertSame('integriq-missing', $result['code']);
		$this->assertStringContainsString('Integriq', $result['error']);
	}//end testWithNoIntegriqTheSendIsRefusedAndNothingIsDispatched()

	/**
	 * A renamed integriq still resolves, under either of its two ids.
	 *
	 * REQ-BB-22. The whole reason FleetAppId exists: integriq shipped as
	 * `openconnector` and a literal app id here would answer false on an
	 * instance still running that release, which makes the integration a
	 * silent no-op rather than an error.
	 *
	 * @return void
	 */
	public function testIntegriqStillResolvesUnderItsOldId(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturnCallback(
			static fn (string $id): bool => ($id === 'openconnector')
		);
		$appManager->method('isEnabledForUser')->willReturn(true);
		$this->appManager = $appManager;

		$this->answerWith(
			static function (Event $event): void {
				$event->setHandled(true);
				$event->setMessageId('dp-old-id');
			}
		);

		$result = $this->adapter()->sendMessage('123456782', 'Besluit', 'De tekst', 'BESLUIT');

		// A send, NOT the integriq-missing refusal: the app is there, under
		// the name it used to have.
		$this->assertSame('dp-old-id', $result['messageId']);
	}//end testIntegriqStillResolvesUnderItsOldId()

	/**
	 * A read status is never invented: the adapter says it does not know.
	 *
	 * @return void
	 */
	public function testTheReadStatusIsAnsweredAsUnknownRatherThanRead(): void {
		$status = $this->adapter()->getReadStatus('dp-4711');

		// `read: true` here would mark a letter read that nobody opened, which
		// is this whole change one layer down. `unknown` is what lets
		// BerichtenboxService tell "not read" from "not asked".
		$this->assertFalse($status['read']);
		$this->assertTrue($status['unknown']);
		$this->assertNull($status['readAt']);
	}//end testTheReadStatusIsAnsweredAsUnknownRatherThanRead()
}//end class
