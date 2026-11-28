<?php

/**
 * Dossiq published case.
 *
 * The case a publication is written onto: read from OpenRegister and
 * normalised to its array form (a missing or unreadable case refused), and
 * saved back once the publication record is on it. Split out of
 * {@see \OCA\Dossiq\Service\PublicationService}, which keeps composing the
 * publication record and requesting its delivery.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Publication
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/archive/2026-06-13-besluitvorming-workflow/tasks.md#task-7
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Publication;

use OCA\Dossiq\Kernel\AppIdentity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Reads the case to publish from OpenRegister and saves it back.
 *
 * @spec openspec/changes/archive/2026-06-13-besluitvorming-workflow/tasks.md#task-7
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class PublishedCase {

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Logs a case read that failed.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Load a case from OpenRegister and normalise it to its array form.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param array<string, mixed> $entry The entry: the case id, and the configured register and case schema ids.
	 *
	 * @return array<string, mixed> The case data.
	 *
	 * @throws \RuntimeException When the case cannot be loaded or does not exist.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function load(object $objectService, array $entry): array {
		$caseId = $entry['caseId'];
		try {
			$obj = $objectService->find(id: $caseId, options: new ObjectFindOptions(register: $entry['register'], schema: $entry['schema']));
		} catch (Throwable $e) {
			$this->logger->error(
				'PublicationService::publish find failed',
				['app' => AppIdentity::APP_ID, 'caseId' => $caseId, 'error' => $e->getMessage()]
			);
			throw new RuntimeException('Case not found: ' . $caseId);
		}

		if ($obj === null) {
			throw new RuntimeException('Case not found: ' . $caseId);
		}

		// An array casts to itself, so the cast doubles as the plain-object fallback.
		$case = (array)$obj;
		if (is_array($obj) === false && method_exists($obj, 'jsonSerialize') === true) {
			$case = $obj->jsonSerialize();
		}

		return $case;
	}//end load()

	/**
	 * Save the case, with its publication record, back into its register and schema.
	 *
	 * @param object               $objectService The OpenRegister ObjectService.
	 * @param array<string, mixed> $case          The case, publication record included.
	 * @param array<string, mixed> $entry         The entry: the configured register and case schema ids.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function save(object $objectService, array $case, array $entry): void {
		$objectService->saveObject(
			object: $case,
			options: new ObjectSaveOptions(target: new ObjectFindTarget(register: $entry['register'], schema: $entry['schema']))
		);
	}//end save()
}//end class
