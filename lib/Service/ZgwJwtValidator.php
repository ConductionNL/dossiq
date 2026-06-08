<?php

/**
 * ZGW JWT Validator.
 *
 * Self-contained JWT (HMAC) validation for the ZGW API surface.
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Validates ZGW JWT bearer tokens against OpenRegister Consumer credentials.
 *
 * OpenRegister's AuthorizationService::authorizeJwt() is a protected method and
 * cannot be invoked from procest. Calling it externally raises a PHP Error
 * ("Call to protected method") which is not an Exception, so the previous
 * try/catch (\Exception) blocks did not catch it — every authenticated ZGW
 * request therefore failed with a 500. This validator reproduces the JWT HMAC
 * verification contract locally, using the Consumer's stored shared secret
 * (publicKey) and OpenRegister's public validatePayload() for iat/exp checks.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/retrofit-2026-05-24-zgw-api-mapping/tasks.md#task-4
 */
class ZgwJwtValidator
{
    /**
     * Map of JWT algorithm names to hash_hmac algorithm strings.
     *
     * @var array<string, string>
     */
    private const HMAC_MAP = [
        'HS256' => 'sha256',
        'HS384' => 'sha384',
        'HS512' => 'sha512',
    ];

    /**
     * The OpenRegister ConsumerMapper (loaded dynamically).
     *
     * @var object|null
     */
    private $consumerMapper = null;

    /**
     * The OpenRegister AuthorizationService (loaded dynamically, for validatePayload).
     *
     * @var object|null
     */
    private $authorizationService = null;

    /**
     * Constructor.
     *
     * @param LoggerInterface $logger      The logger
     * @param IUserSession    $userSession The user session
     * @param IUserManager    $userManager The user manager
     *
     * @return void
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly IUserSession $userSession,
        private readonly IUserManager $userManager,
    ) {
        $this->loadOpenRegisterServices();
    }//end __construct()

    /**
     * Load OpenRegister services dynamically.
     *
     * @return void
     */
    private function loadOpenRegisterServices(): void
    {
        try {
            $container = \OC::$server;

            $this->consumerMapper       = $container->get('OCA\OpenRegister\Db\ConsumerMapper');
            $this->authorizationService = $container->get('OCA\OpenRegister\Service\AuthorizationService');
        } catch (\Throwable $e) {
            $this->logger->warning(
                'ZgwJwtValidator: OpenRegister services not available',
                ['exception' => $e->getMessage()]
            );
        }
    }//end loadOpenRegisterServices()

    /**
     * Validate a JWT bearer token from an Authorization header.
     *
     * On success the matching Consumer's Nextcloud user is set on the session
     * (mirroring OpenRegister's authorizeJwt behaviour) so downstream object
     * operations run with the correct identity.
     *
     * @param string $authorization The full Authorization header value
     *
     * @return void
     *
     * @throws ZgwAuthValidationException If the token is missing, malformed,
     *                                    or the signature/payload is invalid.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) — sequential JWT validation guards
     * @SuppressWarnings(PHPMD.NPathComplexity)      — sequential JWT validation guards
     *
     * @spec openspec/changes/retrofit-2026-05-24-zgw-api-mapping/tasks.md#task-4
     */
    public function validate(string $authorization): void
    {
        if ($this->consumerMapper === null) {
            throw new ZgwAuthValidationException(message: 'Authorization service is unavailable');
        }

        $token = substr(string: $authorization, offset: strlen(string: 'Bearer '));
        if ($token === '') {
            throw new ZgwAuthValidationException(message: 'No token has been provided');
        }

        $parts = explode(separator: '.', string: $token);
        if (count(value: $parts) !== 3) {
            throw new ZgwAuthValidationException(message: 'Invalid JWT format');
        }

        [$headerB64, $payloadB64, $signatureB64] = $parts;

        $header = json_decode(json: $this->base64urlDecode(data: $headerB64), associative: true);
        if (is_array(value: $header) === false || isset($header['alg']) === false) {
            throw new ZgwAuthValidationException(message: 'Invalid token header');
        }

        $payload = json_decode(json: $this->base64urlDecode(data: $payloadB64), associative: true);
        if (is_array(value: $payload) === false) {
            throw new ZgwAuthValidationException(message: 'Invalid token payload');
        }

        if (isset($payload['iss']) === false || empty($payload['iss']) === true) {
            throw new ZgwAuthValidationException(message: 'No issuer mentioned');
        }

        $consumer = $this->findIssuer(issuer: $payload['iss']);
        if ($consumer === null) {
            throw new ZgwAuthValidationException(message: 'Unknown issuer');
        }

        $authConf  = $consumer->getAuthorizationConfiguration();
        $secret    = $authConf['publicKey'] ?? '';
        $algorithm = $authConf['algorithm'] ?? $header['alg'];

        if (isset(self::HMAC_MAP[$algorithm]) === false) {
            throw new ZgwAuthValidationException(message: 'Unsupported token algorithm');
        }

        $signature = $this->base64urlDecode(data: $signatureB64);
        if ($this->verifyHmac(
                headerB64: $headerB64,
                payloadB64: $payloadB64,
                signature: $signature,
                secret: $secret,
                algorithm: $algorithm
            ) === false
        ) {
            throw new ZgwAuthValidationException(message: 'The token does not match the shared secret');
        }

        // Validate iat/exp via OpenRegister's public payload validator when available,
        // otherwise fall back to a local check.
        $this->validatePayloadTiming(payload: $payload);

        // Mirror OpenRegister: bind the request to the Consumer's user.
        $userId = $consumer->getUserId();
        if ($userId !== null && $userId !== '') {
            $user = $this->userManager->get($userId);
            if ($user !== null) {
                $this->userSession->setUser($user);
            }
        }
    }//end validate()

    /**
     * Validate the iat/exp timing of the payload.
     *
     * Delegates to OpenRegister's public validatePayload() when available; this
     * keeps the expiry window semantics in lock-step with the platform. Falls
     * back to an equivalent local check if the service is unavailable.
     *
     * @param array $payload The decoded JWT payload
     *
     * @return void
     *
     * @throws ZgwAuthValidationException If the token is expired or missing iat.
     */
    private function validatePayloadTiming(array $payload): void
    {
        if ($this->authorizationService !== null
            && method_exists($this->authorizationService, 'validatePayload') === true
        ) {
            try {
                $this->authorizationService->validatePayload($payload);
                return;
            } catch (\Throwable $e) {
                throw new ZgwAuthValidationException(message: $e->getMessage());
            }
        }

        if (isset($payload['iat']) === false) {
            throw new ZgwAuthValidationException(message: 'The token has no time of creation');
        }

        $now = time();
        $exp = ($payload['exp'] ?? ((int) $payload['iat'] + 3600));
        if ((int) $exp < $now) {
            throw new ZgwAuthValidationException(message: 'The token has expired');
        }
    }//end validatePayloadTiming()

    /**
     * Find a Consumer entity by issuer name.
     *
     * @param string $issuer The JWT issuer (maps to Consumer name)
     *
     * @return object|null The Consumer entity or null
     */
    private function findIssuer(string $issuer): ?object
    {
        try {
            $consumers = $this->consumerMapper->findAll(filters: ['name' => $issuer]);
            if (count(value: $consumers) > 0) {
                return $consumers[0];
            }
        } catch (\Throwable $e) {
            $this->logger->warning(
                'ZgwJwtValidator: failed to find consumer for issuer '.$issuer,
                ['exception' => $e->getMessage()]
            );
        }

        return null;
    }//end findIssuer()

    /**
     * Base64url-decode a string per RFC 7515.
     *
     * @param string $data The base64url-encoded string
     *
     * @return string The decoded data
     */
    private function base64urlDecode(string $data): string
    {
        return (string) base64_decode(string: strtr($data, '-_', '+/'));
    }//end base64urlDecode()

    /**
     * Verify an HMAC JWT signature.
     *
     * @param string $headerB64  The base64url-encoded header
     * @param string $payloadB64 The base64url-encoded payload
     * @param string $signature  The raw signature bytes
     * @param string $secret     The HMAC shared secret
     * @param string $algorithm  The JWT algorithm (HS256, HS384, HS512)
     *
     * @return bool True if the signature is valid
     */
    private function verifyHmac(
        string $headerB64,
        string $payloadB64,
        string $signature,
        string $secret,
        string $algorithm
    ): bool {
        $hashAlg = self::HMAC_MAP[$algorithm] ?? null;
        if ($hashAlg === null) {
            return false;
        }

        $expected = hash_hmac($hashAlg, $headerB64.'.'.$payloadB64, $secret, true);
        return hash_equals($expected, $signature);
    }//end verifyHmac()
}//end class
