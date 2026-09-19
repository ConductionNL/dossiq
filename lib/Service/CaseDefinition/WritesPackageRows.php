<?php

/**
 * Dossiq case definition row primitives.
 *
 * The handful of things both halves of a package write need to agree on:
 * whether the package named an id, whether this instance already holds it,
 * what counts as metadata and must not be written back, and how a
 * half-written component is undone.
 *
 * They are a trait rather than a base class because writing collections and
 * deploying workflows are not two kinds of one thing: they share these five
 * primitives and nothing else.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\CaseDefinition
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
 * @spec openspec/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\CaseDefinition;

use Psr\Log\LoggerInterface;

/**
 * The row primitives both package writers share.
 *
 * @spec openspec/specs/case-types/spec.md
 */
trait WritesPackageRows {

	/**
	 * The id an incoming row already carries, if any.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string The id, or the empty string.
	 */
	private function existingId(array $row): string {
		$self = ($row['@self'] ?? []);
		if (is_array($self) === true) {
			$id = trim((string)($self['uuid'] ?? $self['id'] ?? ''));
			if ($id !== '') {
				return $id;
			}
		}

		return trim((string)($row['id'] ?? ''));
	}//end existingId()

	/**
	 * Whether this instance already holds the object the package names.
	 *
	 * A read that RAISES is answered false, and that is deliberate: a miss and
	 * an unreadable store both mean "write it", and the write is what refuses
	 * if the store is genuinely broken. Answering true on a failed read would
	 * skip a row the instance does not have.
	 *
	 * @param object $objectService The OpenRegister object service.
	 * @param string $register The register slug.
	 * @param string $schema The schema slug.
	 * @param string $id The object id the package carries.
	 *
	 * @return boolean True when the object is already here.
	 */
	private function holds(object $objectService, string $register, string $schema, string $id): bool {
		try {
			$found = $objectService->find($id, register: $register, schema: $schema);
		} catch (\Throwable $e) {
			return false;
		}

		return ($found !== null && $found !== [] && $found !== false);
	}//end holds()

	/**
	 * The row as it goes to the store, without OpenRegister's own metadata.
	 *
	 * `@self` is the store's, not the case type's: writing it back would write
	 * the exporting instance's register, schema and organisation ids onto a
	 * row in a different instance, and every one of them would be wrong.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return array<string, mixed> The payload.
	 */
	private function withoutMetadata(array $row): array {
		unset($row['@self'], $row['id']);

		return $row;
	}//end withoutMetadata()

	/**
	 * The id of a row the store just wrote.
	 *
	 * @param mixed $stored Whatever the store answered.
	 *
	 * @return string The id, or the empty string.
	 */
	private function storedId(mixed $stored): string {
		if (is_object($stored) === true && method_exists($stored, 'getUuid') === true) {
			return trim((string)$stored->getUuid());
		}

		if (is_object($stored) === true && method_exists($stored, 'jsonSerialize') === true) {
			$stored = $stored->jsonSerialize();
		}

		if (is_array($stored) === true) {
			$self = ($stored['@self'] ?? []);
			if (is_array($self) === true && trim((string)($self['uuid'] ?? '')) !== '') {
				return trim((string)$self['uuid']);
			}

			return trim((string)($stored['id'] ?? ''));
		}

		return '';
	}//end storedId()

	/**
	 * Take back the rows this run created for a component that then failed.
	 *
	 * Best effort, and it says so in the log rather than in the response: a
	 * roll-back that itself fails must not turn one error into two, and the
	 * response the administrator reads is already `error`.
	 *
	 * @param object $objectService The OpenRegister object service.
	 * @param string $register The register slug.
	 * @param array<int, string> $ids The ids to remove.
	 *
	 * @return void
	 */
	private function rollBack(object $objectService, string $register, array $ids): void {
		foreach ($ids as $id) {
			if ($id === '') {
				continue;
			}

			try {
				$objectService->deleteObject($id, register: $register);
			} catch (\Throwable $e) {
				$this->logger->error(
					'Could not roll back imported object {id}: {error}',
					['id' => $id, 'error' => $e->getMessage()]
				);
			}
		}
	}//end rollBack()
}//end trait
