<?php

/**
 * Hands OpenRegister the case type rights rows when it compiles the case matrix.
 *
 * The case schema's matrix declares `rowsFrom: "dossiq.case-type-rights"`.
 * OpenRegister asks for the rows through `MatrixRowsRequestedEvent` while it
 * decides an access question, once per request; this listener answers with the
 * rights rows of the current case types, translated by
 * {@see \OCA\Dossiq\Service\Access\CaseTypeRightsMatrixRows}, and with the
 * rows that let a case's assigned group read it
 * ({@see \OCA\Dossiq\Service\Access\AssignedGroupReadRows}).
 *
 * 🔴 IT RUNS INSIDE AN AUTHORIZATION DECISION. Everything is read without RBAC
 * and without tenancy, and nothing asks the permission handler. Any failure
 * adds no rows, which is the closed direction: the matrix only adds ways in.
 *
 * Only current case types count: a draft or a superseded version grants
 * nothing, so a rights change takes effect when its version is published.
 *
 * The compiled case type rows do not depend on the caller and are kept for a
 * minute in {@see \OCA\Dossiq\Service\Access\CaseTypeRightsRowsCache}; a case
 * type or organisation role change drops them at once. An empty compile is
 * not kept, so a failed read costs one request, not a minute.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/case-type-rights-enforced/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Kernel\Contracts\CaseTypeStoreInterface;
use OCA\Dossiq\Kernel\Contracts\OrganisationRolesInterface;
use OCA\Dossiq\Service\Access\CaseRightsRowSources;
use OCA\Dossiq\Service\Access\CaseTypeRightsRowsCache;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Answers OpenRegister's request for the case matrix rows.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/case-type-rights-enforced/specs/case-types/spec.md
 */
class CaseTypeRightsRowsListener implements IEventListener {
	/**
	 * The provider id the case schema's matrix names.
	 */
	public const PROVIDER = 'dossiq.case-type-rights';

	/**
	 * The event, by name: OpenRegister may be older than the event.
	 */
	public const EVENT = 'OCA\\OpenRegister\\Event\\MatrixRowsRequestedEvent';

	/**
	 * Constructor.
	 *
	 * @param CaseTypeStoreInterface     $store   Reads every case type.
	 * @param OrganisationRolesInterface $roles   Reads the organisation roles.
	 * @param CaseRightsRowSources       $sources The case type rights rows and the assigned group's read rows.
	 * @param LoggerInterface            $logger  The logger.
	 * @param CaseTypeRightsRowsCache    $cache   The compiled case type rows of the last minute.
	 */
	public function __construct(
		private readonly CaseTypeStoreInterface $store,
		private readonly OrganisationRolesInterface $roles,
		private readonly CaseRightsRowSources $sources,
		private readonly LoggerInterface $logger,
		private readonly CaseTypeRightsRowsCache $cache,
	) {
	}//end __construct()

	/**
	 * Add the rows when the request names this provider.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-type-rights-enforced/specs/case-types/spec.md
	 */
	public function handle(Event $event): void {
		if (is_a($event, self::EVENT) === false
			|| method_exists($event, 'getProvider') === false
			|| $event->getProvider() !== self::PROVIDER
		) {
			return;
		}

		$event->addRows(rows: $this->sources->assignedGroup->rows(), appId: AppIdentity::APP_ID);

		try {
			$event->addRows(rows: $this->caseTypeRows(), appId: AppIdentity::APP_ID);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: the case type rights could not be read; they grant nothing this request',
				['exception' => $e->getMessage()]
			);
		}
	}//end handle()

	/**
	 * The rows of the current case types' matrices: the kept ones, or compiled and kept.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function caseTypeRows(): array {
		$kept = $this->cache->get();
		if ($kept !== null) {
			return $kept;
		}

		$rows = $this->sources->caseTypes->rows(caseTypes: $this->currentCaseTypes(), roles: $this->roles->allUnscoped());
		if ($rows !== []) {
			$this->cache->remember(rows: $rows);
		}

		return $rows;
	}//end caseTypeRows()

	/**
	 * The published, not superseded case types with their rights rows.
	 *
	 * @return array<int, array{id: string, rightsMatrix: mixed}>
	 */
	private function currentCaseTypes(): array {
		$current = [];
		foreach ($this->store->everyCaseType(scope: ['_rbac' => false, '_multitenancy' => false]) as $row) {
			if (($row['isDraft'] ?? false) === true || empty($row['supersededBy']) === false) {
				continue;
			}

			$current[] = ['id' => $this->store->rowId(row: $row), 'rightsMatrix' => ($row['rightsMatrix'] ?? [])];
		}

		return $current;
	}//end currentCaseTypes()

}//end class
