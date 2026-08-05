<?php

/**
 * Procest tenant branding sanitiser.
 *
 * Owns the fail-closed validation of every tenant-supplied branding input:
 * the hex-colour check on primary/secondary colours, the MIME-type + 5 MB
 * guard on an uploaded logo, and the whitelist-based custom-CSS sanitiser.
 * Split out of TenantConfigurationService so that service keeps configuration
 * *storage* — read, merge, persist — while the rules that decide what a tenant
 * is allowed to inject into other users' pages live in one auditable place.
 *
 * Both CSS rules are deliberately whitelist-shaped rather than blacklist-
 * shaped: an unrecognised property is dropped and a sheet containing any
 * dangerous token is discarded whole, so a novel injection vector fails closed
 * instead of passing through unrecognised.
 *
 * @category Service
 * @package  OCA\Procest\Service\Tenant
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/security-hardening/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Tenant;

use InvalidArgumentException;

/**
 * Fail-closed validation of tenant-supplied branding input.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/security-hardening/spec.md
 */
class TenantBrandingSanitiser
{
    /**
     * Maximum logo size in bytes (5MB).
     *
     * @var int
     */
    public const LOGO_MAX_BYTES = 5_242_880;

    /**
     * Allowed logo MIME types.
     *
     * @var array<int, string>
     */
    public const LOGO_ALLOWED_MIME = ['image/png', 'image/jpeg', 'image/svg+xml', 'image/webp'];

    /**
     * Custom-CSS property whitelist (sanitiser).
     *
     * @var array<int, string>
     */
    public const CSS_PROPERTY_WHITELIST = [
        'color',
        'background-color',
        'border-color',
        'font-family',
        'font-size',
        'font-weight',
        'border-radius',
        'padding',
        'margin',
        '--nc-color-primary',
        '--nc-color-primary-element',
        '--nc-color-text',
        '--nc-border-radius',
    ];

    /**
     * CSS tokens that discard the whole sheet when present.
     *
     * @var array<int, string>
     */
    private const CSS_DANGEROUS_TOKENS = ['url(', 'expression(', '@import', 'javascript:', '<', '>'];

    /**
     * Sanitise a branding payload — hex-color check, whitelist custom CSS.
     *
     * @param array<string,mixed> $branding Input.
     *
     * @return array<string,mixed> Sanitised.
     *
     * @throws InvalidArgumentException When a hex color is invalid, or the logo
     *                                 upload fails the MIME/size guard.
     *
     * @spec openspec/specs/security-hardening/spec.md
     */
    public function sanitiseBranding(array $branding): array
    {
        $out = [];
        if (isset($branding['logo']) === true) {
            // Fail closed: when the upload carries MIME/size metadata, enforce
            // the logo MIME-type + 5 MB guard before accepting it.
            // validateLogoUpload() throws InvalidArgumentException on a
            // disallowed MIME type or an oversized file.
            $logoMime  = (string) ($branding['logoMimeType'] ?? '');
            $logoBytes = (int) ($branding['logoBytes'] ?? 0);
            if ($logoMime !== '' || $logoBytes > 0) {
                $this->validateLogoUpload(mimeType: $logoMime, bytes: $logoBytes);
            }

            $out['logo'] = (string) $branding['logo'];
        }

        foreach (['primaryColor', 'secondaryColor'] as $colorField) {
            if (isset($branding[$colorField]) === true) {
                $val = (string) $branding[$colorField];
                if ($this->isHexColor(val: $val) === false) {
                    throw new InvalidArgumentException('Invalid hex color for '.$colorField.': '.$val);
                }

                $out[$colorField] = $val;
            }
        }

        if (isset($branding['fontFamily']) === true) {
            $out['fontFamily'] = (string) $branding['fontFamily'];
        }

        if (isset($branding['customCSS']) === true) {
            $out['customCSS'] = $this->sanitiseCustomCss(css: (string) $branding['customCSS']);
        }

        return $out;
    }//end sanitiseBranding()

    /**
     * Whitelist-based CSS sanitiser. Strips any rule with a property not in
     * the whitelist or a value containing `url(`, `@import`, `expression`.
     *
     * @param string $css Raw CSS.
     *
     * @return string Sanitised CSS.
     *
     * @spec openspec/specs/security-hardening/spec.md
     */
    public function sanitiseCustomCss(string $css): string
    {
        // Drop dangerous tokens entirely.
        foreach (self::CSS_DANGEROUS_TOKENS as $bad) {
            if (stripos($css, $bad) !== false) {
                return '';
            }
        }

        $lines = preg_split('/[\n;]/', $css);
        if ($lines === false) {
            $lines = [];
        }

        $kept = [];
        foreach ($lines as $line) {
            $trim = trim($line);
            if ($trim === '') {
                continue;
            }

            $parts = explode(':', $trim, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $prop = trim(strtolower($parts[0]));
            $val  = trim($parts[1]);
            if (in_array($prop, self::CSS_PROPERTY_WHITELIST, true) === false) {
                continue;
            }

            $kept[] = $prop.': '.$val;
        }

        if (count($kept) > 0) {
            return implode('; ', $kept).';';
        }

        return '';
    }//end sanitiseCustomCss()

    /**
     * Validate that an uploaded logo passes the MIME + size guard.
     *
     * @param string $mimeType Uploaded MIME.
     * @param int    $bytes    Size in bytes.
     *
     * @return void
     *
     * @throws InvalidArgumentException When the MIME type is not allowed or the
     *                                 file exceeds the 5 MB ceiling.
     *
     * @spec openspec/specs/security-hardening/spec.md
     */
    public function validateLogoUpload(string $mimeType, int $bytes): void
    {
        if (in_array($mimeType, self::LOGO_ALLOWED_MIME, true) === false) {
            throw new InvalidArgumentException('Unsupported logo MIME type: '.$mimeType);
        }

        if ($bytes > self::LOGO_MAX_BYTES) {
            throw new InvalidArgumentException('Logo exceeds 5MB');
        }
    }//end validateLogoUpload()

    /**
     * Check whether a string is a 6-digit hex color.
     *
     * @param string $val 6-digit hex (with leading #).
     *
     * @return bool True when the value is exactly `#rrggbb`.
     *
     * @spec openspec/specs/security-hardening/spec.md
     */
    public function isHexColor(string $val): bool
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', $val) === 1;
    }//end isHexColor()
}//end class
