<?php

/**
 * Calls into OpenRegister's object service for one schema, each failure turned into its answer.
 *
 * Split out of {@see \OCA\Dossiq\Controller\BelplanController}. A create
 * answers OpenRegister's two validation refusals as 400 with their own message,
 * which names the offending property, and any other failure as 500 with the
 * caller's sentence; a patch answers any failure as 500; a failed list is empty.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller\Support;

use Closure;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\OpenRegister\Exception\CustomValidationException as OpenRegisterCustomValidationException;
use OCA\OpenRegister\Exception\ValidationException as OpenRegisterValidationException;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use Throwable;

/**
 * Creates, patches and lists the objects of one schema, answering each failure.
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class SchemaObjectCalls {

	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param string $createFailure The error a genuine fault on a create answers with, as a 500.
	 * @param string $patchFailure The error any failure on a patch answers with, as a 500.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function __construct(
		private readonly string $createFailure,
		private readonly string $patchFailure,
	) {
	}//end __construct()

	/**
	 * Save the record as a new object and answer it.
	 *
	 * The answer for an OpenRegister validation refusal is a 400 with its message,
	 * for any other failure a 500 with the create failure.
	 *
	 * @param array{0: object, 1: string, 2: string} $store The object service, register and schema.
	 * @param array<string, mixed> $record The record to save.
	 * @param Closure $answer Turns the created object into the response.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/archive/2026-06-13-kcc-werkplek-zaaksysteem-bridge/tasks.md#T12
	 */
	public function created(array $store, array $record, Closure $answer): JSONResponse {
		[$objectService, $register, $schema] = $store;

		try {
			$created = $objectService->saveObject(
				object: $record,
				options: new ObjectSaveOptions(target: new ObjectFindTarget(register: $register, schema: $schema))
			);
		} catch (OpenRegisterValidationException | OpenRegisterCustomValidationException $e) {
			// A payload OpenRegister's schema refuses came back as a 500, so an
			// admin who typed a bad value was told the server had broken. The
			// exception's message already names the offending property.
			//
			// Narrow on purpose; the Throwable arm below still answers 500 for
			// a genuine fault.
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (Throwable $e) {
			return new JSONResponse(['error' => $this->createFailure], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return $answer($created);
	}//end created()

	/**
	 * Write only the given fields onto a stored object and answer it.
	 *
	 * @param array{0: object, 1: string, 2: string} $store The object service, register and schema.
	 * @param string $id The object's uuid.
	 * @param array<string, mixed> $changes The fields to write.
	 * @param Closure $answer Turns the patched object into the response.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/archive/2026-06-13-kcc-werkplek-zaaksysteem-bridge/tasks.md#T12
	 */
	public function patched(array $store, string $id, array $changes, Closure $answer): JSONResponse {
		[$objectService, $register, $schema] = $store;

		try {
			$updated = $this->patchObjectAsArray(objectService: $objectService, register: $register, schema: $schema, id: $id, changes: $changes);
		} catch (Throwable $e) {
			return new JSONResponse(['error' => $this->patchFailure], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return $answer($updated);
	}//end patched()

	/**
	 * The objects matching the filters, or none when the read fails.
	 *
	 * @param array{0: object, 1: string, 2: string} $store The object service, register and schema.
	 * @param array<string, mixed> $filters Object-field filters and pagination keys.
	 *
	 * @return array<int, array<string, mixed>> The objects.
	 *
	 * @spec openspec/changes/archive/2026-06-13-kcc-werkplek-zaaksysteem-bridge/tasks.md#T12
	 */
	public function listed(array $store, array $filters): array {
		[$objectService, $register, $schema] = $store;

		try {
			return $this->searchObjectsAsArrays(objectService: $objectService, register: $register, schema: $schema, filters: $filters);
		} catch (Throwable $e) {
			return [];
		}
	}//end listed()
}//end class
