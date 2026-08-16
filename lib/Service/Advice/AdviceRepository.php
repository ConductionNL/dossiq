<?php

/**
 * Procest Advice Repository.
 *
 * Every OpenRegister read and write against the `adviesAanvraag` schema.
 * Split out of AdviceService so that service keeps only the workflow
 * orchestration: resolving the ObjectService, resolving the
 * register/schema config pair, guarding both against an unconfigured
 * instance, normalising whatever shape OpenRegister returns into a plain
 * array, and turning an infrastructure failure into a static,
 * detail-free RuntimeException — that whole seam is repeated in every
 * advice query and now lives here and nowhere else.
 *
 * The read methods deliberately degrade to an empty result rather than
 * throwing: they back the deadline cron and the case-detail tab, neither
 * of which should fail hard on an unconfigured instance. The write
 * method throws, because a silently-dropped transition would be
 * invisible.
 *
 * @category Service
 * @package  OCA\Procest\Service\Advice
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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
 * @spec openspec/specs/advice-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Advice;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Reads and writes adviesAanvraag records through OpenRegister.
 *
 * @spec openspec/specs/advice-management/spec.md
 */
class AdviceRepository {

	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The settings/config + ObjectService bridge.
	 * @param LoggerInterface $logger The logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Load a single advice request by id.
	 *
	 * @param string $adviceId The advice UUID.
	 *
	 * @return array<string, mixed>|null Advice data, or null when unavailable/not found.
	 *
	 * @spec openspec/specs/advice-management/spec.md
	 */
	public function find(string $adviceId): ?array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return null;
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('advies_aanvraag_schema');

		if (empty($register) === true || empty($schema) === true) {
			return null;
		}

		try {
			$advice = $objectService->find($adviceId, register: $register, schema: $schema);
		} catch (Throwable $e) {
			$this->logger->error(
				'Procest: failed to load advice: ' . $e->getMessage(),
				['app' => Application::APP_ID]
			);
			return null;
		}

		return $this->normalize(result: $advice);
	}//end find()

	/**
	 * Get all advice requests linked to a case.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<int, array<string, mixed>> Advice records for the case.
	 *
	 * @spec openspec/specs/advice-management/spec.md
	 */
	public function findForCase(string $caseId): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return [];
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('advies_aanvraag_schema');

		if (empty($register) === true || empty($schema) === true) {
			return [];
		}

		try {
			return $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: ['case' => $caseId, '_limit' => 200],
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Procest: failed to fetch advice for case: ' . $e->getMessage(),
				['app' => Application::APP_ID]
			);
			return [];
		}
	}//end findForCase()

	/**
	 * Load all open advice requests across the system (for the deadline job).
	 *
	 * @return array<int, array<string, mixed>> Open advice records.
	 *
	 * @spec openspec/specs/advice-management/spec.md
	 */
	public function findOpen(): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return [];
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('advies_aanvraag_schema');

		if (empty($register) === true || empty($schema) === true) {
			return [];
		}

		try {
			return $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: ['status' => 'requested', '_limit' => 500],
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Procest: failed to load open advice: ' . $e->getMessage(),
				['app' => Application::APP_ID]
			);
			return [];
		}
	}//end findOpen()

	/**
	 * Persist a patch onto an existing advice request.
	 *
	 * @param array<string, mixed> $update The fields to write.
	 * @param string $adviceId The advice UUID.
	 *
	 * @return array<string, mixed> The normalized saved record.
	 *
	 * @throws RuntimeException When OpenRegister is unavailable, not configured, or the write fails.
	 *
	 * @spec openspec/specs/advice-management/spec.md
	 */
	public function save(array $update, string $adviceId): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('advies_aanvraag_schema');

		if (empty($register) === true || empty($schema) === true) {
			throw new RuntimeException('Advice schema is not configured');
		}

		try {
			$advice = $objectService->saveObject(
				object: $update,
				register: $register,
				schema: $schema,
				uuid: (string)$adviceId
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Procest: failed to transition advice status: ' . $e->getMessage(),
				['app' => Application::APP_ID]
			);
			throw new RuntimeException('Could not update advice request');
		}

		return $this->normalize(result: $advice);
	}//end save()

	/**
	 * Convert an object/array result to an associative array.
	 *
	 * @param mixed $result The OpenRegister return value.
	 *
	 * @return array<string, mixed> Normalized advice record.
	 *
	 * @spec openspec/specs/advice-management/spec.md
	 */
	public function normalize(mixed $result): array {
		if (is_array($result) === true) {
			return $result;
		}

		if (is_object($result) === true && method_exists($result, 'jsonSerialize') === true) {
			$data = $result->jsonSerialize();
			if (is_array($data) === true) {
				return $data;
			}
		}

		return [];
	}//end normalize()
}//end class
