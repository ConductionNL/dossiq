<?php

/**
 * Dossiq reads-case-writes listener trait
 *
 * The payload reading a pre-persist case listener shares: the payload of the
 * object being saved, whether it is a case, and the case as the save will
 * leave it. The using class provides `$logger`, `$settingsService` (with
 * `getConfigValue()`) and a `PAYLOAD_CHECK` constant naming the check in the
 * debug log of a payload that cannot be read.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener\Support
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
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener\Support;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;

/**
 * Reads the case a pre-persist event is about to save.
 *
 * @spec openspec/specs/case-management/spec.md
 */
trait ReadsCaseWrites {
	/**
	 * Read an entity's payload, or null when it cannot be read.
	 *
	 * @param ObjectEntity $entity The entity carried by the event.
	 *
	 * @return array<string, mixed>|null The payload, or null.
	 */
	private function payload(ObjectEntity $entity): ?array {
		return (new PreSaveCheck(logger: $this->logger))->read(entity: $entity, check: self::PAYLOAD_CHECK);
	}//end payload()

	/**
	 * Whether the supplied payload belongs to the `case` schema.
	 *
	 * @param array<string, mixed> $object Object payload (incl. `@self`).
	 *
	 * @return bool True when this is a case.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) SchemaMembership::belongsTo() is a pure predicate on the payload.
	 */
	private function isCaseSchema(array $object): bool {
		return SchemaMembership::belongsTo(object: $object, expected: $this->settingsService->getConfigValue('case_schema'));
	}//end isCaseSchema()

	/**
	 * The case as the save will leave it, or null when the save is not of a readable case.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event  The pre-persist event.
	 * @param ObjectEntity                            $entity The object being written.
	 *
	 * @return array<string, mixed>|null The stored payload with the modified data on top.
	 */
	private function caseAsSaved(ObjectCreatingEvent|ObjectUpdatingEvent $event, ObjectEntity $entity): ?array {
		$payload = $this->payload(entity: $entity);
		if ($payload === null || $this->isCaseSchema(object: $payload) === false) {
			return null;
		}

		return array_merge($payload, $event->getModifiedData());
	}//end caseAsSaved()
}//end trait
