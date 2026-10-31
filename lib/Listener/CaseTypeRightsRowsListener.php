<?php

/**
 * Hands OpenRegister the case type rights rows when it compiles the case matrix.
 *
 * The case schema's matrix declares `rowsFrom: "dossiq.case-type-rights"`.
 * OpenRegister asks for the rows through `MatrixRowsRequestedEvent` while it
 * decides an access question, once per request; this listener answers with the
 * rights rows of the current case types, translated by
 * {@see CaseTypeRightsMatrixRows}.
 *
 * 🔴 IT RUNS INSIDE AN AUTHORIZATION DECISION. Everything is read without RBAC
 * and without tenancy, and nothing asks the permission handler. Any failure
 * adds no rows, which is the closed direction: the matrix only adds ways in.
 *
 * Only current case types count: a draft or a superseded version grants
 * nothing, so a rights change takes effect when its version is published.
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

use OCA\Dossiq\Service\Access\CaseTypeRightsMatrixRows;
use OCA\Dossiq\Service\Access\OrganisationRoles;
use OCA\Dossiq\Service\CaseTypeStore;
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
	 * @param CaseTypeStore            $store     Reads every case type.
	 * @param OrganisationRoles        $roles     Reads the organisation roles.
	 * @param CaseTypeRightsMatrixRows $translate Turns rights rows into matrix rows.
	 * @param LoggerInterface          $logger    The logger.
	 */
	public function __construct(
		private readonly CaseTypeStore $store,
		private readonly OrganisationRoles $roles,
		private readonly CaseTypeRightsMatrixRows $translate,
		private readonly LoggerInterface $logger,
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

		try {
			$event->addRows($this->translate->rows(caseTypes: $this->currentCaseTypes(), roles: $this->roles->allUnscoped()));
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: the case type rights could not be read; they grant nothing this request',
				['exception' => $e->getMessage()]
			);
		}
	}//end handle()

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
