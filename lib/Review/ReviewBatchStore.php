<?php

/**
 * Dossiq review batch store: where a Review batch is written.
 *
 * A batch saved into the batch schema, created or replaced by its uuid, and
 * answered as an array; a register that will not take it refused as
 * unavailable with the cause, and logged. Split out of
 * {@see ReviewBatches}, which keeps the checks, the documents, the task and
 * the reviewer access.
 *
 * @category Review
 * @package  OCA\Dossiq\Review
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-batches-are-assigned-to-named-reviewers-before-any-verdict-req-wrt-003
 * @spec openspec/changes/method-decomposition/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Review;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Writes a Review batch into the batch schema.
 *
 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-batches-are-assigned-to-named-reviewers-before-any-verdict-req-wrt-003
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class ReviewBatchStore {

	/**
	 * Constructor.
	 *
	 * @param SettingsServiceInterface $settingsService The settings and OpenRegister access.
	 * @param LoggerInterface          $logger          Logs a batch that could not be written.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function __construct(
		private readonly SettingsServiceInterface $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Save a batch, creating or replacing it.
	 *
	 * @param array<string, mixed> $batch The batch.
	 *
	 * @return array<string, mixed> The saved batch.
	 *
	 * @throws RefusedException When the batch cannot be written.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function save(array $batch): array {
		$uuid = ($batch['id'] ?? ($batch['uuid'] ?? null));
		if ($uuid !== null) {
			$uuid = (string)$uuid;
		}

		unset($batch['@self'], $batch['id'], $batch['uuid']);
		$saved = $this->writeBatch(batch: $batch, uuid: $uuid);

		if (is_object($saved) === true && method_exists($saved, 'jsonSerialize') === true) {
			return (array)$saved->jsonSerialize();
		}

		return (array)$saved;
	}//end save()

	/**
	 * Write a batch to OpenRegister.
	 *
	 * @param array<string, mixed> $batch The batch, without its identifiers.
	 * @param string|null          $uuid  The batch's uuid, or null for a new batch.
	 *
	 * @return mixed The saved object.
	 *
	 * @throws RefusedException When the batch cannot be written.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function writeBatch(array $batch, ?string $uuid) {
		try {
			return $this->settingsService->getObjectService()->saveObject(
				object: $batch,
				options: new ObjectSaveOptions(
					target: new ObjectFindTarget(
							register: $this->settingsService->getConfigValue('register'),
							schema: $this->settingsService->getConfigValue(ReviewBatches::SCHEMA_KEY)
						),
					uuid: $uuid
				)
			);
		} catch (Throwable $e) {
			$context = ['app' => AppIdentity::APP_ID, 'case' => ($batch['case'] ?? ''), 'exception' => $e->getMessage()];
			$this->logger->error('Dossiq: a Review batch could not be written', $context);
			throw new RefusedException(
				rule: 'review-batch-unavailable',
				sentence: ReviewBatches::UNSTORED,
				status: RefusedException::STATUS_INDETERMINATE,
				previous: $e
			);
		}
	}//end writeBatch()
}//end class
