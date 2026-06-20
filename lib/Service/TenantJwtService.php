<?php

/**
 * Procest Tenant JWT Service
 *
 * Self-contained HMAC JWT encode/decode with tenant claim support. Used by
 * the SaaS authentication path to mint and validate tokens carrying
 * `tenant_id` + `tenant_slug` + `roles` claims.
 *
 * HMAC (HS256) is deliberate — it lines up with the existing
 * `ZgwJwtValidator` shape and the OpenRegister Consumer secret model.
 * The signing secret comes from procest configuration (never from the
 * request) so a forged signature cannot pass verification.
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/tenant-zaaksysteem-saas-05-auth-jwt-tenant-claim/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use InvalidArgumentException;
use RuntimeException;

/**
 * HMAC-based JWT minting + validation with first-class tenant claim support.
 */
class TenantJwtService
{
    /**
     * HMAC algorithm — HS256 by default.
     */
    public const ALG = 'HS256';

    /**
     * Hash function name passed to hash_hmac.
     */
    private const HASH_FN = 'sha256';

    /**
     * Token validity window in seconds (default 1 hour).
     */
    public const DEFAULT_TTL = 3600;

    /**
     * Constructor.
     *
     * @param string $signingSecret Server-side HMAC signing secret (>= 32 chars).
     */
    public function __construct(
        private readonly string $signingSecret,
    ) {
        if (strlen($this->signingSecret) < 16) {
            throw new InvalidArgumentException('JWT signing secret too short (<16 chars)');
        }
    }//end __construct()

    /**
     * Encode a tenant-aware JWT.
     *
     * @param string             $subject    Subject (NC user ID).
     * @param string             $tenantId   Tenant UUID.
     * @param string             $tenantSlug Tenant slug.
     * @param array<int, string> $roles      Roles inside the tenant.
     * @param int|null           $ttl        Override default TTL (seconds).
     *
     * @return string Compact JWT string.
     */
    public function createToken(string $subject, string $tenantId, string $tenantSlug, array $roles=[], ?int $ttl=null): string
    {
        $iat = time();
        $exp = $iat + ($ttl ?? self::DEFAULT_TTL);

        $header = ['alg' => self::ALG, 'typ' => 'JWT'];
        $claims = [
            'sub'         => $subject,
            'tenant_id'   => $tenantId,
            'tenant_slug' => $tenantSlug,
            'roles'       => array_values($roles),
            'iat'         => $iat,
            'exp'         => $exp,
            'iss'         => 'procest',
        ];

        $hPart = $this->b64UrlEncode(bytes: (string) json_encode($header, JSON_UNESCAPED_SLASHES));
        $cPart = $this->b64UrlEncode(bytes: (string) json_encode($claims, JSON_UNESCAPED_SLASHES));
        $sig   = $this->b64UrlEncode(bytes: $this->signRaw(input: $hPart.'.'.$cPart));
        return $hPart.'.'.$cPart.'.'.$sig;
    }//end createToken()

    /**
     * Build a tenant-scoped JWT from a (mocked / decoded) eHerkenning assertion.
     *
     * The assertion is expected to carry: `subject`, `eherkenningLevel`,
     * `tenantId`, `tenantSlug`, `roles`.
     *
     * @param array<string, mixed> $assertion eHerkenning assertion payload.
     *
     * @return string Compact JWT.
     */
    public function createTokenFromSaml(array $assertion): string
    {
        $required = ['subject', 'tenantId', 'tenantSlug'];
        foreach ($required as $field) {
            if (isset($assertion[$field]) === false || $assertion[$field] === '') {
                throw new InvalidArgumentException('SAML assertion missing field: '.$field);
            }
        }

        $roles = (array) ($assertion['roles'] ?? []);
        if (isset($assertion['eherkenningLevel']) === true) {
            $roles[] = 'eh:level:'.$assertion['eherkenningLevel'];
        }

        return $this->createToken(
            subject: (string) $assertion['subject'],
            tenantId: (string) $assertion['tenantId'],
            tenantSlug: (string) $assertion['tenantSlug'],
            roles: $roles,
        );
    }//end createTokenFromSaml()

    /**
     * Validate a JWT and return its claims.
     *
     * @param string $token Compact JWT.
     *
     * @return array<string,mixed> Claim set.
     *
     * @throws RuntimeException When the token is malformed, the signature
     *                          does not match, or the token is expired.
     */
    public function validate(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new RuntimeException('Malformed JWT');
        }

        [$hPart, $cPart, $sPart] = $parts;

        $expected = $this->b64UrlEncode(bytes: $this->signRaw(input: $hPart.'.'.$cPart));
        if (hash_equals($expected, $sPart) === false) {
            throw new RuntimeException('Invalid JWT signature');
        }

        $claims = json_decode($this->b64UrlDecode(encoded: $cPart), true);
        if (is_array($claims) === false) {
            throw new RuntimeException('Malformed JWT claims');
        }

        if (isset($claims['exp']) === true && (int) $claims['exp'] < time()) {
            throw new RuntimeException('Expired JWT');
        }

        return $claims;
    }//end validate()

    /**
     * Extract the `tenant_id` claim from a (validated) claim set.
     *
     * @param array<string,mixed> $claims Validated claim set.
     *
     * @return string
     *
     * @throws RuntimeException When the claim is missing.
     */
    public function extractTenantId(array $claims): string
    {
        $tid = (string) ($claims['tenant_id'] ?? '');
        if ($tid === '') {
            throw new RuntimeException('JWT missing tenant_id claim');
        }

        return $tid;
    }//end extractTenantId()

    /**
     * Raw HMAC of the signing input.
     *
     * @param string $input Signing input (header.payload).
     *
     * @return string Raw HMAC.
     */
    private function signRaw(string $input): string
    {
        return hash_hmac(self::HASH_FN, $input, $this->signingSecret, true);
    }//end signRaw()

    /**
     * Base64-url encode (no padding).
     *
     * @param string $bytes Raw bytes.
     *
     * @return string
     */
    private function b64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }//end b64UrlEncode()

    /**
     * Base64-url decode.
     *
     * @param string $encoded Encoded string.
     *
     * @return string
     */
    private function b64UrlDecode(string $encoded): string
    {
        $pad = 4 - (strlen($encoded) % 4);
        if ($pad < 4) {
            $encoded .= str_repeat('=', $pad);
        }

        return (string) base64_decode(strtr($encoded, '-_', '+/'));
    }//end b64UrlDecode()
}//end class
