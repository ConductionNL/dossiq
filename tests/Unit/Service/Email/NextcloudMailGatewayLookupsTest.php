<?php

/**
 * The Mail gateway's lookups against a Mail app that answers.
 *
 * Pins the DKIM verdict, the trusted-sender lookup and the account resolution
 * (observed through source()) for every branch, with Mail's services stood in
 * by plain objects resolved from the container by class name.
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

use OCA\Dossiq\Service\Email\AuthenticationResult;
use OCA\Dossiq\Service\Email\MailMessageSource;
use OCA\Dossiq\Service\Email\NextcloudMailGateway;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use RuntimeException;

/**
 * Characterisation tests for the gateway's lookups.
 *
 * @covers \OCA\Dossiq\Service\Email\NextcloudMailGateway
 */
class NextcloudMailGatewayLookupsTest extends TestCase {

	private const DKIM = 'OCA\\Mail\\Service\\DkimService';

	private const TRUSTED = 'OCA\\Mail\\Service\\TrustedSenderService';

	private const ACCOUNTS = 'OCA\\Mail\\Service\\AccountService';

	private const MANAGER = 'OCA\\Mail\\Service\\MailManager';

	/**
	 * Messages logged during the test, as "level: message".
	 *
	 * @var array<int, string>
	 */
	private array $logged = [];

	/**
	 * Build a gateway whose container answers the given services.
	 *
	 * @param array<string, object> $services The services by class name.
	 * @param MailMessageSource     $tables   The Mail tables.
	 *
	 * @return NextcloudMailGateway The gateway.
	 */
	private function gateway(array $services, ?MailMessageSource $tables=null): NextcloudMailGateway {
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

		$logged = &$this->logged;
		$logger = new class($logged) extends AbstractLogger {
			/**
			 * @param array<int, string> $logged The shared log.
			 */
			public function __construct(private array &$logged) {
			}

			/**
			 * @param mixed                $level   The level.
			 * @param string|\Stringable   $message The message.
			 * @param array<string, mixed> $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context=[]): void {
				$this->logged[] = $level.': '.$message.' '.json_encode($context);
			}
		};

		return new NextcloudMailGateway(
			$appManager,
			$container,
			$tables ?? $this->createMock(MailMessageSource::class),
			$logger
		);
	}//end gateway()

	/**
	 * A validator answering the given value, or throwing when it is an exception.
	 *
	 * @param mixed $answer The answer.
	 *
	 * @return object The validator.
	 */
	private function validator(mixed $answer): object {
		return new class($answer) {
			/**
			 * @param mixed $answer The answer.
			 */
			public function __construct(private mixed $answer) {
			}

			/**
			 * @param string $source The source.
			 *
			 * @return mixed The answer.
			 */
			public function validateRaw(string $source): mixed {
				if ($this->answer instanceof \Throwable) {
					throw $this->answer;
				}

				return $this->answer;
			}
		};
	}//end validator()

	/**
	 * Every DKIM verdict branch.
	 *
	 * @return void
	 */
	public function testTheDkimVerdictForEveryAnswer(): void {
		$signed = "DKIM-Signature: v=1; d=gemeente.nl\r\n\r\nbody";

		self::assertSame(AuthenticationResult::UNAVAILABLE, $this->gateway([])->dkimResult(''));
		self::assertSame(AuthenticationResult::NONE, $this->gateway([])->dkimResult("Subject: x\r\n\r\nbody"));
		self::assertSame(AuthenticationResult::UNAVAILABLE, $this->gateway([])->dkimResult($signed));
		self::assertSame(AuthenticationResult::UNAVAILABLE, $this->gateway([self::DKIM => new \stdClass()])->dkimResult($signed));
		self::assertSame(AuthenticationResult::PASS, $this->gateway([self::DKIM => $this->validator(true)])->dkimResult($signed));
		self::assertSame(AuthenticationResult::FAIL, $this->gateway([self::DKIM => $this->validator(false)])->dkimResult($signed));
		self::assertSame(AuthenticationResult::UNAVAILABLE, $this->gateway([self::DKIM => $this->validator(1)])->dkimResult($signed));

		$this->logged = [];
		self::assertSame(
			AuthenticationResult::UNAVAILABLE,
			$this->gateway([self::DKIM => $this->validator(new RuntimeException('boom'))])->dkimResult($signed)
		);
		self::assertSame(['debug: Dossiq: the DKIM check could not run {"error":"boom"}'], $this->logged);
	}//end testTheDkimVerdictForEveryAnswer()

	/**
	 * The trusted-sender lookup asks each owner in turn and stops at the first yes.
	 *
	 * @return void
	 */
	public function testTheTrustedSenderLookupAsksEachOwnerUntilOneTrusts(): void {
		$tables = $this->createMock(MailMessageSource::class);
		$tables->method('allAccounts')->willReturn([['id' => 1], ['id' => 2], ['id' => 3], ['id' => 4]]);
		$tables->method('ownerOf')->willReturnMap([[1, ''], [2, 'boom'], [3, 'anne'], [4, 'bert']]);

		$service = new class {
			/**
			 * @var array<int, string>
			 */
			public array $asked = [];

			/**
			 * @param string $owner   The owner.
			 * @param string $address The address.
			 *
			 * @return boolean Whether trusted.
			 */
			public function isTrusted(string $owner, string $address): bool {
				$this->asked[] = $owner.'/'.$address;
				if ($owner === 'boom') {
					throw new RuntimeException('down');
				}

				return $owner === 'anne';
			}
		};

		$gateway = $this->gateway([self::TRUSTED => $service], $tables);
		self::assertTrue($gateway->isTrustedSender('  Burger@Example.NL '));
		self::assertSame(['boom/burger@example.nl', 'anne/burger@example.nl'], $service->asked);
		self::assertSame(['debug: Dossiq: the trusted-sender lookup failed {"error":"down"}'], $this->logged);
	}//end testTheTrustedSenderLookupAsksEachOwnerUntilOneTrusts()

	/**
	 * Nobody trusting, an empty address and a missing service all answer no.
	 *
	 * @return void
	 */
	public function testTheTrustedSenderLookupAnswersNoOtherwise(): void {
		$tables = $this->createMock(MailMessageSource::class);
		$tables->method('allAccounts')->willReturn([['id' => 4]]);
		$tables->method('ownerOf')->willReturn('bert');

		$service = new class {
			/**
			 * @param string $owner   The owner.
			 * @param string $address The address.
			 *
			 * @return boolean Whether trusted.
			 */
			public function isTrusted(string $owner, string $address): bool {
				return false;
			}
		};

		self::assertFalse($this->gateway([self::TRUSTED => $service], $tables)->isTrustedSender('a@b.nl'));
		self::assertFalse($this->gateway([self::TRUSTED => $service], $tables)->isTrustedSender('   '));
		self::assertFalse($this->gateway([self::TRUSTED => new \stdClass()], $tables)->isTrustedSender('a@b.nl'));
		self::assertFalse($this->gateway([], $tables)->isTrustedSender('a@b.nl'));
	}//end testTheTrustedSenderLookupAnswersNoOtherwise()

	/**
	 * An account service answering the given value, or throwing when it is an exception.
	 *
	 * @param mixed $answer The answer.
	 *
	 * @return object The account service.
	 */
	private function accounts(mixed $answer): object {
		return new class($answer) {
			/**
			 * @param mixed $answer The answer.
			 */
			public function __construct(private mixed $answer) {
			}

			/**
			 * @param integer $id The account id.
			 *
			 * @return mixed The answer.
			 */
			public function findById(int $id): mixed {
				if ($this->answer instanceof \Throwable) {
					throw $this->answer;
				}

				return $this->answer;
			}
		};
	}//end accounts()

	/**
	 * The account is resolved for every answer of the account service.
	 *
	 * @return void
	 */
	public function testTheAccountResolutionForEveryAnswer(): void {
		$manager = new class {
			/**
			 * @param object  $account The account.
			 * @param string  $mailbox The folder.
			 * @param integer $uid     The uid.
			 *
			 * @return string The source.
			 */
			public function getSource(object $account, string $mailbox, int $uid): string {
				return 'source of '.$account->name;
			}
		};
		$account = new \stdClass();
		$account->name = 'intake';

		self::assertSame('source of intake', $this->gateway([self::ACCOUNTS => $this->accounts($account), self::MANAGER => $manager])->source(7, 'INBOX', 1));
		self::assertSame('', $this->gateway([self::ACCOUNTS => $this->accounts($account), self::MANAGER => $manager])->source(0, 'INBOX', 1));
		self::assertSame('', $this->gateway([self::ACCOUNTS => $this->accounts('id'), self::MANAGER => $manager])->source(7, 'INBOX', 1));
		self::assertSame('', $this->gateway([self::ACCOUNTS => new \stdClass(), self::MANAGER => $manager])->source(7, 'INBOX', 1));

		$this->logged = [];
		self::assertSame('', $this->gateway([self::MANAGER => $manager])->source(7, 'INBOX', 1));
		self::assertSame(
			['warning: Dossiq: a Nextcloud Mail service could not be resolved, so intake is unavailable {"service":"OCA\\\\Mail\\\\Service\\\\AccountService","error":"not installed"}'],
			$this->logged
		);

		$this->logged = [];
		self::assertSame('', $this->gateway([self::ACCOUNTS => $this->accounts(new RuntimeException('gone')), self::MANAGER => $manager])->source(7, 'INBOX', 1));
		self::assertSame(['warning: Dossiq: the configured intake account could not be read {"account":7,"error":"gone"}'], $this->logged);
	}//end testTheAccountResolutionForEveryAnswer()
}//end class
