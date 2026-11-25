<?php

/**
 * AwardProposal
 *
 * Proposes one admissible bid of a case for the award of its lot. The
 * proposal is held on the bid itself (state `proposed-award`), one per lot of
 * the case: proposing another bid of the same lot moves the earlier one back
 * to `evaluated`. The award itself is made later by the case's flow, after
 * its standstill term, which hands the decision on.
 *
 * @category  Service
 * @package   OCA\Dossiq\Service\Scoring
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/tenders-run-on-the-tender-case-type/specs/dossiq-procurement-tender-management/spec.md#requirement-a-tender-is-worked-end-to-end-on-its-case-page-req-tnd-case-01
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Scoring;

use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Kernel\Contracts\AwardProposalInterface;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\IL10N;
use Override;
use RuntimeException;

/**
 * The proposed award of a case, held on its bids.
 *
 * @spec openspec/changes/tenders-run-on-the-tender-case-type/specs/dossiq-procurement-tender-management/spec.md#requirement-a-tender-is-worked-end-to-end-on-its-case-page-req-tnd-case-01
 */
class AwardProposal implements AwardProposalInterface {
	use SearchesObjects;

	/**
	 * The state of the bid proposed for the award of its lot.
	 *
	 * @var string
	 */
	public const PROPOSED = 'proposed-award';

	/**
	 * The state a bid goes back to when another bid of its lot is proposed.
	 *
	 * @var string
	 */
	public const RELEASED = 'evaluated';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settings The object service.
	 * @param ScoringRecords  $records  Reads the case's bids.
	 * @param IL10N           $l10n     The refusals.
	 * @param ScoringValue    $value    Reads ids.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settings,
		private readonly ScoringRecords $records,
		private readonly IL10N $l10n,
		private readonly ScoringValue $value = new ScoringValue(),
	) {
	}//end __construct()

	/**
	 * Propose one admissible bid of the case for the award of its lot.
	 *
	 * @param string $caseId       The case uuid.
	 * @param string $submissionId The bid uuid.
	 *
	 * @return array<string, mixed> `{proposed, released}` or `{refusal}`.
	 *
	 * @throws RuntimeException When the register refuses the write.
	 *
	 * @spec openspec/changes/tenders-run-on-the-tender-case-type/specs/dossiq-procurement-tender-management/spec.md#requirement-a-tender-is-worked-end-to-end-on-its-case-page-req-tnd-case-01
	 */
	#[Override]
	public function propose(string $caseId, string $submissionId): array {
		$submissions = $this->records->scorableSubmissions(caseId: $caseId);
		$chosen      = $this->byId(submissions: $submissions, submissionId: trim($submissionId));
		if ($chosen === null) {
			return ['refusal' => $this->l10n->t('Only an admissible bid on this case can be proposed for the award.')];
		}

		$objectService = $this->settings->getObjectService();
		if ($objectService === null) {
			return ['refusal' => $this->l10n->t('The register cannot be reached. Try again later.')];
		}

		$chosenId = $this->value->idOf(row: $chosen);
		$released = [];
		foreach ($this->earlierProposals(submissions: $submissions, chosen: $chosen) as $earlier) {
			$earlierId = $this->value->idOf(row: $earlier);
			$this->setState(objectService: $objectService, id: $earlierId, state: self::RELEASED);
			$released[] = $earlierId;
		}

		if ((string)($chosen['state'] ?? '') !== self::PROPOSED) {
			$this->setState(objectService: $objectService, id: $chosenId, state: self::PROPOSED);
		}

		return ['proposed' => $chosenId, 'released' => $released];
	}//end propose()

	/**
	 * The other bids of the chosen bid's lot that are proposed now.
	 *
	 * A bid without a lot shares the lot of every other bid without one.
	 *
	 * @param list<array<string, mixed>> $submissions The case's scorable bids.
	 * @param array<string, mixed>       $chosen      The bid proposed now.
	 *
	 * @return list<array<string, mixed>> The bids to release.
	 */
	private function earlierProposals(array $submissions, array $chosen): array {
		$chosenId = $this->value->idOf(row: $chosen);
		$lot      = (string)($chosen['lot'] ?? '');

		return array_values(
			array_filter(
				$submissions,
				fn (array $row): bool => (string)($row['state'] ?? '') === self::PROPOSED
					&& (string)($row['lot'] ?? '') === $lot
					&& $this->value->idOf(row: $row) !== $chosenId
			)
		);
	}//end earlierProposals()

	/**
	 * Write the state of one bid, and nothing else.
	 *
	 * @param object $objectService The object service.
	 * @param string $id            The bid uuid.
	 * @param string $state         The new state.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the register refuses the write.
	 */
	private function setState(object $objectService, string $id, string $state): void {
		$this->patchObjectAsArray(
			objectService: $objectService,
			register: AppIdentity::REGISTER_SLUG,
			schema: ScoringRecords::SUBMISSION_SCHEMA,
			id: $id,
			changes: ['state' => $state]
		);
	}//end setState()

	/**
	 * One scorable bid by id.
	 *
	 * @param list<array<string, mixed>> $submissions  The case's scorable bids.
	 * @param string                     $submissionId The id asked for.
	 *
	 * @return array<string, mixed>|null The bid.
	 */
	private function byId(array $submissions, string $submissionId): ?array {
		foreach ($submissions as $submission) {
			if ($submissionId !== '' && $this->value->idOf(row: $submission) === $submissionId) {
				return $submission;
			}
		}

		return null;
	}//end byId()
}//end class
