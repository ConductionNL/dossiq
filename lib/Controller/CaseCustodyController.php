<?php

/**
 * Reading the chain of custody of a case.
 *
 * Read-only: the chain is written by the moves themselves, through
 * {@see \OCA\Dossiq\Service\CaseTransferService}, so there is no endpoint here
 * that opens or closes a holding. A second way to write the chain would be a
 * second way for it to disagree with the case.
 *
 * 🔴 EVERY ENDPOINT CHECKS THE CASE, NOT THE ROLE. Who held a case is the
 * history of who could read it, so it is guarded exactly as the case is
 * ({@see CaseAccessGuard}, ADR-005 Rule 3). The unit endpoint is the one
 * exception in shape and not in principle: it answers over a unit rather than a
 * case, so it is filtered to the units the caller belongs to.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Kernel\Contracts\CaseAccessGuardInterface;
use OCA\Dossiq\Kernel\Contracts\CaseCustodyChainInterface;
use OCA\Dossiq\Kernel\Contracts\CaseCustodyQueryInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The chain, who held the case on a date, and what a unit held.
 *
 * @spec openspec/specs/case-management/spec.md
 */
class CaseCustodyController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest         $request     The request.
	 * @param CaseCustodyChainInterface $chain       The chain of holdings.
	 * @param CaseCustodyQueryInterface $query       The two questions the chain exists for.
	 * @param CaseAccessGuardInterface  $accessGuard Per-case authorization, failing closed.
	 * @param IGroupManager    $groups      The units a caller belongs to.
	 * @param IUserSession     $userSession The session.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function __construct(
		IRequest $request,
		private readonly CaseCustodyChainInterface $chain,
		private readonly CaseCustodyQueryInterface $query,
		private readonly CaseAccessGuardInterface $accessGuard,
		private readonly IGroupManager $groups,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Every holding of a case, oldest first.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return JSONResponse The chain, or the refusal.
	 *
	 * @spec openspec/specs/case-management/spec.md#requirement-case-ownership-is-a-dated-chain-of-holdings-req-cus-01
	 */
	#[NoAdminRequired]
	public function chain(string $caseId): JSONResponse {
		if ($this->readerHoldsTheCase(caseId: $caseId) === false) {
			return $this->notYours();
		}

		$holdings = $this->chain->holdings(caseId: $caseId);

		return new JSONResponse(
			[
				'caseId' => $caseId,
				'holdings' => $holdings,
				'open' => $this->chain->openHoldingFor(caseId: $caseId),
				'total' => count($holdings),
			]
		);
	}//end chain()

	/**
	 * Who held the case on one date.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return JSONResponse The holding, or the refusal.
	 *
	 * @spec openspec/specs/case-management/spec.md#requirement-case-ownership-is-a-dated-chain-of-holdings-req-cus-01
	 */
	#[NoAdminRequired]
	public function holder(string $caseId): JSONResponse {
		if ($this->readerHoldsTheCase(caseId: $caseId) === false) {
			return $this->notYours();
		}

		$asOf = trim((string)$this->request->getParam('on', ''));
		if ($asOf === '') {
			return new JSONResponse(
				['message' => 'Name the date you are asking about.', 'error' => 'custody-date-missing'],
				Http::STATUS_BAD_REQUEST,
			);
		}

		return new JSONResponse(
			[
				'caseId' => $caseId,
				'on' => $asOf,
				'holding' => $this->query->holderOn(caseId: $caseId, asOf: $asOf),
			]
		);
	}//end holder()

	/**
	 * What one unit held between two dates.
	 *
	 * @param string $unit The organisation unit.
	 *
	 * @return JSONResponse The holdings, or the refusal.
	 *
	 * @spec openspec/specs/case-management/spec.md#requirement-case-ownership-is-a-dated-chain-of-holdings-req-cus-01
	 */
	#[NoAdminRequired]
	public function unit(string $unit): JSONResponse {
		if ($this->mayReadUnit(unit: $unit) === false) {
			return $this->notYours();
		}

		$from = trim((string)$this->request->getParam('from', ''));
		$to = trim((string)$this->request->getParam('to', ''));
		if ($from === '' || $to === '') {
			return new JSONResponse(
				['message' => 'Name the period you are asking about.', 'error' => 'custody-period-missing'],
				Http::STATUS_BAD_REQUEST,
			);
		}

		$holdings = $this->query->heldBy(organisationUnit: $unit, from: $from, to: $to);

		return new JSONResponse(
			[
				'organisationUnit' => $unit,
				'from' => $from,
				'to' => $to,
				'holdings' => $holdings,
				'total' => count($holdings),
			]
		);
	}//end unit()

	/**
	 * Whether the caller may read a unit's custody: a member of it, or an administrator.
	 *
	 * @param string $unit The organisation unit (a group id).
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/case-management/spec.md#requirement-case-ownership-is-a-dated-chain-of-holdings-req-cus-01
	 */
	private function mayReadUnit(string $unit): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		$mine = array_map('strval', $this->groups->getUserGroupIds($user));
		return in_array($unit, $mine, true) === true || $this->groups->isAdmin($user->getUID()) === true;
	}//end mayReadUnit()

	/**
	 * Whether the caller may read this case at all.
	 *
	 * It ASKS `CaseAccessGuard` and decides nothing itself. Named for what it
	 * does rather than `mayRead`, because a method with an evaluator's name
	 * reads as a second answer to "who may open this case", and a second
	 * answer is a disclosure the first time the two disagree.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return bool True when they may.
	 */
	private function readerHoldsTheCase(string $caseId): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		return $this->accessGuard->hasCaseReadAccess(caseId: $caseId, user: $user);
	}//end readerHoldsTheCase()

	/**
	 * One answer for "this case is not yours", so none of the three differ.
	 *
	 * @return JSONResponse The refusal.
	 */
	private function notYours(): JSONResponse {
		return new JSONResponse(
			['message' => 'You cannot read the custody of this case.', 'error' => 'case-access-denied'],
			Http::STATUS_FORBIDDEN,
		);
	}//end notYours()
}//end class
