<?php

/**
 * Dossiq Preferences Controller
 *
 * Generic per-user key/value preferences, backed by Nextcloud IConfig user
 * values. Used by shared @conduction/nextcloud-vue widgets (e.g.
 * CnSupportDialog's "seen" flag, CnAppRoot's walkthrough progress) that need
 * to persist a small per-user UI flag cross-device without a bespoke
 * endpoint per feature. The route is provided by the OpenRegister AppHost
 * canonical route table (ADR-040); Nextcloud resolves it to this
 * app-namespaced class by name, so the class must exist here even though the
 * route array itself is shared.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
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
 * @link https://conduction.nl
 *
 * @spec openspec/specs/admin-settings/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Per-user preferences controller.
 */
class PreferencesController extends Controller {

	/**
	 * Maximum allowed byte length for a preference value (DoS guard).
	 *
	 * Values exceeding this limit are rejected with HTTP 400 to prevent
	 * unbounded database row growth on oc_preferences.
	 *
	 * @var int
	 */
	private const MAX_VALUE_LENGTH = 8192;

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param IConfig $config The Nextcloud config (user values).
	 * @param IUserSession $userSession The user session.
	 */
	public function __construct(
		IRequest $request,
		private readonly IConfig $config,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * Read a per-user preference value.
	 *
	 * @param string $key The preference key (letters, digits, `.`, `_`, `-`).
	 *
	 * @return JSONResponse `{value: string|null}`.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	#[NoAdminRequired]
	public function getPreference(string $key): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Not logged in'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$safeKey = $this->sanitizeKey(key: $key);
		if ($safeKey === '') {
			return new JSONResponse(data: ['message' => 'Invalid key'], statusCode: Http::STATUS_BAD_REQUEST);
		}

		$value = $this->config->getUserValue(
			userId: $user->getUID(),
			appName: Application::APP_ID,
			key: 'pref_' . $safeKey,
			default: ''
		);

		$stored = null;
		if ($value !== '') {
			$stored = $value;
		}

		return new JSONResponse(data: ['value' => $stored]);
	}//end getPreference()

	/**
	 * Write a per-user preference value. An empty value clears it.
	 *
	 * @param string $key The preference key (letters, digits, `.`, `_`, `-`).
	 * @param string $value The value to store (empty string clears it).
	 *
	 * @return JSONResponse `{value: string|null}`.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	#[NoAdminRequired]
	public function setPreference(string $key, string $value = ''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Not logged in'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$safeKey = $this->sanitizeKey(key: $key);
		if ($safeKey === '') {
			return new JSONResponse(data: ['message' => 'Invalid key'], statusCode: Http::STATUS_BAD_REQUEST);
		}

		if (strlen(string: $value) > self::MAX_VALUE_LENGTH) {
			return new JSONResponse(
				data: ['message' => 'Value exceeds maximum length of ' . self::MAX_VALUE_LENGTH . ' bytes'],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		$stored = null;
		if ($value === '') {
			$this->config->deleteUserValue(
				userId: $user->getUID(),
				appName: Application::APP_ID,
				key: 'pref_' . $safeKey
			);
		}

		if ($value !== '') {
			$this->config->setUserValue(
				userId: $user->getUID(),
				appName: Application::APP_ID,
				key: 'pref_' . $safeKey,
				value: $value
			);
			$stored = $value;
		}

		return new JSONResponse(data: ['value' => $stored]);
	}//end setPreference()

	/**
	 * Accept a key only when it is already in the safe charset, so callers
	 * cannot reach IConfig user values outside the `pref_` namespace.
	 *
	 * A key outside it is refused rather than rewritten: stripping would let
	 * `case.view` and `caseview` silently share one stored value. The charset
	 * covers the keys the library sends (`cn_landing_page`,
	 * `dashboard-layout.{pageId}`), and the length leaves room for `pref_`
	 * within IConfig's 64-character key column.
	 *
	 * @param string $key The raw key.
	 *
	 * @return string The key unchanged, or '' when it is refused.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	private function sanitizeKey(string $key): string {
		if (preg_match(pattern: '/^[A-Za-z0-9._-]{1,59}$/', subject: $key) !== 1) {
			return '';
		}

		return $key;
	}//end sanitizeKey()
}//end class
