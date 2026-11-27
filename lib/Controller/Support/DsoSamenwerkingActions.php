<?php

/**
 * Dossiq DSO samenwerking actions.
 *
 * The writes on the samenwerking of a DSO case: starting a samenwerkverzoek on
 * a case, and answering one behind the per-object guard (the samenwerkverzoek
 * the caller may answer is looked up and authorised first). Split out of
 * {@see \OCA\Dossiq\Controller\DsoController}; guarding the case a
 * samenwerkverzoek starts on is {@see DsoCaseActions}.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/archive/2026-06-13-dso-omgevingsloket/tasks.md#T07
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller\Support;

use OCA\Dossiq\Kernel\Contracts\DsoObjectRepositoryInterface;
use OCA\Dossiq\Kernel\Contracts\SamenwerkverzoekServiceInterface;
use OCP\IUser;

/**
 * The samenwerkverzoek writes of a DSO case.
 *
 * @spec openspec/changes/archive/2026-06-13-dso-omgevingsloket/tasks.md#T07
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class DsoSamenwerkingActions {

	/**
	 * Constructor.
	 *
	 * @param DsoObjectRepositoryInterface     $repository       The OpenRegister read collaborator.
	 * @param SamenwerkverzoekServiceInterface $samenwerkService The samenwerkverzoek service.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function __construct(
		private readonly DsoObjectRepositoryInterface $repository,
		private readonly SamenwerkverzoekServiceInterface $samenwerkService,
	) {
	}//end __construct()

	/**
	 * The samenwerkverzoek the user may answer, or null when it does not exist.
	 *
	 * The guard throws `Not authorized` when the user may not answer it,
	 * which the controller answers as a 403.
	 *
	 * @param string $samenwerkId The samenwerkverzoek.
	 * @param IUser  $user        The caller.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/archive/2026-06-13-dso-omgevingsloket/tasks.md#T07
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function mutableSamenwerk(string $samenwerkId, IUser $user): ?array {
		$samenwerkverzoek = $this->repository->findSamenwerkverzoek(samenwerkId: $samenwerkId);
		if ($samenwerkverzoek === null) {
			return null;
		}

		$this->samenwerkService->authorizeSamenwerkMutation(
			samenwerk: $samenwerkverzoek,
			user: $user
		);
		return $samenwerkverzoek;
	}//end mutableSamenwerk()

	/**
	 * Start the samenwerkverzoek the body asks for.
	 *
	 * @param string               $caseId The case.
	 * @param array<string, mixed> $body   The request body: requestedCompetentAuthority and rationale.
	 *
	 * @return array<string, mixed> The samenwerkverzoek.
	 *
	 * @spec openspec/changes/archive/2026-06-13-dso-omgevingsloket/tasks.md#T07
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function startSamenwerking(string $caseId, array $body): array {
		return $this->samenwerkService->initiateSamenwerking(
			caseId: $caseId,
			aangezochtGezag: (string)($body['requestedCompetentAuthority'] ?? ''),
			rationale: (string)($body['rationale'] ?? '')
		);
	}//end startSamenwerking()

	/**
	 * Answer a samenwerkverzoek as the body says.
	 *
	 * @param string               $samenwerkId The samenwerkverzoek.
	 * @param array<string, mixed> $body        The request body: accept and advice.
	 *
	 * @return array<string, mixed> The updated samenwerkverzoek.
	 *
	 * @spec openspec/changes/archive/2026-06-13-dso-omgevingsloket/tasks.md#T07
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function answerSamenwerking(string $samenwerkId, array $body): array {
		return $this->samenwerkService->respondToSamenwerking(
			samenwerkId: $samenwerkId,
			accept: (bool)($body['accept'] ?? false),
			advies: (string)($body['advice'] ?? '')
		);
	}//end answerSamenwerking()
}//end class
