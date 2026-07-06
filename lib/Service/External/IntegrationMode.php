<?php

/**
 * Procest external-integration tier selector (external-integrations-test-environments).
 *
 * Single source of truth for the per-integration config-tier model.
 * Each external seam reads one `integration.<name>.mode` app-config key
 * to choose its adapter tier; the DEFAULT for every seam is `log`
 * (dormant), so a fresh install NEVER makes an unknowing external call.
 * An unknown/unset mode also falls back to `log` (fail-closed).
 *
 * Tiers:
 *   - `log`       dormant Log adapter (default) — no external call
 *   - `mock`      offline mock (e.g. ghcr.io/brp-api/personen-mock)
 *   - `test`      hosted test environment (BRP proefomgeving, api.kvk.nl/test)
 *   - `simulator` local auth simulator (DigiD/eHerkenning, capped at beta)
 *   - `preprod`   official preproductie (certificate-bound, manual/gated)
 *   - `live`      production (customer-side aansluiting — out of scope here)
 *
 * When `pluggable-integration-registry` lands, adapter selection moves
 * behind that registry; until then this factory-config pattern binds the
 * tier in Application::register() (DC02).
 *
 * @category Service
 * @package  OCA\Procest\Service\External
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://procest.nl
 *
 * @spec openspec/specs/external-integration-test-wiring/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Service\External;

use OCP\IAppConfig;

/**
 * Reads the per-integration `integration.<name>.mode` config tier.
 *
 * @spec openspec/specs/external-integration-test-wiring/spec.md
 */
final class IntegrationMode
{
    /**
     * Procest app id.
     */
    public const APP_ID = 'procest';

    /**
     * Dormant tier — no external call. Default for every seam.
     */
    public const LOG = 'log';

    /**
     * Offline mock tier (docker mock).
     */
    public const MOCK = 'mock';

    /**
     * Hosted test-environment tier.
     */
    public const TEST = 'test';

    /**
     * Local auth-simulator tier (DigiD/eHerkenning; capped at beta).
     */
    public const SIMULATOR = 'simulator';

    /**
     * Official preproductie tier (certificate-bound, manual/gated).
     */
    public const PREPROD = 'preprod';

    /**
     * Production tier (customer-side aansluiting).
     */
    public const LIVE = 'live';

    /**
     * Constructor.
     *
     * @param IAppConfig $appConfig App-config accessor.
     */
    public function __construct(private readonly IAppConfig $appConfig)
    {
    }//end __construct()

    /**
     * Resolve the configured tier for an integration, defaulting to
     * `log` (fail-closed to no external call) when unset or unknown.
     *
     * @param string        $integration Integration name (e.g. `brp`, `kvk`, `digid`).
     * @param array<string> $allowed     The tiers this integration accepts.
     *
     * @return string One of the allowed tiers, or `log`.
     */
    public function resolve(string $integration, array $allowed): string
    {
        $raw = $this->appConfig->getValueString(
            self::APP_ID,
            'integration.'.$integration.'.mode',
            self::LOG
        );

        $mode = strtolower(trim($raw));
        if (in_array($mode, $allowed, true) === true) {
            return $mode;
        }

        return self::LOG;

    }//end resolve()

    /**
     * Read an integration string setting (e.g. baseUrl, apiKey).
     *
     * @param string $integration Integration name.
     * @param string $key         Setting suffix (e.g. `baseUrl`).
     * @param string $default     Fallback value.
     *
     * @return string
     */
    public function setting(string $integration, string $key, string $default=''): string
    {
        $raw = $this->appConfig->getValueString(
            self::APP_ID,
            'integration.'.$integration.'.'.$key,
            $default
        );

        return trim($raw);

    }//end setting()
}//end class
