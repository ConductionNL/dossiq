<?php

/**
 * Dossiq Portal Assertion Verifier
 *
 * The receiving end of portaliq's endpoint actions (contract v2, A6). When
 * portaliq forwards an action server-to-server it attaches `X-Portal-Subject`:
 * a 60-second HS256 JWT carrying the resolved portal subject. This class
 * verifies it and hands the controller a trusted claim set, the only identity
 * a portal-driven request may use (ADR-005).
 *
 * Copied from the fleet reference (petstore `PortalAssertionVerifier`), with
 * one difference on purpose: the secret is portaliq's dedicated
 * `jwt_signing_secret` and nothing else. portaliq itself refuses to mint
 * without it (`PortalSessionService::__construct()` at portaliq development
 * 0e0cfc8d), so an instance-secret fallback here could only ever accept a
 * token portaliq did not make.
 *
 * Self-contained: no portaliq import, no JWT library. The HMAC check is
 * constant time (hash_equals).
 *
 * @category Portal
 * @package  OCA\Dossiq\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-request-from-a-portal-dossier/specs/portal-contribution/spec.md#requirement-a-resident-starts-a-woo-request-from-the-portal-req-portal-020
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Portal;

use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Verifies portaliq's `X-Portal-Subject` assertion, fail-closed.
 *
 * @spec openspec/changes/woo-request-from-a-portal-dossier/specs/portal-contribution/spec.md#requirement-a-resident-starts-a-woo-request-from-the-portal-req-portal-020
 */
class PortalAssertionVerifier {

	/**
	 * The header portaliq attaches the assertion to.
	 */
	public const HEADER = 'X-Portal-Subject';

	/**
	 * The only accepted algorithm.
	 */
	private const ALG = 'HS256';

	/**
	 * The minting edge.
	 */
	private const ISSUER = 'portaliq';

	/**
	 * The `use` claim of an assertion; a session token has none.
	 */
	private const USE_ASSERTION = 'assertion';

	/**
	 * The app whose config holds the secret: portaliq's, not dossiq's.
	 */
	private const PORTALIQ_APP_ID = 'portaliq';

	/**
	 * portaliq's config key for the signing secret.
	 */
	private const SECRET_KEY = 'jwt_signing_secret';

	/**
	 * portaliq refuses to mint with a shorter secret; dossiq refuses to accept one.
	 */
	private const MIN_SECRET_LENGTH = 16;

	/**
	 * Tolerated clock skew for `iat`, in seconds (the forward is a loopback hop).
	 */
	private const IAT_LEEWAY = 60;

	/**
	 * Constructor.
	 *
	 * @param IConfig|null         $config         Where portaliq's secret lives.
	 * @param string|null          $secretOverride A plain secret, for tests.
	 * @param LoggerInterface|null $logger         Rejection reasons at debug level only.
	 */
	public function __construct(
		private readonly ?IConfig $config = null,
		private readonly ?string $secretOverride = null,
		private readonly ?LoggerInterface $logger = null,
	) {
	}//end __construct()

	/**
	 * The verified claims of an assertion, or null.
	 *
	 * Null unless: three non-empty segments, header `alg` exactly HS256, the
	 * HMAC matches, the claims decode, `use` is `assertion`, `iss` is
	 * `portaliq`, `exp` is in the future, `iat` is not, and `sub` is a
	 * non-empty string. Never throws.
	 *
	 * @param string $jwt The raw header value.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/woo-request-from-a-portal-dossier/specs/portal-contribution/spec.md#requirement-a-resident-starts-a-woo-request-from-the-portal-req-portal-020
	 */
	public function verify(string $jwt): ?array {
		$claims = $this->signedClaims(jwt: $jwt);
		if ($claims === null) {
			return null;
		}

		$refusal = $this->claimRefusal(claims: $claims);
		if ($refusal !== null) {
			return $this->reject(reason: $refusal);
		}

		return $claims;
	}//end verify()

	/**
	 * The claims of a well-formed HS256 token signed with portaliq's secret, or null.
	 *
	 * @param string $jwt The raw token.
	 *
	 * @return array<string, mixed>|null
	 */
	private function signedClaims(string $jwt): ?array {
		$secret = $this->secret();
		if ($secret === null) {
			return $this->reject(reason: 'no usable signing secret');
		}

		$parts = explode('.', $jwt);
		if (count($parts) !== 3 || in_array('', $parts, true) === true) {
			return $this->reject(reason: 'malformed structure');
		}

		[$head, $body, $signature] = $parts;

		$header = json_decode($this->b64UrlDecode(encoded: $head), true);
		if (is_array($header) === false || ($header['alg'] ?? '') !== self::ALG) {
			return $this->reject(reason: 'unexpected algorithm');
		}

		$expected = $this->b64UrlEncode(bytes: hash_hmac('sha256', $head . '.' . $body, $secret, true));
		if (hash_equals($expected, $signature) === false) {
			return $this->reject(reason: 'signature mismatch');
		}

		$claims = json_decode($this->b64UrlDecode(encoded: $body), true);
		if (is_array($claims) === false) {
			return $this->reject(reason: 'malformed claims');
		}

		return $claims;
	}//end signedClaims()

	/**
	 * Why signed claims are still refused, or null when they are an assertion for a subject, now.
	 *
	 * @param array<string, mixed> $claims The signed claims.
	 *
	 * @return string|null
	 */
	private function claimRefusal(array $claims): ?string {
		if (($claims['use'] ?? '') !== self::USE_ASSERTION) {
			return 'not an assertion';
		}

		if (($claims['iss'] ?? '') !== self::ISSUER) {
			return 'unexpected issuer';
		}

		$sub = ($claims['sub'] ?? null);
		if (is_string($sub) === false || $sub === '') {
			return 'missing subject';
		}

		return $this->timeRefusal(exp: ($claims['exp'] ?? null), iat: ($claims['iat'] ?? null));
	}//end claimRefusal()

	/**
	 * Why the validity window is refused, or null when it holds now.
	 *
	 * @param mixed $exp The `exp` claim.
	 * @param mixed $iat The `iat` claim.
	 *
	 * @return string|null
	 */
	private function timeRefusal(mixed $exp, mixed $iat): ?string {
		$now = time();
		if (is_int($exp) === false || $exp <= $now) {
			return 'expired or missing exp';
		}

		if (is_int($iat) === false || $iat > ($now + self::IAT_LEEWAY) || $iat > $exp) {
			return 'implausible iat';
		}

		return null;
	}//end timeRefusal()

	/**
	 * portaliq's dedicated secret, or null when there is no usable one.
	 *
	 * @return string|null
	 */
	private function secret(): ?string {
		$secret = $this->secretOverride;
		if ($secret === null && $this->config !== null) {
			$secret = (string)$this->config->getAppValue(self::PORTALIQ_APP_ID, self::SECRET_KEY, '');
		}

		if ($secret === null || strlen($secret) < self::MIN_SECRET_LENGTH) {
			return null;
		}

		return $secret;
	}//end secret()

	/**
	 * Log a refusal at debug level and answer null.
	 *
	 * @param string $reason Why.
	 *
	 * @return null
	 */
	private function reject(string $reason): null {
		$this->logger?->debug('Dossiq: portal assertion rejected', ['reason' => $reason]);

		return null;
	}//end reject()

	/**
	 * Base64-url encode without padding, as portaliq does.
	 *
	 * @param string $bytes Raw bytes.
	 *
	 * @return string
	 */
	private function b64UrlEncode(string $bytes): string {
		return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
	}//end b64UrlEncode()

	/**
	 * Base64-url decode, as portaliq does.
	 *
	 * @param string $encoded The encoded string.
	 *
	 * @return string
	 */
	private function b64UrlDecode(string $encoded): string {
		$pad = (4 - (strlen($encoded) % 4));
		if ($pad < 4) {
			$encoded .= str_repeat('=', $pad);
		}

		return (string)base64_decode(strtr($encoded, '-_', '+/'));
	}//end b64UrlDecode()
}//end class
