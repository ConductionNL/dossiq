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
 * admin settings panel or via `occ config:app:set dossiq dso_lv_auth_token`.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T03
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\AppInfo\Application;
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
class DsoLvAuthService {

	/**
	 * App config key for the DSO-LV bearer token.
	 */
	private const CONFIG_KEY_AUTH_TOKEN = 'dso_lv_auth_token';

	/**
	 * App config key for the DSO base URL (config-ready seam,
	 * external-integrations-test-environments). The pre-productie
	 * (oefenomgeving) endpoint is
	 * `https://service.pre.omgevingswet.overheid.nl`; it is
	 * certificate-bound (PKIoverheid OIN/HRN) and reached only after the
	 * DSO aansluittraject grants a client_id + test key, so it stays
	 * UNSET by default and callers keep their compiled-in default.
	 */
	private const CONFIG_KEY_BASE_URL = 'integration.dso.baseUrl';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The application config
	 * @param LoggerInterface $logger The logger
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
	public function getAuthHeaders(): array {
		if ($this->isAuthConfigured() === false) {
			$this->logger->warning(
				'Dossiq DsoLvAuthService: dso_lv_auth_token is not configured. '
				. 'Outbound DSO-LV calls will be unauthenticated. '
				. 'Configure via occ config:app:set dossiq dso_lv_auth_token --value <token>.',
				['app' => Application::APP_ID]
			);
			return [];
		}

		$token = $this->appConfig->getValueString(
			app: Application::APP_ID,
			key: self::CONFIG_KEY_AUTH_TOKEN,
			default: ''
		);

		return ['Authorization' => 'Bearer ' . $token];
	}//end getAuthHeaders()

	/**
	 * Return whether outbound DSO-LV authentication is configured.
	 *
	 * @return bool True when a bearer token has been set in app config
	 *
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T03
	 */
	public function isAuthConfigured(): bool {
		return $this->appConfig->getValueString(
			app: Application::APP_ID,
			key: self::CONFIG_KEY_AUTH_TOKEN,
			default: ''
		) !== '';
	}//end isAuthConfigured()

	/**
	 * Return the configured DSO base URL, or the supplied default.
	 *
	 * Config-ready seam: when the DSO aansluittraject grants pre-prod
	 * access (client_id + test key + PKIoverheid cert), an operator sets
	 * `integration.dso.baseUrl` to `https://service.pre.omgevingswet.overheid.nl`
	 * without a code change. Unset by default — DSO calls keep their
	 * compiled-in endpoint and no external pre-prod call happens
	 * unknowingly.
	 *
	 * @param string $default Fallback base URL when unconfigured.
	 *
	 * @return string The configured base URL, or $default.
	 *
	 * @spec openspec/specs/external-integration-test-wiring/spec.md
	 */
	public function getBaseUrl(string $default = ''): string {
		$configured = $this->appConfig->getValueString(
			app: Application::APP_ID,
			key: self::CONFIG_KEY_BASE_URL,
			default: ''
		);

		if ($configured !== '') {
			return $configured;
		}

		return $default;
	}//end getBaseUrl()
}//end class
