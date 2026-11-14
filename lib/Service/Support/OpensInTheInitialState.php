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
		[$object, $field, $state] = self::withoutLifecycleValue(write: $write);

		$created = $this->createInInitialState(objectService: $objectService, write: $write, object: $object);
		if ($state === null || ($created[$field] ?? null) === $state) {
			return ($created ?? $object);
		}

		$uuid = self::movedUuid(created: $created, state: $state);

		try {
			$moved = $this->moveIntoState(objectService: $objectService, write: $write, uuid: $uuid, state: $state);
			return ($moved ?? array_merge((array)$created, [$field => $state]));
		} catch (Throwable $e) {
			$leftOver = $this->deleteAgain(objectService: $objectService, created: $write + ['uuid' => $uuid]);
			throw new RuntimeException('The record could not be moved into "' . (string)$state . '": ' . $e->getMessage() . $leftOver, 0, $e);
		}
	}//end createThenMove()

	/**
	 * The record to create without its lifecycle value, the lifecycle field, and the value it is born in.
	 *
	 * @param array<string, mixed> $write `register`, `schema`, the `object` to write and the lifecycle `field`.
	 *
	 * @return array{0: array<string, mixed>, 1: string, 2: mixed} The object, the field and the state.
	 *
	 * @spec openspec/specs/bezwaar-hearing/spec.md#requirement-hearing-waiver-afzien-van-hoorrecht
	 */
	private static function withoutLifecycleValue(array $write): array {
		$object = array_diff_key((array)$write['object'], CallerIdentity::KEYS);
		$field  = (string)$write['field'];
		$state  = ($object[$field] ?? null);
		unset($object[$field]);

		return [$object, $field, $state];
	}//end withoutLifecycleValue()

	/**
	 * Create the record without its lifecycle value.
	 *
	 * @param object               $objectService OpenRegister's object service.
	 * @param array<string, mixed> $write         `register` and `schema`.
	 * @param array<string, mixed> $object        The record, without its lifecycle value.
	 *
	 * @return array<string, mixed>|null The created record.
	 *
	 * @spec openspec/specs/bezwaar-hearing/spec.md#requirement-hearing-waiver-afzien-van-hoorrecht
	 */
	private function createInInitialState(object $objectService, array $write, array $object): ?array {
		return $this->saveObjectAsArray(
			objectService: $objectService,
			register: (string)$write['register'],
			schema: (string)$write['schema'],
			object: $object
		);
	}//end createInInitialState()

	/**
	 * The id of the created record, or an exception when it answered none.
	 *
	 * @param array<string, mixed>|null $created The created record.
	 * @param mixed                     $state   The state it was to be moved into.
	 *
	 * @return string The id.
	 *
	 * @throws RuntimeException When the record answered no id.
	 *
	 * @spec openspec/specs/bezwaar-hearing/spec.md#requirement-hearing-waiver-afzien-van-hoorrecht
	 */
	private static function movedUuid(?array $created, mixed $state): string {
		$uuid = (string)(($created ?? [])['id'] ?? (($created ?? [])['uuid'] ?? ''));
		if ($uuid === '') {
			throw new RuntimeException('The record was created but answered no id, so it could not be moved into "' . (string)$state . '".');
		}

		return $uuid;
	}//end movedUuid()

	/**
	 * Move a created record into the state it carries.
	 *
	 * @param object               $objectService OpenRegister's object service.
	 * @param array<string, mixed> $write         `register`, `schema` and the lifecycle `field`.
	 * @param string               $uuid          The created record.
	 * @param mixed                $state         The state it is moved into.
	 *
	 * @return array<string, mixed>|null The moved record, or null when the patch answered none.
	 *
	 * @spec openspec/specs/bezwaar-hearing/spec.md#requirement-hearing-waiver-afzien-van-hoorrecht
	 */
	private function moveIntoState(object $objectService, array $write, string $uuid, mixed $state): ?array {
		return $this->patchObjectAsArray(
			objectService: $objectService,
			register: (string)$write['register'],
			schema: (string)$write['schema'],
			id: $uuid,
			changes: [(string)$write['field'] => $state]
		);
	}//end moveIntoState()

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
