<?php

/**
 * The Mail gateway's threading lookup and outbox resolution against a Mail app that answers.
 *
 * Pins holdsMessageId() for every answer of Mail's manager, and that a send
 * finds no outbox unless Mail's outbox service offers both verbs.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Email
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
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Email;

use OCA\Dossiq\Service\Email\MailMessageSource;
use OCA\Dossiq\Service\Email\NextcloudMailGateway;
use OCA\Dossiq\Service\Email\OutboundState;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use RuntimeException;

require_once __DIR__ . '/../../../Support/Mail/LocalMessage.php';

/**
 * Characterisation tests for the threading lookup and the outbox.
 *
 * @covers \OCA\Dossiq\Service\Email\NextcloudMailGateway
 */
class NextcloudMailGatewayThreadingTest extends TestCase {

	private const ACCOUNTS = 'OCA\\Mail\\Service\\AccountService';

	private const MANAGER = 'OCA\\Mail\\Service\\MailManager';

	private const OUTBOX = 'OCA\\Mail\\Service\\OutboxService';

	/**
	 * Log lines and manager calls, in order.
	 *
	 * @var array<int, string>
	 */
	private array $said = [];

	/**
	 * A gateway whose container answers the given services.
	 *
	 * @param array<string, object> $services The services by class name.
	 *
	 * @return NextcloudMailGateway The gateway.
	 */
	private function gateway(array $services): NextcloudMailGateway {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $name) use ($services): object {
				if (isset($services[$name]) === false) {
					throw new RuntimeException('not installed');
				}

				return $services[$name];
			}
		);

		$said = &$this->said;
		$logger = new class($said) extends AbstractLogger {
			/**
			 * @param array<int, string> $said The shared record.
			 */
			public function __construct(private array &$said) {
			}

			/**
			 * @param mixed                $level   The level.
			 * @param string|\Stringable   $message The message.
			 * @param array<string, mixed> $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context=[]): void {
				$this->said[] = $level.': '.$message.' '.json_encode($context);
			}
		};

		return new NextcloudMailGateway($appManager, $container, $this->createMock(MailMessageSource::class), $logger);
	}//end gateway()

	/**
	 * Mail's account service, answering one account for id 7.
	 *
	 * @return object The account service.
	 */
	private function accounts(): object {
		return new class {
			/**
			 * @param integer $id The account id.
			 *
			 * @return object|null The account.
			 */
			public function findById(int $id): ?object {
				return $id === 7 ? (object)['id' => 7] : null;
			}
		};
	}//end accounts()

	/**
	 * Mail's manager, answering the given value for every message id, or throwing it.
	 *
	 * @param mixed $answer The answer.
	 *
	 * @return object The manager.
	 */
	private function manager(mixed $answer): object {
		$said = &$this->said;
		return new class($answer, $said) {
			/**
			 * @param mixed              $answer The answer.
			 * @param array<int, string> $said   The shared record.
			 */
			public function __construct(private mixed $answer, private array &$said) {
			}

			/**
			 * @param object $account   The account.
			 * @param string $messageId The bare message id.
			 *
			 * @return mixed The answer.
			 */
			public function getByMessageId(object $account, string $messageId): mixed {
				$this->said[] = 'lookup '.$account->id.' '.$messageId;
				if ($this->answer instanceof \Throwable) {
					throw $this->answer;
				}

				return $this->answer;
			}
		};
	}//end manager()

	/**
	 * The threading lookup answers yes only for a message Mail really holds.
	 *
	 * @return void
	 */
	public function testTheThreadingLookupForEveryAnswer(): void {
		$with = fn (mixed $answer): NextcloudMailGateway => $this->gateway([self::ACCOUNTS => $this->accounts(), self::MANAGER => $this->manager($answer)]);

		self::assertTrue($with([['id' => 1]])->holdsMessageId(7, ' <abc@gemeente.nl> '));
		self::assertFalse($with([])->holdsMessageId(7, '<abc@gemeente.nl>'));
		self::assertFalse($with('yes')->holdsMessageId(7, '<abc@gemeente.nl>'));
		self::assertFalse($with([['id' => 1]])->holdsMessageId(7, ' <> '));
		self::assertFalse($with([['id' => 1]])->holdsMessageId(8, '<abc@gemeente.nl>'));
		self::assertFalse($this->gateway([self::ACCOUNTS => $this->accounts(), self::MANAGER => new \stdClass()])->holdsMessageId(7, '<abc@gemeente.nl>'));
		self::assertFalse($this->gateway([self::ACCOUNTS => $this->accounts()])->holdsMessageId(7, '<abc@gemeente.nl>'));
		self::assertSame(
			[
				'lookup 7 abc@gemeente.nl',
				'lookup 7 abc@gemeente.nl',
				'lookup 7 abc@gemeente.nl',
				'warning: Dossiq: a Nextcloud Mail service could not be resolved, so intake is unavailable {"service":"OCA\\\\Mail\\\\Service\\\\MailManager","error":"not installed"}',
			],
			$this->said
		);

		$this->said = [];
		self::assertFalse($with(new RuntimeException('imap down'))->holdsMessageId(7, '<abc@gemeente.nl>'));
		self::assertSame(
			[
				'lookup 7 abc@gemeente.nl',
				'warning: Dossiq: the threading lookup failed, so the claim stays unproven {"account":7,"error":"imap down"}',
			],
			$this->said
		);
	}//end testTheThreadingLookupForEveryAnswer()

	/**
	 * A send finds no outbox unless Mail's outbox service offers both verbs.
	 *
	 * @return void
	 */
	public function testASendNeedsAnOutboxWithBothVerbs(): void {
		$unavailable = ['state' => OutboundState::UNAVAILABLE, 'outboxId' => null];
		$said = &$this->said;
		$saveOnly = new class($said) {
			/**
			 * @param array<int, string> $said The shared record.
			 */
			public function __construct(private array &$said) {
			}

			/**
			 * @return object Never used.
			 */
			public function saveMessage(): object {
				$this->said[] = 'saveMessage asked';
				throw new RuntimeException('must not be asked');
			}
		};
		$sendOnly = new class {
			/**
			 * @return object Never used.
			 */
			public function sendMessage(): object {
				throw new RuntimeException('must not be asked');
			}
		};
		$message = ['to' => ['a@b.nl'], 'subject' => 'S', 'html' => '', 'plain' => 'p'];

		self::assertSame($unavailable, $this->gateway([self::ACCOUNTS => $this->accounts(), self::OUTBOX => $saveOnly])->sendMessage(7, $message));
		self::assertSame($unavailable, $this->gateway([self::ACCOUNTS => $this->accounts(), self::OUTBOX => $sendOnly])->sendMessage(7, $message));
		self::assertSame($unavailable, $this->gateway([self::ACCOUNTS => $this->accounts()])->sendMessage(7, $message));
		self::assertNotContains('saveMessage asked', $this->said);
	}//end testASendNeedsAnOutboxWithBothVerbs()
}//end class
