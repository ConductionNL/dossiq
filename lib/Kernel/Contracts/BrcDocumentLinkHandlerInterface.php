<?php

/**
 * Contract of BrcDocumentLinkHandler
 *
 * The public surface of BrcDocumentLinkHandler that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Dossiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Kernel\Contracts;

use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Contract of BrcDocumentLinkHandler.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface BrcDocumentLinkHandlerInterface {

	/**
	 * Create a BesluitInformatieObject with cross-register OIO sync (brc-005a).
	 *
	 * After creating the BIO, also creates an ObjectInformatieObject in the
	 * DRC register with objectType=besluit.
	 *
	 * @param IRequest $request The incoming request
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function create(IRequest $request): JSONResponse;

	/**
	 * Delete a BesluitInformatieObject and its OIO in DRC (brc-005b).
	 *
	 * @param IRequest $request The incoming request
	 * @param string $uuid The BIO UUID to delete
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function destroy(IRequest $request, string $uuid): JSONResponse;

	/**
	 * List BesluitInformatieObjecten as a plain array (per ZGW spec).
	 *
	 * Unlike paginated resources, besluitinformatieobjecten returns a flat array.
	 *
	 * @param IRequest $request The incoming request
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function index(IRequest $request): JSONResponse;
}//end interface
