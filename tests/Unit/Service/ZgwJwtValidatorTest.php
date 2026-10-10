<?php

/**
 * Characterisation tests for ZgwJwtValidator.
 *
 * Pins every refusal of validate(), in order, and the user binding on success, so its
 * decomposition (method-decomposition) can be proven to change no outcome.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\ZgwAuthValidationException;
use OCA\Dossiq\Service\ZgwJwtValidator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The ConsumerMapper shape the validator calls.
 */
interface JwtConsumerMapperStub {
	public function findAll(array $filters): array;
}//end interface

/**
 * Characterisation tests for the ZGW JWT validator.
 *
 * @covers \OCA\Dossiq\Service\ZgwJwtValidator
 */
class ZgwJwtValidatorTest extends TestCase {

	private const SECRET = 'gedeeld-geheim';

	/**
	 * The user the session was bound to, if any.
	 *
	 * @var IUser|null
	 */
	private ?IUser $bound = null;

	/**
	 * Build a validator without its container-reading constructor.
	 *
	 * @param object|null $consumer The consumer the mapper knows, or null for none
	 * @param bool        $mapper   Whether a ConsumerMapper is available
	 *
	 * @return ZgwJwtValidator
	 */
	private function validator(?object $consumer, bool $mapper=true): ZgwJwtValidator {
		$validator = (new \ReflectionClass(ZgwJwtValidator::class))->newInstanceWithoutConstructor();

		$consumerMapper = null;
		if ($mapper === true) {
			$consumerMapper = $this->createMock(JwtConsumerMapperStub::class);
			$consumerMapper->method('findAll')->willReturnCallback(
				static fn (array $filters): array => ($consumer !== null && $filters['name'] === 'client-a') ? [$consumer] : []
			);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('setUser')->willReturnCallback(function (?IUser $user): void {
			$this->bound = $user;
		});
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(
			fn (string $uid): ?IUser => $uid === 'alice' ? $this->createMock(IUser::class) : null
		);
		$logger = $this->createMock(LoggerInterface::class);

		\Closure::bind(
			static function () use ($validator, $consumerMapper, $session, $users, $logger): void {
				$validator->logger = $logger;
				$validator->userSession = $session;
				$validator->userManager = $users;
				$validator->consumerMapper = $consumerMapper;
				$validator->authorizationService = null;
			},
			null,
			ZgwJwtValidator::class
		)();

		return $validator;

	}//end validator()

	/**
	 * A consumer with a shared secret and an optional user.
	 *
	 * @param string $userId    The consumer's user id
	 * @param string $algorithm The configured algorithm, '' for none
	 *
	 * @return object
	 */
	private function consumer(string $userId='alice', string $algorithm=''): object {
		$config = ['publicKey' => self::SECRET];
		if ($algorithm !== '') {
			$config['algorithm'] = $algorithm;
		}

		return new class($config, $userId) {
			/**
			 * Constructor.
			 *
			 * @param array  $config The authorisation configuration
			 * @param string $userId The user id
			 */
			public function __construct(private array $config, private string $userId) {
			}//end __construct()

			/**
			 * The authorisation configuration.
			 *
			 * @return array
			 */
			public function getAuthorizationConfiguration(): array {
				return $this->config;
			}//end getAuthorizationConfiguration()

			/**
			 * The user id.
			 *
			 * @return string
			 */
			public function getUserId(): string {
				return $this->userId;
			}//end getUserId()
		};

	}//end consumer()

	/**
	 * Base64url-encode.
	 *
	 * @param string $data The bytes
	 *
	 * @return string
	 */
	private function b64(string $data): string {
		return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');

	}//end b64()

	/**
	 * A signed token.
	 *
	 * @param array  $payload The claims
	 * @param string $alg     The header algorithm
	 * @param string $secret  The signing secret
	 *
	 * @return string The Authorization header value
	 */
	private function token(array $payload, string $alg='HS256', string $secret=self::SECRET): string {
		$header = $this->b64((string)json_encode(['alg' => $alg, 'typ' => 'JWT']));
		$body   = $this->b64((string)json_encode($payload));
		$hash   = ['HS256' => 'sha256', 'HS384' => 'sha384', 'HS512' => 'sha512'][$alg] ?? 'sha256';
		$sig    = $this->b64(hash_hmac($hash, $header.'.'.$body, $secret, true));

		return 'Bearer '.$header.'.'.$body.'.'.$sig;

	}//end token()

	/**
	 * Assert validate() refuses with exactly this message.
	 *
	 * @param ZgwJwtValidator $validator     The validator
	 * @param string          $authorization The header
	 * @param string          $message       The expected message
	 *
	 * @return void
	 */
	private function assertRefused(ZgwJwtValidator $validator, string $authorization, string $message): void {
		try {
			$validator->validate($authorization);
			$this->fail('expected a refusal: '.$message);
		} catch (ZgwAuthValidationException $e) {
			$this->assertSame($message, $e->getMessage());
		}

	}//end assertRefused()

	/**
	 * Every refusal, in the order validate() checks them.
	 *
	 * @return void
	 */
	public function testEveryRefusal(): void {
		$now   = time();
		$valid = ['iss' => 'client-a', 'iat' => $now];
		$v     = $this->validator(consumer: $this->consumer());

		$this->assertRefused($this->validator(consumer: null, mapper: false), $this->token($valid), 'Authorization service is unavailable');
		$this->assertRefused($v, 'Bearer ', 'No token has been provided');
		$this->assertRefused($v, 'Bearer a.b', 'Invalid JWT format');
		$this->assertRefused($v, 'Bearer '.$this->b64('{"typ":"JWT"}').'.'.$this->b64('{}').'.x', 'Invalid token header');
		$this->assertRefused($v, 'Bearer '.$this->b64('{"alg":"HS256"}').'.'.$this->b64('"nope"').'.x', 'Invalid token payload');
		$this->assertRefused($v, $this->token(['iat' => $now]), 'No issuer mentioned');
		$this->assertRefused($v, $this->token(['iss' => 'client-b', 'iat' => $now]), 'Unknown issuer');
		$this->assertRefused($v, $this->token($valid, alg: 'RS256'), 'Unsupported token algorithm');
		$this->assertRefused($v, $this->token($valid, secret: 'ander-geheim'), 'The token does not match the shared secret');
		$this->assertRefused($v, $this->token(['iss' => 'client-a']), 'The token has no time of creation');
		$this->assertRefused($v, $this->token(['iss' => 'client-a', 'iat' => $now - 7200]), 'The token has expired');
		$this->assertRefused($v, $this->token(['iss' => 'client-a', 'iat' => $now, 'exp' => $now - 1]), 'The token has expired');
		$this->assertNull($this->bound);

	}//end testEveryRefusal()

	/**
	 * A valid token binds the consumer's user; the consumer's algorithm overrides the header's.
	 *
	 * @return void
	 */
	public function testValidTokenBindsTheConsumersUser(): void {
		$this->validator(consumer: $this->consumer())->validate($this->token(['iss' => 'client-a', 'iat' => time()], alg: 'HS512'));
		$this->assertNotNull($this->bound);

		$this->bound = null;
		$this->validator(consumer: $this->consumer(algorithm: 'HS256'))->validate($this->token(['iss' => 'client-a', 'iat' => time()]));
		$this->assertNotNull($this->bound);

		$this->assertRefused(
			$this->validator(consumer: $this->consumer(algorithm: 'HS384')),
			$this->token(['iss' => 'client-a', 'iat' => time()]),
			'The token does not match the shared secret'
		);

	}//end testValidTokenBindsTheConsumersUser()

	/**
	 * A consumer without a user, or with an unknown user, binds nobody and still passes.
	 *
	 * @return void
	 */
	public function testConsumerWithoutAKnownUserBindsNobody(): void {
		$this->validator(consumer: $this->consumer(userId: ''))->validate($this->token(['iss' => 'client-a', 'iat' => time()]));
		$this->validator(consumer: $this->consumer(userId: 'bob'))->validate($this->token(['iss' => 'client-a', 'iat' => time()]));

		$this->assertNull($this->bound);

	}//end testConsumerWithoutAKnownUserBindsNobody()
}//end class
