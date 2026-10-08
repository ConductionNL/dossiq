<?php

/**
 * Reads OpenRegister's deletion marker, and the window it states.
 *
 * Split out of {@see CaseRecycleService} because it is a different job. That
 * class talks to OpenRegister and answers dossiq's questions about deleted
 * cases; this one knows the SHAPE of a soft-deleted row: where the marker
 * lives, which keys a window is spelled with, and what a row deleted before
 * openregister#3724 carries instead.
 *
 * Every read here is duck-typed. dossiq runs on instances where OpenRegister
 * is absent, and on instances where it is present but older than the window,
 * so nothing may assume a method exists or a key is filled.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Recycle
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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
 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Recycle;

use DateTimeImmutable;
use OCA\Dossiq\Service\SettingsService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The published recovery window of one soft-deleted row.
 *
 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
 */
class DeletionWindowReader {

	/**
	 * OpenRegister's window service, which is the single definition of one.
	 *
	 * @var string
	 */
	public const WINDOW_SERVICE = 'OCA\\OpenRegister\\Service\\Deletion\\DeletionWindowService';

	/**
	 * The retention OpenRegister falls back to when nothing states one.
	 *
	 * Mirrors `DeletionWindowService::DEFAULT_RETENTION_DAYS`. It applies to a
	 * row deleted before that service shipped, which carries a `purgeDate` and
	 * no `destroyableFrom`.
	 *
	 * @var int
	 */
	public const DEFAULT_RETENTION_DAYS = 30;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Bridge to OpenRegister.
	 * @param LoggerInterface $logger Structured logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The published window of one deleted entity.
	 *
	 * OpenRegister is asked first, because it owns the definition and knows
	 * which rule set the retention. The marker is the fallback.
	 *
	 * @param object $entity OpenRegister's object entity.
	 *
	 * @return array<string, mixed>|null The window, or null when the row carries none.
	 *
	 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
	 */
	public function windowFor(object $entity): ?array {
		$service = $this->settingsService->getOpenRegisterClass(class: self::WINDOW_SERVICE);
		$window = null;

		if ($service !== null && method_exists($service, 'windowFor') === true) {
			try {
				$stated = $service->windowFor(object: $entity, schema: null);
				if ($stated !== null && method_exists($stated, 'toArray') === true) {
					$window = $stated->toArray();
				}
			} catch (Throwable $e) {
				// Logged, not answered: the marker below is the fallback, and
				// a `return null` here would skip it.
				$this->logger->info(
					'Dossiq: OpenRegister could not state the window, reading the marker instead',
					['error' => $e->getMessage()]
				);
			}
		}

		if ($window !== null) {
			return $window;
		}

		return $this->fromMarker(entity: $entity);
	}//end windowFor()

	/**
	 * The window read straight off the deletion marker.
	 *
	 * @param object $entity OpenRegister's object entity.
	 *
	 * @return array<string, mixed>|null The window, or null.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) `DateTimeImmutable::createFromFormat`
	 * ANSWERS false on a value that is not a date, where the constructor
	 * throws. A value to branch on is what keeps this method from having to
	 * swallow an exception, which is the pattern the architecture guard
	 * `ServiceCatchReturnsNullTest` counts.
	 *
	 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
	 */
	public function fromMarker(object $entity): ?array {
		$marker = $this->marker(entity: $entity);
		$endsOn = trim((string)($marker['destroyableFrom'] ?? ($marker['purgeDate'] ?? '')));
		if ($endsOn === '') {
			return null;
		}

		$ends = DateTimeImmutable::createFromFormat('!Y-m-d', substr($endsOn, 0, 10));
		if ($ends === false) {
			$this->logger->warning(
				'Dossiq: a deletion marker carries no readable destroyable-from date',
				['value' => $endsOn]
			);

			return null;
		}

		$today = new DateTimeImmutable('today');
		$remaining = 0;
		if ($ends > $today) {
			$remaining = (int)$today->diff($ends)->days;
		}

		$retention = ($marker['retentionPeriod'] ?? null);
		if (is_numeric($retention) === false || (int)$retention < 1) {
			$retention = self::DEFAULT_RETENTION_DAYS;
		}

		return [
			'deletedAt' => $this->deletedAt(marker: $marker),
			'destroyableFrom' => $ends->format(DATE_ATOM),
			'daysRemaining' => $remaining,
			'retentionDays' => (int)$retention,
			'retentionSource' => 'default',
			'lapsed' => ($remaining === 0),
		];
	}//end fromMarker()

	/**
	 * The deletion marker of an entity.
	 *
	 * @param object $entity OpenRegister's object entity.
	 *
	 * @return array<string, mixed> The marker, or an empty array.
	 *
	 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
	 */
	public function marker(object $entity): array {
		if (method_exists($entity, 'getDeleted') === false) {
			return [];
		}

		$marker = $entity->getDeleted();
		if (is_array($marker) === false) {
			return [];
		}

		return $marker;
	}//end marker()

	/**
	 * The payload of an entity.
	 *
	 * @param object $entity OpenRegister's object entity.
	 *
	 * @return array<string, mixed> The payload.
	 *
	 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
	 */
	public function payload(object $entity): array {
		if (method_exists($entity, 'getObject') === false) {
			return [];
		}

		$payload = $entity->getObject();
		if (is_array($payload) === false) {
			return [];
		}

		return $payload;
	}//end payload()

	/**
	 * Whether the entity is in the recycle state.
	 *
	 * @param object $entity OpenRegister's object entity.
	 *
	 * @return bool True when soft-deleted.
	 *
	 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
	 */
	public function isSoftDeleted(object $entity): bool {
		if (method_exists($entity, 'isSoftDeleted') === true) {
			return ($entity->isSoftDeleted() === true);
		}

		return ($this->marker(entity: $entity) !== []);
	}//end isSoftDeleted()

	/**
	 * When the row was deleted, whichever key carries it.
	 *
	 * `deletedAt` is what openregister#3724 writes; `deleted` is what the
	 * older entity path wrote, and rows from before that change still carry
	 * only the second one.
	 *
	 * @param array<string, mixed> $marker The deletion marker.
	 *
	 * @return string The timestamp, or an empty string.
	 */
	private function deletedAt(array $marker): string {
		return trim((string)($marker['deletedAt'] ?? ($marker['deleted'] ?? '')));
	}//end deletedAt()
}//end class
