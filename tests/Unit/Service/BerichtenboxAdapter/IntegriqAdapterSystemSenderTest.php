<?php

/**
 * Unit tests for a digital post letter sent without a signed-in user.
 *
 * A letter sent by a background job has no session. It must still reach
 * integriq, with `system` as the requester and the recipient and subject the
 * caller gave, never an empty requester integriq would have to guess about.
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
 * @spec openspec/specs/berichtenbox-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\BerichtenboxAdapter;

use OCA\Dossiq\Service\BerichtenboxAdapter\IntegriqAdapter;
use OCP\App\IAppManager;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The requester and the letter's envelope when no user is signed in.
 *
 * @covers \OCA\Dossiq\Service\BerichtenboxAdapter\IntegriqAdapter
 * @covers \OCA\Dossiq\Service\BerichtenboxAdapter\DigitalPostLetter
 * @uses   \OCA\Dossiq\Support\FleetAppId
 */
class IntegriqAdapterSystemSenderTest extends TestCase {

	/**
	 * Without a session the letter goes out as `system`, with the given recipient and subject.
	 *
	 * @return void
	 */
	public function testWithoutASessionTheRequesterIsSystem(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);
		$appManager->method('isEnabledForUser')->willReturn(true);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('digital-post-source-1');
		$session = $this->createMock(IUserSession::class);
		$session->expects($this->atLeastOnce())->method('getUser')->willReturn(null);

		$dispatched = null;
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->expects($this->once())
			->method('dispatchTyped')
			->willReturnCallback(
				static function (Event $event) use (&$dispatched): void {
					$dispatched = $event;
					$event->setHandled(true);
					$event->setMessageId('dp-900');
				}
			);

		$adapter = new IntegriqAdapter(
			$dispatcher,
			$appManager,
			$appConfig,
			$session,
			$this->createMock(LoggerInterface::class)
		);

		$result = $adapter->sendMessage('999993653', 'Ontvangstbevestiging', 'Wij hebben uw aanvraag ontvangen.', 'ONTVANGST');

		$this->assertSame('dp-900', $result['messageId']);
		$this->assertSame('sent', $result['status']);
		$this->assertNotNull($dispatched);
		$this->assertSame('system', $dispatched->getRequestedBy());
		$this->assertSame('999993653', $dispatched->getRecipient());
		$this->assertSame('Ontvangstbevestiging', $dispatched->getSubject());
		$this->assertSame('digital-post-source-1', $dispatched->getSourceId());
	}//end testWithoutASessionTheRequesterIsSystem()
}//end class
