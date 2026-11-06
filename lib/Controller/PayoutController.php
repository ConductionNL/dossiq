<?php

/**
 * Dossiq PayoutController.
 *
 * Prepares the payout of a settled accruing amount and hands it to the
 * finance app, for a caller with mutation access to the owning case.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
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
 * @spec openspec/changes/payout-goes-through-finance/specs/payout/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\Money\MoneyConfigKeys;
use OCA\Dossiq\Service\Money\PayoutService;
use OCA\Dossiq\Service\Support\OwningCaseResolver;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Throwable;

/**
 * REST surface that prepares a payout.
 *
 *
 * @spec openspec/changes/payout-goes-through-finance/specs/payout/spec.md
 */
class PayoutController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName App id.
	 * @param IRequest $request Request.
	 * @param PayoutService $payouts Prepares the payout and hands it to finance.
	 * @param IUserSession $userSession User session.
	 * @param CaseAccessGuard $caseAccess Per-case authorization guard.
	 * @param OwningCaseResolver $owningCase Resolves a calculation's owning case.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly PayoutService $payouts,
		private readonly IUserSession $userSession,
		private readonly CaseAccessGuard $caseAccess,
		private readonly OwningCaseResolver $owningCase,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Prepare the payout of a settled calculation and hand it to the finance app.
	 *
	 * Body: accountHolderName, iban, and optionally receiptDate (Y-m-d, the day
	 * the notice was received, from which the latest payment date runs).
	 *
	 * @param string $id The calculation id.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/payout-goes-through-finance/specs/payout/spec.md
	 */
	public function prepare(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		$caseId = null;
		if ($user !== null) {
			$caseId = $this->owningCase->resolveVia(
				objectId: $id,
				schemaKey: MoneyConfigKeys::ACCRUING_AMOUNT_SCHEMA,
				linkField: 'deadlineInstance',
				viaSchemaKey: 'termijn_instance_schema',
				caseField: 'case'
			);
		}

		// Fails closed: no user, no owning case, or no mutation access all deny.
		if ($user === null || $caseId === null
			|| $this->caseAccess->hasCaseMutationAccess(caseId: $caseId, user: $user) === false
		) {
			return new JSONResponse(['message' => 'Not authorized for calculation ' . $id], Http::STATUS_FORBIDDEN);
		}

		try {
			$row = $this->payouts->preparePayoutFrom(calculationId: $id, input: $this->jsonBody());
		} catch (Throwable $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($row, Http::STATUS_CREATED);
	}//end prepare()

	/**
	 * Deprecated alias of /api/accruing-amounts/{id}/payout, kept for clients of the old URL.
	 *
	 * @param string $id Id.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/case-money-is-configuration/specs/case-money-is-configuration/spec.md (REQ-DQM-003)
	 *
	 * @contract exclude Deprecated path alias for prepare(); behaviour covered by the prepare() tests.
	 */
	public function prepareLegacyPath(string $id): JSONResponse {
		return $this->prepare(id: $id);
	}//end prepareLegacyPath()

	/**
	 * Decode the JSON request body.
	 *
	 * IRequest::getContent() is protected on the concrete OC request, so the
	 * payload is read from php://input. Protected so a test can supply it.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/payout-goes-through-finance/specs/payout/spec.md#REQ-PAY-001
	 */
	protected function jsonBody(): array {
		$body = json_decode((string)file_get_contents('php://input'), true);
		if (is_array($body) === true) {
			return $body;
		}

		return [];
	}//end jsonBody()
}//end class
