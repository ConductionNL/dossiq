<?php

/**
 * Dossiq Woo delivered set controller
 *
 * GET /api/cases/{id}/woo/delivered-sets/{setId}/verify recomputes the
 * hashes of a delivered set (woo-delivered-set-is-a-record REQ-WDS-003). It
 * needs read access to the case, and the set must belong to that case.
 * GET .../items/{index} names both files of one item and GET
 * .../items/{index}/{side} answers the bytes of one, for the compare dialog
 * (REQ-WDS-004), behind the same guard.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-delivered-set-is-a-record/specs/woo-delivered-set/spec.md#requirement-a-set-is-re-verifiable-req-wds-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Woo\WooDeliveredSetFiles;
use OCA\Dossiq\Woo\WooDeliveredSetVerifier;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Throwable;

/**
 * Verifies a delivered set for a user who may read its case.
 *
 * @spec openspec/changes/woo-delivered-set-is-a-record/specs/woo-delivered-set/spec.md#requirement-a-set-is-re-verifiable-req-wds-003
 */
class WooDeliveredSetController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string                  $appName     The app name.
	 * @param IRequest                $request     The request.
	 * @param WooDeliveredSetVerifier $verifier    Recomputes the hashes.
	 * @param CaseAccessGuard         $guard       Case read access.
	 * @param IUserSession            $userSession The caller.
	 * @param WooDeliveredSetFiles    $files       Reads both files of an item.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly WooDeliveredSetVerifier $verifier,
		private readonly CaseAccessGuard $guard,
		private readonly IUserSession $userSession,
		private readonly WooDeliveredSetFiles $files,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Verify one set of one case.
	 *
	 * @param string $id    The case.
	 * @param string $setId The set.
	 *
	 * @return JSONResponse 200 `{verified, setHash, items}`; 403 without case read access; 404 for another case's set.
	 *
	 * @spec openspec/changes/woo-delivered-set-is-a-record/specs/woo-delivered-set/spec.md#requirement-a-set-is-re-verifiable-req-wds-003
	 */
	#[NoAdminRequired]
	public function verify(string $id, string $setId): JSONResponse {
		$set = $this->readableSet(id: $id, setId: $setId);
		if ($set instanceof JSONResponse) {
			return $set;
		}

		try {
			return new JSONResponse($this->verifier->verify(set: $set));
		} catch (Throwable $e) {
			// An unreadable store is not a verified set: say so, never answer verified.
			return new JSONResponse(['error' => 'unavailable'], Http::STATUS_SERVICE_UNAVAILABLE);
		}
	}//end verify()

	/**
	 * Name and type of the original and the delivered file of one item.
	 *
	 * @param string $id    The case.
	 * @param string $setId The set.
	 * @param int    $index The item's position.
	 *
	 * @return JSONResponse 200 `{original: {fileName, mimeType, readable}, delivered: {...}}`;
	 *                      403 without case read access; 404 for another case's set or an unknown item.
	 *
	 * @spec openspec/changes/woo-delivered-set-is-a-record/specs/woo-delivered-set/spec.md#requirement-the-delivered-rendition-is-compared-with-its-original-req-wds-004
	 */
	#[NoAdminRequired]
	public function item(string $id, string $setId, int $index): JSONResponse {
		$set = $this->readableSet(id: $id, setId: $setId);
		if ($set instanceof JSONResponse) {
			return $set;
		}

		try {
			$sides = $this->files->describe(set: $set, index: $index);
		} catch (Throwable $e) {
			return new JSONResponse(['error' => 'unavailable'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		if ($sides === null) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($sides);
	}//end item()

	/**
	 * The bytes of one side of one item.
	 *
	 * @param string $id    The case.
	 * @param string $setId The set.
	 * @param int    $index The item's position.
	 * @param string $side  `original` or `delivered`.
	 *
	 * @return DataDownloadResponse|JSONResponse The file; 403 without case read access; 404 when there is nothing to read.
	 *
	 * @spec openspec/changes/woo-delivered-set-is-a-record/specs/woo-delivered-set/spec.md#requirement-the-delivered-rendition-is-compared-with-its-original-req-wds-004
	 */
	#[NoAdminRequired]
	public function file(string $id, string $setId, int $index, string $side): DataDownloadResponse|JSONResponse {
		$set = $this->readableSet(id: $id, setId: $setId);
		if ($set instanceof JSONResponse) {
			return $set;
		}

		try {
			$file = $this->files->read(set: $set, index: $index, side: $side);
		} catch (Throwable $e) {
			return new JSONResponse(['error' => 'unavailable'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		if ($file === null) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		return new DataDownloadResponse($file['bytes'], $file['fileName'], $file['mimeType']);
	}//end file()

	/**
	 * The set when the caller may read its case and it belongs to that case, else the refusal.
	 *
	 * @param string $id    The case.
	 * @param string $setId The set.
	 *
	 * @return array<string, mixed>|JSONResponse
	 */
	private function readableSet(string $id, string $setId): array|JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null || $this->guard->hasCaseReadAccess(caseId: $id, user: $user) === false) {
			return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		try {
			$set = $this->verifier->find(setId: $setId);
		} catch (Throwable $e) {
			return new JSONResponse(['error' => 'unavailable'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		if ($set === null || (string)($set['case'] ?? '') !== $id) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		return $set;
	}//end readableSet()
}//end class
