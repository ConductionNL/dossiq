<?php

/**
 * Dossiq: write a record of a lifecycle schema that is born past its first state
 *
 * OpenRegister opens an object of a lifecycle schema in the state the schema
 * declares as initial, and refuses a create that names another one. A record
 * that describes something already past that state (a hearing that was waived
 * before it was planned) is therefore created in the initial state and moved
 * by an update, which the schema's declared transitions judge as the acting
 * user. No elevation is involved.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Support
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version GIT: <git-id>
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/bezwaar-hearing/spec.md#requirement-hearing-waiver-afzien-van-hoorrecht
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Support;

use RuntimeException;
use Throwable;

/**
 * Create in the initial state, then move into the state the record carries.
 *
 * The host class uses {@see SearchesObjects}, whose save and patch helpers
 * this trait calls.
 *
 * @spec openspec/specs/bezwaar-hearing/spec.md#requirement-hearing-waiver-afzien-van-hoorrecht
 */
trait OpensInTheInitialState {

	/**
	 * Create the record without its lifecycle value, then move it into that value.
	 *
	 * A record whose value is the initial state, or that names none, is not
	 * moved. When the move is refused the created record is deleted again, so
	 * no record is left behind in a state nobody chose.
	 *
	 * @param object               $objectService OpenRegister's object service.
	 * @param array<string, mixed> $write         `register`, `schema`, the `object` to write and the
	 *                                            lifecycle `field` whose value it is born in.
	 *
	 * @return array<string, mixed> The stored record, in the state it carries.
	 *
	 * @throws RuntimeException When the create or the move fails.
	 *
	 * @spec openspec/specs/bezwaar-hearing/spec.md#requirement-hearing-waiver-afzien-van-hoorrecht
	 */
	protected function createThenMove(object $objectService, array $write): array {
		$object = array_diff_key((array)$write['object'], CallerIdentity::KEYS);
		$field  = (string)$write['field'];
		$state  = ($object[$field] ?? null);
		unset($object[$field]);

		$created = $this->saveObjectAsArray(
			objectService: $objectService,
			register: (string)$write['register'],
			schema: (string)$write['schema'],
			object: $object
		);
		if ($state === null || ($created[$field] ?? null) === $state) {
			return ($created ?? $object);
		}

		$uuid = (string)(($created ?? [])['id'] ?? (($created ?? [])['uuid'] ?? ''));
		if ($uuid === '') {
			throw new RuntimeException('The record was created but answered no id, so it could not be moved into "' . (string)$state . '".');
		}

		try {
			return ($this->patchObjectAsArray(
				objectService: $objectService,
				register: (string)$write['register'],
				schema: (string)$write['schema'],
				id: $uuid,
				changes: [$field => $state]
			) ?? array_merge((array)$created, [$field => $state]));
		} catch (Throwable $e) {
			$leftOver = $this->deleteAgain(objectService: $objectService, created: $write + ['uuid' => $uuid]);
			throw new RuntimeException('The record could not be moved into "' . (string)$state . '": ' . $e->getMessage() . $leftOver, 0, $e);
		}
	}//end createThenMove()

	/**
	 * Delete a record that was created but could not be moved.
	 *
	 * @param object               $objectService OpenRegister's object service.
	 * @param array<string, mixed> $created       `register`, `schema` and `uuid` of the record.
	 *
	 * @return string '' when it is gone, or the sentence that says it is not.
	 */
	private function deleteAgain(object $objectService, array $created): string {
		try {
			$objectService->deleteObject(
				register: (string)$created['register'],
				schema: (string)$created['schema'],
				uuid: (string)$created['uuid']
			);
		} catch (Throwable $e) {
			return ' The created record ' . (string)$created['uuid'] . ' could not be deleted again: ' . $e->getMessage();
		}

		return '';
	}//end deleteAgain()
}//end trait
