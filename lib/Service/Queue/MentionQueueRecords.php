<?php

/**
 * A mention, written down so it can be waited on.
 *
 * WHY THIS EXISTS AT ALL. A mention today is a fire-and-forget Nextcloud
 * notification: the bell shows it, and nothing anywhere can answer "what am I
 * still mentioned in". A queue fed by a bell would empty itself the moment
 * somebody cleared their notifications, which is the dismissal D-2 refuses,
 * dressed as an unrelated gesture.
 *
 * So the mention is also recorded, once per person mentioned, and the record is
 * what the queue reads. The record closes when the reader has seen the thing
 * they were mentioned in. It is never dismissed and there is no endpoint that
 * would let it be.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Queue
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
 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Queue;

use DateTimeImmutable;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Writes and reads the per-person record of a mention.
 *
 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
 */
class MentionQueueRecords {
	use SearchesObjects;

	/**
	 * The schema the records live in.
	 *
	 * @var string
	 */
	public const SCHEMA = 'queueMention';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settings The register configuration.
	 * @param LoggerInterface $logger   Logger.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	public function __construct(
		private readonly SettingsService $settings,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Record that somebody was mentioned.
	 *
	 * Best effort, and deliberately so: the note is already saved by the time
	 * this runs, and a failed queue record must not turn a saved note into an
	 * error the author sees. The failure is logged, and the bell still rang.
	 *
	 * @param string $person      Who was mentioned.
	 * @param string $actor       Who mentioned them.
	 * @param string $subjectType What they were mentioned in: usually a case.
	 * @param string $subjectId   The subject's id.
	 * @param string $noteId      The note carrying the mention.
	 *
	 * @return bool TRUE when the record was written.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	public function record(string $person, string $actor, string $subjectType, string $subjectId, string $noteId): bool {
		if (trim($person) === '' || trim($subjectId) === '') {
			return false;
		}

		if (trim($subjectType) === '') {
			$subjectType = 'case';
		}

		try {
			$saved = $this->saveObjectAsArray(
				objectService: $this->objects(),
				register: $this->register(),
				schema: self::SCHEMA,
				object: [
					'person' => $person,
					'actor' => $actor,
					'subjectType' => $subjectType,
					'subjectId' => $subjectId,
					'noteId' => $noteId,
					'mentionedAt' => (new DateTimeImmutable())->format('Y-m-d\TH:i:sP'),
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning('Dossiq: could not record a mention on the queue: ' . $e->getMessage());
			return false;
		}

		return ($saved !== null);
	}//end record()

	/**
	 * The mentions this person has not dealt with.
	 *
	 * @param string $userId The person.
	 *
	 * @return array<int, array<string, mixed>> The records.
	 *
	 * @throws RuntimeException When the records cannot be read.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	public function openFor(string $userId): array {
		try {
			$rows = $this->searchObjectsAsArrays(
				objectService: $this->objects(),
				register: $this->register(),
				schema: self::SCHEMA,
				filters: ['person' => $userId, '_limit' => 100]
			);
		} catch (RuntimeException $e) {
			throw $e;
		} catch (Throwable $e) {
			throw new RuntimeException($e->getMessage(), 0, $e);
		}

		return array_values(
			array_filter(
				$rows,
				static fn (array $row): bool => (trim((string)($row['acknowledgedAt'] ?? '')) === '')
			)
		);
	}//end openFor()

	/**
	 * Stamp a mention as dealt with.
	 *
	 * @param string $recordId The record's id.
	 *
	 * @return bool TRUE when the stamp was written.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	public function acknowledge(string $recordId): bool {
		if (trim($recordId) === '') {
			return false;
		}

		try {
			$patched = $this->patchObjectAsArray(
				objectService: $this->objects(),
				register: $this->register(),
				schema: self::SCHEMA,
				id: $recordId,
				changes: ['acknowledgedAt' => (new DateTimeImmutable())->format('Y-m-d\TH:i:sP')]
			);
		} catch (Throwable $e) {
			$this->logger->warning('Dossiq: could not close a mention on the queue: ' . $e->getMessage());
			return false;
		}

		return ($patched !== null);
	}//end acknowledge()

	/**
	 * The register the records live in.
	 *
	 * @return string The register slug or id.
	 *
	 * @throws RuntimeException When the app is not configured.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	private function register(): string {
		$register = trim((string)$this->settings->getConfigValue('register'));
		if ($register === '') {
			throw new RuntimeException('Dossiq has no register configured yet.');
		}

		return $register;
	}//end register()

	/**
	 * OpenRegister's object service.
	 *
	 * @return object The object service.
	 *
	 * @throws RuntimeException When OpenRegister is absent.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	private function objects(): object {
		$service = $this->settings->getObjectService();
		if ($service === null) {
			throw new RuntimeException('OpenRegister is not available, so mentions cannot be read.');
		}

		return $service;
	}//end objects()
}//end class
