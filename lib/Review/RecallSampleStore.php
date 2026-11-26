<?php

/**
 * Dossiq Recall Sample Store.
 *
 * Where the Recall samples of a case are kept: the case's stored samples,
 * read through OpenRegister, and a sample written back, its derived progress
 * left out. A sample that cannot be stored is refused, so nothing is drawn or
 * judged without its record.
 *
 * @category Review
 * @package  OCA\Dossiq\Review
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Review;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads and writes the Recall samples of a case.
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class RecallSampleStore {
	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsServiceInterface $settingsService The settings and OpenRegister access.
	 * @param LoggerInterface          $logger          The logger.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function __construct(
		private readonly SettingsServiceInterface $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The case's stored samples.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return list<array<string, mixed>> The samples.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-the-recall-is-estimated-from-an-elusion-sample-with-its-uncertainty-req-wrs-002
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function rows(string $caseId): array {
		$objectService = $this->settingsService->getObjectService();
		$schema = $this->settingsService->getConfigValue(RecallSamples::SCHEMA_KEY);
		if ($objectService === null || $schema === '' || $caseId === '') {
			return [];
		}

		return array_values(
			$this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $this->settingsService->getConfigValue('register'),
				schema: $schema,
				filters: ['case' => $caseId, '_limit' => 100],
			)
		);
	}//end rows()

	/**
	 * Store the sample.
	 *
	 * @param array<string, mixed> $sample The sample.
	 *
	 * @return array<string, mixed> The stored sample.
	 *
	 * @throws RefusedException When it cannot be stored.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-the-recall-is-estimated-from-an-elusion-sample-with-its-uncertainty-req-wrs-002
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function save(array $sample): array {
		$objectService = $this->settingsService->getObjectService();
		$schema = $this->settingsService->getConfigValue(RecallSamples::SCHEMA_KEY);
		$uuid = (string)($sample['id'] ?? ($sample['uuid'] ?? ''));
		if ($uuid === '') {
			$uuid = null;
		}

		unset($sample['@self'], $sample['id'], $sample['uuid'], $sample['progress']);
		if ($objectService === null || $schema === '') {
			throw $this->unstored(previous: null);
		}

		try {
			$saved = $objectService->saveObject(
				object: $sample,
				options: new ObjectSaveOptions(
					target: new ObjectFindTarget(
							register: $this->settingsService->getConfigValue('register'),
							schema: $schema
						),
					uuid: $uuid
				)
			);
		} catch (Throwable $e) {
			$this->logger->error('Dossiq: a Recall sample could not be stored', ['exception' => $e->getMessage()]);
			throw $this->unstored(previous: $e);
		}

		return $this->savedRow(saved: $saved);
	}//end save()

	/**
	 * The stored sample as an array.
	 *
	 * @param mixed $saved What the ObjectService answered.
	 *
	 * @return array<string, mixed> The stored sample.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-the-recall-is-estimated-from-an-elusion-sample-with-its-uncertainty-req-wrs-002
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function savedRow(mixed $saved): array {
		if (is_object($saved) === true && method_exists($saved, 'jsonSerialize') === true) {
			return (array)$saved->jsonSerialize();
		}

		return (array)$saved;
	}//end savedRow()

	/**
	 * The refusal when the sample cannot be stored.
	 *
	 * @param Throwable|null $previous The cause.
	 *
	 * @return RefusedException The refusal.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-the-recall-is-estimated-from-an-elusion-sample-with-its-uncertainty-req-wrs-002
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function unstored(?Throwable $previous): RefusedException {
		return new RefusedException(
			rule: 'review-recall-sample-unavailable',
			sentence: 'The Recall sample cannot be stored, so nothing was drawn or judged.',
			status: RefusedException::STATUS_INDETERMINATE,
			previous: $previous,
		);
	}//end unstored()
}//end class
