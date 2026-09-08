<?php

/**
 * Dossiq integration-status service.
 *
 * Writes what a probe or a save learned about one outside connection onto the
 * matching `dossiqIntegration` object, so the Integrations page under the gear
 * shows the connection's real state instead of a form and nothing else.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/admin-settings/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\Support\SearchesObjects;
use DateTimeImmutable;
use DateTimeInterface;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Records the outcome of a probe or a settings save on an integration row.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */
class IntegrationStatusService {

	use SearchesObjects;

	/**
	 * The connection keys the seed ships. A key outside this set is a caller's
	 * typo, not a new connection: adding one means adding a seed row too.
	 *
	 * @var array<int, string>
	 */
	public const KEYS = [
		'zgw',
		'stuf',
		'kcc',
		'dmn',
		'mailbox',
		'store',
		'financial',
		'brp',
		'kvk',
		'pdok',
	];

	/**
	 * The four states a card may show. A caller handing anything else is
	 * refused rather than silently written, because a status nothing can read
	 * is worse on the page than no status at all.
	 *
	 * @var array<int, string>
	 */
	public const STATUSES = [
		'configured',
		'unconfigured',
		'unavailable',
		'error',
	];

	/**
	 * App-config keys that must all be non-empty for a connection to count as
	 * configured after a plain settings save (design D3).
	 *
	 * Only connections WITHOUT a probe appear here. StUF and the mailbox are
	 * absent on purpose: they are probed, and a probe outranks a saved form.
	 * `brp` and `kvk` are absent because nothing saves them yet, and `pdok`
	 * because no admin section writes its keys.
	 *
	 * @var array<string, array<int, string>>
	 */
	public const SAVE_REQUIRED_KEYS = [
		'zgw' => ['register', 'case_schema'],
		'kcc' => ['identification_method'],
		'dmn' => ['decision_table_schema'],
		'financial' => ['dwangsom_callback_secret'],
	];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Resolves OpenRegister + config.
	 * @param IAppConfig $appConfig Reads the saved section values.
	 * @param LoggerInterface $logger Records what could not be written.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Record a connection's status.
	 *
	 * Never throws: this runs beside a probe whose own answer is the thing the
	 * caller returns, and a page that cannot be updated must not turn a
	 * successful connection test into a 500.
	 *
	 * @param string $key One of {@see self::KEYS}.
	 * @param string $status One of {@see self::STATUSES}.
	 * @param string $message What the probe or save reported.
	 *
	 * @return bool True when the row was written.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function record(string $key, string $status, string $message = ''): bool {
		if (in_array($key, self::KEYS, true) === false) {
			$this->logger->warning(
				'Dossiq: refusing to record an unknown integration key',
				['key' => $key]
			);
			return false;
		}

		if (in_array($status, self::STATUSES, true) === false) {
			$this->logger->warning(
				'Dossiq: refusing to record an unknown integration status',
				['key' => $key, 'status' => $status]
			);
			return false;
		}

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return false;
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('dossiq_integration_schema');
		if ($register === '' || $schema === '') {
			return false;
		}

		try {
			$rows = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: ['key' => $key, '_limit' => 1]
			);

			$row = ($rows[0] ?? null);
			if (is_array($row) === false) {
				$this->logger->warning(
					'Dossiq: no dossiqIntegration row for key',
					['key' => $key]
				);
				return false;
			}

			$uuid = (string)($row['id'] ?? ($row['@self']['id'] ?? ''));
			if ($uuid === '') {
				return false;
			}

			// PUT-semantic: `saveObject()` replaces the object, so the whole
			// row goes back with the three fields moved. Sending only the
			// three would blank the title the page renders.
			$row['status'] = $status;
			$row['statusMessage'] = $message;
			$row['checkedAt'] = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
			unset($row['@self']);

			$objectService->saveObject(
				object: $row,
				register: $register,
				schema: $schema,
				uuid: $uuid
			);
			return true;
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: could not record an integration status',
				['key' => $key, 'exception' => $e->getMessage()]
			);
			return false;
		}
	}//end record()

	/**
	 * Record every connection whose section the given save touched.
	 *
	 * A save that names none of a connection's required keys leaves that
	 * connection's row alone — saving the KCC form must not restate what the
	 * ZGW card says.
	 *
	 * @param array<string, mixed> $saved The payload the settings save carried.
	 *
	 * @return array<string, string> The statuses written, keyed by connection.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function recordFromSave(array $saved): array {
		$written = [];
		foreach (self::SAVE_REQUIRED_KEYS as $key => $requiredKeys) {
			$touched = array_intersect($requiredKeys, array_keys($saved));
			if ($touched === []) {
				continue;
			}

			$status = 'unconfigured';
			$message = 'Not checked yet';
			if ($this->allFilled(keys: $requiredKeys) === true) {
				$status = 'configured';
				$message = 'Saved in the admin settings';
			}

			if ($this->record(key: $key, status: $status, message: $message) === true) {
				$written[$key] = $status;
			}
		}

		return $written;
	}//end recordFromSave()

	/**
	 * Whether every named app-config key holds a non-empty value.
	 *
	 * @param array<int, string> $keys The app-config keys a section requires.
	 *
	 * @return bool
	 */
	private function allFilled(array $keys): bool {
		foreach ($keys as $key) {
			if (trim($this->appConfig->getValueString(Application::APP_ID, $key, '')) === '') {
				return false;
			}
		}

		return true;
	}//end allFilled()
}//end class
