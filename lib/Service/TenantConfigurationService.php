<?php

/**
 * Procest Tenant Configuration Service
 *
 * Per-tenant configuration: branding, locale, feature flags, custom CSS.
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
 * @spec openspec/changes/tenant-zaaksysteem-saas-08-configuration-branding/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use InvalidArgumentException;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Per-tenant configuration with sanitised branding inputs.
 */
class TenantConfigurationService
{
    /**
     * Allowed locale identifiers (ISO + Dutch defaults).
     *
     * @var array<int, string>
     */
    public const ALLOWED_LOCALES = ['nl_NL', 'nl_BE', 'en_GB', 'en_US', 'fr_FR', 'de_DE'];

    /**
     * Allowed timezone identifiers (subset of IANA — extend as needed).
     *
     * @var array<int, string>
     */
    public const ALLOWED_TIMEZONES = ['Europe/Amsterdam', 'Europe/Brussels', 'Europe/Berlin', 'Europe/Paris', 'UTC'];

    /**
     * Maximum logo size in bytes (5MB).
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

    public function __construct(
        private readonly IAppManager $appManager,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get the full configuration row for a tenant.
     *
     * @param string $tenantId Tenant UUID.
     *
     * @return array<string,mixed>|null
     */
    public function getConfig(string $tenantId): ?array
    {
        $os = $this->getObjectService();
        if ($os === null) {
            return null;
        }

        try {
            $rows = $os->findAll(
                register: TenantSaasService::REGISTER,
                schema: 'tenantConfiguration',
                limit: 1,
                offset: 0,
                filters: ['tenantRef' => $tenantId]
            );
            return (is_array($rows) === true && count($rows) > 0) ? $rows[0] : null;
        } catch (Throwable $e) {
            return null;
        }
    }//end getConfig()

    /**
     * Update branding for a tenant.
     *
     * @param string              $tenantId Tenant UUID.
     * @param array<string,mixed> $branding Branding payload.
     *
     * @return array<string,mixed>
     *
     * @throws InvalidArgumentException When inputs are invalid.
     */
    public function updateBranding(string $tenantId, array $branding): array
    {
        $sanitised = $this->sanitiseBranding($branding);
        return $this->mergeConfig($tenantId, ['branding' => $sanitised]);
    }//end updateBranding()

    /**
     * Update locale-related fields.
     *
     * @param string                                                                        $tenantId Tenant UUID.
     * @param array{locale?:string, timezone?:string, dateFormat?:string, currency?:string} $payload  Payload.
     *
     * @return array<string,mixed>
     *
     * @throws InvalidArgumentException
     */
    public function updateLocale(string $tenantId, array $payload): array
    {
        if (isset($payload['locale']) === true && in_array($payload['locale'], self::ALLOWED_LOCALES, true) === false) {
            throw new InvalidArgumentException('Invalid locale: '.$payload['locale']);
        }

        if (isset($payload['timezone']) === true && in_array($payload['timezone'], self::ALLOWED_TIMEZONES, true) === false) {
            throw new InvalidArgumentException('Invalid timezone: '.$payload['timezone']);
        }

        if (isset($payload['currency']) === true && preg_match('/^[A-Z]{3}$/', $payload['currency']) !== 1) {
            throw new InvalidArgumentException('Invalid currency code: '.$payload['currency']);
        }

        return $this->mergeConfig($tenantId, $payload);
    }//end updateLocale()

    /**
     * Set or unset a feature flag.
     *
     * @param string $tenantId Tenant UUID.
     * @param string $flag     Flag name.
     * @param bool   $enabled  True to add, false to remove.
     *
     * @return array<string,mixed>
     */
    public function setFeatureFlag(string $tenantId, string $flag, bool $enabled): array
    {
        $current  = $this->getConfig($tenantId) ?? ['tenantRef' => $tenantId, 'features' => []];
        $features = (array) ($current['features'] ?? []);
        $features = array_values(array_unique(array_filter($features, fn ($f) => is_string($f) && $f !== '')));
        if ($enabled === true && in_array($flag, $features, true) === false) {
            $features[] = $flag;
        } else if ($enabled === false) {
            $features = array_values(array_filter($features, fn ($f) => $f !== $flag));
        }

        return $this->mergeConfig($tenantId, ['features' => $features]);
    }//end setFeatureFlag()

    /**
     * Build the theming-tokens CSS-variable map from the tenant branding.
     *
     * @param array<string,mixed> $config Configuration row.
     *
     * @return array<string,string> CSS-variable map.
     */
    public function getThemingTokens(array $config): array
    {
        $branding = (array) ($config['branding'] ?? []);
        $tokens   = [];
        if (isset($branding['primaryColor']) === true && $this->isHexColor((string) $branding['primaryColor']) === true) {
            $tokens['--nc-color-primary']         = (string) $branding['primaryColor'];
            $tokens['--nc-color-primary-element'] = (string) $branding['primaryColor'];
        }

        if (isset($branding['secondaryColor']) === true && $this->isHexColor((string) $branding['secondaryColor']) === true) {
            $tokens['--procest-color-secondary'] = (string) $branding['secondaryColor'];
        }

        if (isset($branding['fontFamily']) === true) {
            $ff = (string) $branding['fontFamily'];
            // Drop quotes and dangerous chars.
            $ff = preg_replace('/[^a-zA-Z0-9_\\- ,]/', '', $ff) ?? '';
            $tokens['--procest-font-family'] = $ff;
        }

        return $tokens;
    }//end getThemingTokens()

    /**
     * Sanitise a branding payload — hex-color check, whitelist custom CSS.
     *
     * @param array<string,mixed> $branding Input.
     *
     * @return array<string,mixed> Sanitised.
     *
     * @throws InvalidArgumentException When a hex color is invalid.
     *
     * @spec openspec/changes/procest-security-hardening/specs/security-hardening/spec.md
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
                $this->validateLogoUpload($logoMime, $logoBytes);
            }

            $out['logo'] = (string) $branding['logo'];
        }

        foreach (['primaryColor', 'secondaryColor'] as $colorField) {
            if (isset($branding[$colorField]) === true) {
                $val = (string) $branding[$colorField];
                if ($this->isHexColor($val) === false) {
                    throw new InvalidArgumentException('Invalid hex color for '.$colorField.': '.$val);
                }

                $out[$colorField] = $val;
            }
        }

        if (isset($branding['fontFamily']) === true) {
            $out['fontFamily'] = (string) $branding['fontFamily'];
        }

        if (isset($branding['customCSS']) === true) {
            $out['customCSS'] = $this->sanitiseCustomCss((string) $branding['customCSS']);
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
     */
    public function sanitiseCustomCss(string $css): string
    {
        // Drop dangerous tokens entirely.
        $dangerous = ['url(', 'expression(', '@import', 'javascript:', '<', '>'];
        foreach ($dangerous as $bad) {
            if (stripos($css, $bad) !== false) {
                return '';
            }
        }

        $lines = preg_split('/[\n;]/', $css) ?: [];
        $kept  = [];
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

        return count($kept) > 0 ? (implode('; ', $kept).';') : '';
    }//end sanitiseCustomCss()

    /**
     * Validate that an uploaded logo passes the MIME + size guard.
     *
     * @param string $mimeType Uploaded MIME.
     * @param int    $bytes    Size in bytes.
     *
     * @return void
     *
     * @throws InvalidArgumentException
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
     * @param string $val 6-digit hex (with leading #).
     *
     * @return bool
     */
    public function isHexColor(string $val): bool
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', $val) === 1;
    }//end isHexColor()

    /**
     * @param string              $tenantId Tenant UUID.
     * @param array<string,mixed> $delta    Fields to merge.
     *
     * @return array<string,mixed>
     */
    private function mergeConfig(string $tenantId, array $delta): array
    {
        $os = $this->getObjectService();
        if ($os === null) {
            return ['tenantRef' => $tenantId] + $delta;
        }

        $current = ($this->getConfig($tenantId) ?? ['tenantRef' => $tenantId]);
        $next    = array_merge($current, $delta);
        try {
            $uuid = (string) ($current['uuid'] ?? $current['id'] ?? '');
            return $os->saveObject(
                object: $next,
                register: TenantSaasService::REGISTER,
                schema: 'tenantConfiguration',
                uuid: $uuid !== '' ? $uuid : null
            );
        } catch (Throwable $e) {
            $this->logger->error('Procest: tenantConfiguration save failed', ['exception' => $e->getMessage()]);
            return $next;
        }
    }//end mergeConfig()

    /**
     * @return mixed|null
     */
    private function getObjectService()
    {
        $installed = $this->appManager->getInstalledApps();
        if (is_array($installed) === false || in_array('openregister', $installed, true) === false) {
            return null;
        }

        try {
            return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
        } catch (Throwable $e) {
            return null;
        }
    }//end getObjectService()
}//end class
