<?php

/**
 * The OpenRegister consumer behind a ZGW API request
 *
 * A calling system presents one of three credentials: a bearer JWT signed
 * with its consumer's shared secret, an API key, or a client certificate
 * whose fingerprint a trusted reverse proxy forwards. Each resolves to the
 * same OpenRegister consumer, and that consumer's Nextcloud user is bound to
 * the request exactly once. The first credential present decides: a request
 * whose JWT fails is refused, it is not retried with an API key, so a failure
 * stays explainable (design D1).
 *
 * Dossiq keeps no consumer, key or certificate store of its own and caches
 * nothing between requests, so a consumer removed in OpenRegister stops
 * resolving on the next request (design D3).
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Zgw
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
 * @spec openspec/changes/public-api-access-with-keys-and-certificates/specs/public-api-access/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Zgw;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\ZgwAuthValidationException;
use OCA\Dossiq\Service\ZgwJwtValidator;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\Ip\IFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves a request's credential to an OpenRegister consumer and binds its user.
 *
 * @spec openspec/changes/public-api-access-with-keys-and-certificates/specs/public-api-access/spec.md
 */
class ApiCredentialResolver {

	/**
	 * The header an API key travels in.
	 *
	 * @var string
	 */
	public const API_KEY_HEADER = 'X-Api-Key';

	/**
	 * The admin setting naming the header a trusted proxy forwards the client
	 * certificate's SHA-256 fingerprint in. Empty switches certificate access off.
	 *
	 * @var string
	 */
	public const CERT_HEADER_KEY = 'public_api_client_cert_header';

	/**
	 * OpenRegister's ConsumerMapper, looked up on first use when not injected.
	 *
	 * @var object|null
	 */
	private ?object $consumerMapper;

	/**
	 * The consumer already resolved for a request, so a second caller in the
	 * same request (the middleware, then a controller) neither resolves nor
	 * binds again.
	 *
	 * @var \WeakMap<IRequest, object>|null
	 */
	private ?\WeakMap $resolved = null;

	/**
	 * What a refusal says, per resolver or validator message: the kind of
	 * credential that failed and why, never the credential itself. A token
	 * refusal other than expiry says only that the token is not valid, so the
	 * answer cannot be used to find out which issuers exist.
	 *
	 * @var array<string, string>
	 */
	private const REFUSALS = [
		'Unknown API key' => 'The API key is not known.',
		'Untrusted certificate header' => 'The certificate header does not come from a trusted proxy.',
		'Unknown certificate' => 'The certificate is not known.',
		'Unsupported authorization scheme' => 'The authorization scheme is not supported.',
		'Authorization service is unavailable' => 'The authorization service is unavailable.',
	];

	/**
	 * Constructor.
	 *
	 * @param ZgwJwtValidator $jwtValidator   Decodes and verifies a bearer JWT.
	 * @param SettingsService $settings       Reads the certificate header setting.
	 * @param IConfig         $config         Reads Nextcloud's `trusted_proxies`.
	 * @param IFactory        $ipFactory      Matches an address against a proxy range.
	 * @param IUserManager    $userManager    Finds the consumer's user.
	 * @param IUserSession    $userSession    Binds the consumer's user.
	 * @param LoggerInterface $logger         Logger.
	 * @param object|null     $consumerMapper OpenRegister's ConsumerMapper; looked up on first use when null.
	 */
	public function __construct(
		private readonly ZgwJwtValidator $jwtValidator,
		private readonly SettingsService $settings,
		private readonly IConfig $config,
		private readonly IFactory $ipFactory,
		private readonly IUserManager $userManager,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		?object $consumerMapper = null,
	) {
		$this->consumerMapper = $consumerMapper;
	}//end __construct()

	/**
	 * The consumer behind the request, with its user bound, or null when the
	 * request carries no credential at all.
	 *
	 * @param IRequest $request The request.
	 *
	 * @return object|null The OpenRegister consumer, null without any credential.
	 *
	 * @throws ZgwAuthValidationException When a credential is present but does not resolve;
	 *                                    the message names the credential kind, never its value.
	 *
	 * @spec openspec/changes/public-api-access-with-keys-and-certificates/specs/public-api-access/spec.md#requirement-the-caller-may-present-a-jwt-an-api-key-or-a-client-certificate-req-paa-02
	 */
	public function resolve(IRequest $request): ?object {
		$this->resolved ??= new \WeakMap();
		if (isset($this->resolved[$request]) === true) {
			return $this->resolved[$request];
		}

		$consumer = $this->consumerOf(request: $request);
		if ($consumer !== null) {
			$this->bindConsumerUser(userId: $consumer->getUserId());
			$this->resolved[$request] = $consumer;
		}

		return $consumer;
	}//end resolve()

	/**
	 * The message a refused request answers with, for the failure the
	 * resolver or the token validator raised.
	 *
	 * @param \Throwable $failure The refusal.
	 *
	 * @return string The kind of credential that failed and why, without its value.
	 *
	 * @spec openspec/changes/public-api-access-with-keys-and-certificates/tasks.md#2.2
	 */
	public static function refusalMessage(\Throwable $failure): string {
		$message = $failure->getMessage();
		if (isset(self::REFUSALS[$message]) === true) {
			return self::REFUSALS[$message];
		}

		if (stripos($message, 'expired') !== false) {
			return 'The token has expired.';
		}

		return 'The token is not valid.';
	}//end refusalMessage()

	/**
	 * The consumer of the first credential the request carries.
	 *
	 * @param IRequest $request The request.
	 *
	 * @return object|null The consumer, null without any credential.
	 *
	 * @throws ZgwAuthValidationException When the credential present does not resolve.
	 */
	private function consumerOf(IRequest $request): ?object {
		$authorization = trim($request->getHeader('Authorization'));
		if (stripos($authorization, 'Bearer ') === 0) {
			return $this->jwtValidator->consumerFor(authorization: $authorization);
		}

		$apiKey = trim($request->getHeader(self::API_KEY_HEADER));
		if ($apiKey === '' && stripos($authorization, 'ApiKey ') === 0) {
			$apiKey = trim(substr($authorization, strlen('ApiKey ')));
		}

		if ($apiKey !== '') {
			return $this->apiKeyConsumer(apiKey: $apiKey);
		}

		if ($authorization !== '') {
			throw new ZgwAuthValidationException(message: 'Unsupported authorization scheme');
		}

		$certHeader = trim($this->settings->getConfigValue(self::CERT_HEADER_KEY));
		if ($certHeader === '') {
			return null;
		}

		$fingerprint = trim($request->getHeader($certHeader));
		if ($fingerprint === '') {
			return null;
		}

		if ($this->fromTrustedProxy(remoteAddress: $this->remoteAddress()) === false) {
			throw new ZgwAuthValidationException(message: 'Untrusted certificate header');
		}

		return $this->certificateConsumer(fingerprint: $fingerprint);
	}//end consumerOf()

	/**
	 * The consumer of type `apiKey` whose stored key equals the presented one.
	 *
	 * @param string $apiKey The presented key, never empty here.
	 *
	 * @return object The consumer.
	 *
	 * @throws ZgwAuthValidationException When no consumer holds the key.
	 */
	private function apiKeyConsumer(string $apiKey): object {
		foreach ($this->consumers() as $consumer) {
			if (strtolower((string)$consumer->getAuthorizationType()) !== 'apikey') {
				continue;
			}

			$stored = ($consumer->getAuthorizationConfiguration()['apiKey'] ?? '');
			if (is_string($stored) === true && $stored !== '' && hash_equals($stored, $apiKey) === true) {
				return $consumer;
			}
		}

		throw new ZgwAuthValidationException(message: 'Unknown API key');
	}//end apiKeyConsumer()

	/**
	 * The consumer whose stored certificate fingerprint equals the forwarded one.
	 *
	 * @param string $fingerprint The forwarded SHA-256 fingerprint, hex, any case, colons allowed.
	 *
	 * @return object The consumer.
	 *
	 * @throws ZgwAuthValidationException When no consumer holds the fingerprint.
	 */
	private function certificateConsumer(string $fingerprint): object {
		$presented = self::normalisedFingerprint(fingerprint: $fingerprint);
		if ($presented !== '') {
			foreach ($this->consumers() as $consumer) {
				$stored = self::normalisedFingerprint(
					fingerprint: (string)($consumer->getAuthorizationConfiguration()['certificateFingerprint'] ?? '')
				);
				if ($stored !== '' && hash_equals($stored, $presented) === true) {
					return $consumer;
				}
			}
		}

		throw new ZgwAuthValidationException(message: 'Unknown certificate');
	}//end certificateConsumer()

	/**
	 * A fingerprint as lowercase hex without separators, '' when it is not hex.
	 *
	 * @param string $fingerprint The fingerprint as stored or forwarded.
	 *
	 * @return string The normalised fingerprint.
	 */
	private static function normalisedFingerprint(string $fingerprint): string {
		$hex = strtolower(str_replace([':', ' '], '', trim($fingerprint)));
		if (preg_match('/^[0-9a-f]{64}$/', $hex) !== 1) {
			return '';
		}

		return $hex;
	}//end normalisedFingerprint()

	/**
	 * Whether the address the request came from is one of Nextcloud's trusted proxies.
	 *
	 * @param string $remoteAddress The connecting address.
	 *
	 * @return bool True when a `trusted_proxies` entry (address or range) contains it.
	 */
	private function fromTrustedProxy(string $remoteAddress): bool {
		if ($remoteAddress === '') {
			return false;
		}

		$proxies = $this->config->getSystemValue('trusted_proxies', []);
		if (is_array($proxies) === false) {
			return false;
		}

		foreach ($proxies as $proxy) {
			try {
				$range = $this->ipFactory->rangeFromString((string)$proxy);
				if ($range->contains($this->ipFactory->addressFromString($remoteAddress)) === true) {
					return true;
				}
			} catch (Throwable $e) {
				$this->logger->debug('ApiCredentialResolver: a trusted_proxies entry could not be read', ['error' => $e->getMessage()]);
			}
		}

		return false;
	}//end fromTrustedProxy()

	/**
	 * The address of the connection itself, not a forwarded client address.
	 *
	 * `IRequest::getRemoteAddress()` answers the forwarded client once the
	 * connection comes from a trusted proxy, which is exactly the address this
	 * check must not use. Protected so a test can name the connection.
	 *
	 * @return string The connecting address, '' when unknown.
	 */
	protected function remoteAddress(): string {
		return (string)($_SERVER['REMOTE_ADDR'] ?? '');
	}//end remoteAddress()

	/**
	 * Every consumer OpenRegister holds.
	 *
	 * @return array<int, object> The consumers.
	 *
	 * @throws ZgwAuthValidationException When OpenRegister cannot be asked.
	 */
	private function consumers(): array {
		try {
			$this->consumerMapper ??= \OC::$server->get('OCA\OpenRegister\Db\ConsumerMapper');
			return array_values((array)$this->consumerMapper->findAll());
		} catch (Throwable $e) {
			// No OpenRegister, or its consumers cannot be read: refuse, never pass.
			$this->logger->warning('ApiCredentialResolver: the consumers could not be read', ['error' => $e->getMessage()]);
			throw new ZgwAuthValidationException(message: 'Authorization service is unavailable');
		}
	}//end consumers()

	/**
	 * Bind the consumer's Nextcloud user to the request, when it has a known one.
	 *
	 * @param string|null $userId The consumer's user id.
	 *
	 * @return void
	 */
	private function bindConsumerUser(?string $userId): void {
		if ($userId === null || $userId === '') {
			return;
		}

		$user = $this->userManager->get($userId);
		if ($user !== null) {
			$this->userSession->setUser($user);
		}
	}//end bindConsumerUser()

}//end class
