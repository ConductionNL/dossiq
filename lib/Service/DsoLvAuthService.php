<?php

/**
 * DSO-LV Authentication Service
 *
 * Provides HTTP authentication headers for outbound calls to the Digitaal
 * Stelsel Omgevingswet Landelijke Voorziening (DSO-LV) API. Reads a
 * configurable bearer token from app settings; full OAuth2 client-credentials
 * and PKIoverheid mTLS support require a dedicated follow-up spec — this
 * service provides the configuration hook so operators can supply a sandbox
 * bearer token without code changes.
 *
 * NOTE: Production DSO-LV requires OAuth2 with OIN-bound PKIoverheid
 * certificate (mTLS). Configuring only a bearer token is sufficient for
 * sandbox/test environments. Set key 'dso_lv_auth_token' via the Nextcloud
 * admin settings panel or via `occ config:app:set procest dso_lv_auth_token`.
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T03
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCA\Procest\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Provides authentication headers for outbound DSO-LV API requests.
 *
 * Reads a bearer token from app config key 'dso_lv_auth_token'. Returns
 * empty headers and logs a warning when auth is not configured.
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T03
 */
class DsoLvAuthService
{

    /**
     * App config key for the DSO-LV bearer token.
     */
    private const CONFIG_KEY_AUTH_TOKEN = 'dso_lv_auth_token';

    /**
     * Constructor.
     *
     * @param IAppConfig      $appConfig The application config
     * @param LoggerInterface $logger    The logger
     */
    public function __construct(
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Return HTTP headers for authenticating outbound DSO-LV API requests.
     *
     * Returns a Bearer Authorization header when a token is configured, or
     * an empty array with a warning log when auth is not yet configured.
     * Callers must merge these headers into every outbound HTTP request to
     * DSO-LV.
     *
     * @return array<string,string> HTTP header key-value pairs
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#T03
     */
    public function getAuthHeaders(): array
    {
        $token = $this->appConfig->getValueString(
            app: Application::APP_ID,
            key: self::CONFIG_KEY_AUTH_TOKEN,
            default: ''
        );

        if ($token === '') {
            $this->logger->warning(
                'Procest DsoLvAuthService: dso_lv_auth_token is not configured. '
                .'Outbound DSO-LV calls will be unauthenticated. '
                .'Configure via occ config:app:set procest dso_lv_auth_token --value <token>.',
                ['app' => Application::APP_ID]
            );
            return [];
        }

        return ['Authorization' => 'Bearer '.$token];
    }//end getAuthHeaders()

    /**
     * Return whether outbound DSO-LV authentication is configured.
     *
     * @return bool True when a bearer token has been set in app config
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#T03
     */
    public function isAuthConfigured(): bool
    {
        return $this->appConfig->getValueString(
            app: Application::APP_ID,
            key: self::CONFIG_KEY_AUTH_TOKEN,
            default: ''
        ) !== '';
    }//end isAuthConfigured()
}//end class
