<?php

/**
 * OpenRegister's AuditTrailMapper, recording what it is asked to write.
 *
 * Extends the stub, so the write keeps the real signature
 * (`createAuditTrailEntry(ObjectEntity $object, string $action, array $context = [], ...)`)
 * and a call with a wrong argument fails here as it would live. `findAll()`
 * mirrors the real filter on `object_uuid` and on an action prefix
 * (`dossiq.bezwaar.*`), read on ConductionNL/openregister development.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Support
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
 * @spec openspec/specs/bezwaar-awb-audit-trail/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Support;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use RuntimeException;

class RecordingAuditTrailMapper extends AuditTrailMapper {
	/**
	 * Every row written, in order: object uuid, action, context and the explicit actor, if any.
	 *
	 * @var array<int, array{object: string, action: string, context: array<string, mixed>, actor: string|null}>
	 */
	public array $rows = [];

	/**
	 * Actions whose write throws, as a failing audit store would.
	 *
	 * @var array<int, string>
	 */
	public array $failingActions = [];

	/**
	 * Whether every write throws.
	 *
	 * @var bool
	 */
	public bool $failsAll = false;

	/**
	 * Record one row.
	 *
	 * @param ObjectEntity         $object    The object the entry relates to.
	 * @param string               $action    The action.
	 * @param array<string, mixed> $context   The context.
	 * @param string|null          $actorId   Explicit actor id.
	 * @param string|null          $actorName Explicit actor name.
	 * @param string|null          $ipAddress Explicit client address.
	 *
	 * @return AuditTrail The entry.
	 */
	public function createAuditTrailEntry(
		ObjectEntity $object,
		string $action,
		array $context = [],
		?string $actorId = null,
		?string $actorName = null,
		?string $ipAddress = null,
	): AuditTrail {
		if ($this->failsAll === true || in_array($action, $this->failingActions, true) === true) {
			throw new RuntimeException('audit store refused '.$action);
		}

		$this->rows[] = ['object' => (string) $object->getUuid(), 'action' => $action, 'context' => $context, 'actor' => $actorId];

		return parent::createAuditTrailEntry(object: $object, action: $action, context: $context, actorId: $actorId, actorName: $actorName, ipAddress: $ipAddress);
	}//end createAuditTrailEntry()

	/**
	 * The rows written so far, filtered the way the real mapper filters.
	 *
	 * @param int|null                  $limit   Unused here.
	 * @param int|null                  $offset  Unused here.
	 * @param array<string, mixed>|null $filters `object_uuid` and `action` (a trailing `.*` is a prefix).
	 * @param array<string, string>|null $sort   Unused here.
	 * @param string|null               $search  Unused here.
	 *
	 * @return array<int, AuditTrail> The matching rows.
	 */
	public function findAll(
		?int $limit = null,
		?int $offset = null,
		?array $filters = [],
		?array $sort = ['created' => 'DESC'],
		?string $search = null,
	): array {
		$found = [];
		foreach ($this->rows as $row) {
			$uuid = (string) ($filters['object_uuid'] ?? '');
			if ($uuid !== '' && $row['object'] !== $uuid) {
				continue;
			}

			$action = (string) ($filters['action'] ?? '');
			if ($action !== '' && str_ends_with($action, '.*') === true && str_starts_with($row['action'], substr($action, 0, -1)) === false) {
				continue;
			}

			$entry = new AuditTrail();
			$entry->objectUuid = $row['object'];
			$entry->action = $row['action'];
			$entry->changed = $row['context'];
			$found[] = $entry;
		}

		return $found;
	}//end findAll()

	/**
	 * The actions written on one record, in order.
	 *
	 * @param string $uuid The record.
	 *
	 * @return array<int, string> The actions.
	 */
	public function actionsOn(string $uuid): array {
		return array_values(array_map(
			static fn (array $row): string => $row['action'],
			array_filter($this->rows, static fn (array $row): bool => $row['object'] === $uuid)
		));
	}//end actionsOn()
}//end class
