<?php

/**
 * The consent a case needs before it leaves the organisation.
 *
 * `toestemming` has been declared in the sociaal-domein register since that
 * fragment shipped, and nothing read it: the slug was never mapped to a config
 * key, so no service could resolve the schema even if it had wanted to. A Wmo
 * file could therefore be handed to a partner organisation with no recorded
 * consent and no limit on what the partner would see.
 *
 * This gate is a precondition, not a warning (D-5). A dialog asking "are you
 * sure, this is sensitive" moves the decision to the person with the least
 * information. Without a consent covering this case, this receiver and this
 * moment, the hand-off does not happen, and the refusal says which of the three
 * is missing.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Custody
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
 * @spec openspec/specs/dossiq-sociaal-domein-avg-consent/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Custody;

use DateTimeImmutable;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Whether this case may be handed to this receiver now, and under what scope.
 *
 * @phpstan-type Outcome array{allowed: bool, rule: string, sentence: string, consent: array<string, mixed>|null}
 * @psalm-type   Outcome = array{allowed: bool, rule: string, sentence: string, consent: array<string, mixed>|null}
 *
 * @spec openspec/specs/dossiq-sociaal-domein-avg-consent/spec.md
 */
class CaseTransferConsentGate {

	use SearchesObjects;

	/**
	 * The refusal when no consent names this receiver at all.
	 *
	 * @var string
	 */
	public const NO_CONSENT = 'consent-missing';

	/**
	 * The refusal when the consent that names this receiver has lapsed.
	 *
	 * @var string
	 */
	public const CONSENT_LAPSED = 'consent-lapsed';

	/**
	 * The refusal when the consent was withdrawn.
	 *
	 * @var string
	 */
	public const CONSENT_WITHDRAWN = 'consent-withdrawn';

	/**
	 * The refusal when a move inside one organisation cannot be checked against its case type.
	 *
	 * @var string
	 */
	public const CONSENT_UNCHECKED = 'consent-unchecked';

	/**
	 * How many consents one case read takes at most.
	 *
	 * @var int
	 */
	public const PAGE_SIZE = 100;

	/**
	 * Where each recorded consent stands.
	 *
	 * @var ConsentStanding
	 */
	private readonly ConsentStanding $standings;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Bridge to OpenRegister and the configured schemas.
	 * @param LoggerInterface $logger          Records a gate that could not be evaluated.
	 * @param ConsentRefusal  $refusals        Words a refusal in the user's language.
	 *
	 * @spec openspec/specs/dossiq-sociaal-domein-avg-consent/spec.md
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
		private readonly ConsentRefusal $refusals,
	) {
		$this->standings = new ConsentStanding();
	}//end __construct()

	/**
	 * Assess a hand-off.
	 *
	 * Returns the verdict rather than throwing, because both callers want the
	 * scope on the way through: {@see \OCA\Dossiq\Service\CaseTransferService}
	 * refuses on it and {@see \OCA\Dossiq\Service\CaseSharingService} writes the
	 * scope onto the share it then creates. A gate that only threw would make
	 * the second caller ask the same question twice and risk the two answers
	 * disagreeing.
	 *
	 * @param string $caseId                The case being handed on.
	 * @param string $sourceOrganisation    The organisation letting go of it.
	 * @param string $receivingOrg The organisation receiving it.
	 * @param string $atDate               The moment of the hand-off in ISO 8601, or empty for now.
	 *
	 * @return array{
	 *     allowed: bool,
	 *     rule: string,
	 *     sentence: string,
	 *     consent: array<string, mixed>|null,
	 *     scope: array<int, string>,
	 *     until: string,
	 *     crossesOrganisation: bool
	 * } The verdict, and the scope when there is one.
	 *
	 * @spec openspec/specs/dossiq-sociaal-domein-avg-consent/spec.md#requirement-a-hand-off-across-organisations-needs-recorded-consent-req-cst-01
	 */
	public function assess(
		string $caseId,
		string $sourceOrganisation,
		string $receivingOrg,
		string $atDate = '',
	): array {
		$receiver = trim($receivingOrg);
		$crosses = $this->crossesOrganisation(
			sourceOrganisation: $sourceOrganisation,
			receivingOrg: $receiver,
		);

		if ($crosses === false) {
			$inside = $this->insideOutcome(caseId: $caseId);
			if ($inside !== null) {
				return $this->verdict(outcome: $inside, crosses: false);
			}
		}

		$moment = $this->instant(value: $atDate) ?? new DateTimeImmutable();
		$candidates = $this->consentsFor(caseId: $caseId, receiver: $receiver);
		$scan = $this->scanConsents(candidates: $candidates, moment: $moment);
		if ($scan['covering'] !== null) {
			return $this->verdict(outcome: $this->granted(consent: $scan['covering']), crosses: $crosses);
		}

		$refusal = $this->refusals->uncovered(scan: $scan + ['none' => ($candidates === [])], receiver: $receiver);

		return $this->verdict(outcome: $refusal, crosses: $crosses);
	}//end assess()

	/**
	 * The outcome of a move inside one organisation, or null when it needs a consent check.
	 *
	 * A move between two teams of one organisation is not a disclosure (D-7),
	 * so it proceeds unless its case type demands consent. When the case or
	 * its case type cannot be read, or the case has none, the move is refused.
	 *
	 * @param string $caseId The case.
	 *
	 * @return Outcome|null The outcome, or null to check the recorded consents.
	 *
	 * @spec openspec/specs/dossiq-sociaal-domein-avg-consent/spec.md
	 */
	private function insideOutcome(string $caseId): ?array {
		try {
			$required = $this->readInsideRule(caseId: trim($caseId));
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq consent: the case type could not be read for the internal-move rule',
				['caseId' => $caseId, 'exception' => $e->getMessage()],
			);

			return $this->refusals->unchecked();
		}

		if ($required === true) {
			return null;
		}

		return $this->granted(consent: null);
	}//end insideOutcome()

	/**
	 * The outcome of a hand-off that may proceed.
	 *
	 * @param array<string, mixed>|null $consent The covering consent, or null when none is needed.
	 *
	 * @return Outcome The outcome.
	 */
	private function granted(?array $consent): array {
		return ['allowed' => true, 'rule' => '', 'sentence' => '', 'consent' => $consent];
	}//end granted()

	/**
	 * Read the recorded consents once, and say what they amount to.
	 *
	 * The first consent that covers the moment wins and is answered as
	 * `covering`. When none does, what the others were is what decides the
	 * sentence: a consent that ran out says so with its date, and a withdrawal
	 * says so in its own words, because "there is no consent" is the wrong
	 * thing to tell a handler about a consent somebody deliberately took back.
	 *
	 * @param array<int, array<string, mixed>> $candidates The recorded consents.
	 * @param DateTimeImmutable                $moment     The moment being asked about.
	 *
	 * @return array{covering: array<string, mixed>|null, lapsedOn: string, withdrawn: bool} What they amount to.
	 *
	 * @spec openspec/specs/dossiq-sociaal-domein-avg-consent/spec.md#requirement-a-hand-off-across-organisations-needs-recorded-consent-req-cst-01
	 */
	private function scanConsents(array $candidates, DateTimeImmutable $moment): array {
		$lapsedOn = '';
		$withdrawn = false;

		foreach ($candidates as $consent) {
			$standing = $this->standings->standingOf(consent: $consent, moment: $moment);
			if ($standing === ConsentStanding::COVERS) {
				return ['covering' => $consent, 'lapsedOn' => $lapsedOn, 'withdrawn' => $withdrawn];
			}

			if ($standing === ConsentStanding::WITHDRAWN) {
				$withdrawn = true;
			}

			if ($standing === ConsentStanding::LAPSED) {
				$lapsedOn = (string)$consent['validTo'];
			}
		}

		return ['covering' => null, 'lapsedOn' => $lapsedOn, 'withdrawn' => $withdrawn];
	}//end scanConsents()


	/**
	 * Whether the hand-off leaves the organisation.
	 *
	 * An empty receiver is treated as crossing: a hand-off that cannot name
	 * where the case is going is the one case where guessing "internal" would
	 * skip the gate entirely.
	 *
	 * @param string $sourceOrganisation    The organisation letting go.
	 * @param string $receivingOrg The organisation receiving.
	 *
	 * @return bool True when the boundary is crossed.
	 * @spec openspec/specs/dossiq-sociaal-domein-avg-consent/spec.md
	 */
	public function crossesOrganisation(string $sourceOrganisation, string $receivingOrg): bool {
		$source = strtolower(trim($sourceOrganisation));
		$receiver = strtolower(trim($receivingOrg));

		if ($source === '' || $receiver === '') {
			return true;
		}

		return ($source !== $receiver);
	}//end crossesOrganisation()

	/**
	 * The scope a consent grants: the data fields, or the free-text reach.
	 *
	 * @param array<string, mixed>|null $consent The consent, or null.
	 *
	 * @return array<int, string> The scope, empty when there is none.
	 *
	 * @spec openspec/specs/dossiq-sociaal-domein-avg-consent/spec.md#requirement-the-recorded-scope-travels-with-the-share-req-cst-02
	 */
	public function scopeOf(?array $consent): array {
		if ($consent === null) {
			return [];
		}

		$fields = [];
		foreach ((array)($consent['tegegevens'] ?? []) as $field) {
			$field = trim((string)$field);
			if ($field !== '') {
				$fields[] = $field;
			}
		}

		if ($fields !== []) {
			return $fields;
		}

		$reach = trim((string)($consent['scope'] ?? ''));
		if ($reach === '') {
			return [];
		}

		return [$reach];
	}//end scopeOf()

	/**
	 * Whether this case's type demands consent for a move inside the organisation.
	 *
	 * Declared on the case type rather than decided here, under ADR-031: an
	 * administrator can read which case types may not move without one, and a
	 * Jeugdwet file and a parking permit are not the same question (D-7).
	 *
	 * A case or case type that cannot be read answers true: consent is then
	 * treated as needed, because whether it is cannot be checked.
	 *
	 * @param string $caseId The case.
	 *
	 * @return bool Whether an internal move needs consent too.
	 *
	 * @spec openspec/specs/dossiq-sociaal-domein-avg-consent/spec.md
	 */
	public function consentRequiredInside(string $caseId): bool {
		return (($this->insideOutcome(caseId: $caseId)['allowed'] ?? false) === false);
	}//end consentRequiredInside()

	/**
	 * Read the case and its case type, and answer the case type's internal-move rule.
	 *
	 * @param string $caseId The trimmed case id.
	 *
	 * @return bool The case type's rule.
	 *
	 * @throws RuntimeException When the case or its case type cannot be read, or the case has none.
	 *
	 * @spec openspec/specs/dossiq-sociaal-domein-avg-consent/spec.md
	 */
	private function readInsideRule(string $caseId): bool {
		if ($caseId === '') {
			throw new RuntimeException('No case id');
		}

		[$objectService, $register] = $this->dossierRegister()->context();
		$case = $this->readCase(objectService: $objectService, register: $register, caseId: $caseId);

		$caseTypeId = trim((string)($case['caseType'] ?? ''));
		if ($caseTypeId === '') {
			throw new RuntimeException('Case has no case type');
		}

		return $this->caseTypeInsideRule(objectService: $objectService, register: $register, caseTypeId: $caseTypeId);
	}//end readInsideRule()

	/**
	 * Read the case.
	 *
	 * @param object $objectService The OpenRegister object service.
	 * @param string $register      The dossier register.
	 * @param string $caseId        The trimmed case id.
	 *
	 * @return array<string, mixed> The case.
	 *
	 * @throws RuntimeException When the case is not found.
	 *
	 * @spec openspec/specs/dossiq-sociaal-domein-avg-consent/spec.md
	 */
	private function readCase(object $objectService, string $register, string $caseId): array {
		$case = $this->findObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $this->dossierRegister()->schema(key: 'case_schema'),
			id: $caseId,
		);
		if ($case === null) {
			throw new RuntimeException('Case not found');
		}

		return $case;
	}//end readCase()

	/**
	 * Read the case type and answer its internal-move rule.
	 *
	 * @param object $objectService The OpenRegister object service.
	 * @param string $register      The dossier register.
	 * @param string $caseTypeId    The case type id.
	 *
	 * @return bool Whether the case type requires consent inside the organisation.
	 *
	 * @throws RuntimeException When the case type is not found.
	 *
	 * @spec openspec/specs/dossiq-sociaal-domein-avg-consent/spec.md
	 */
	private function caseTypeInsideRule(object $objectService, string $register, string $caseTypeId): bool {
		$caseType = $this->findObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $this->dossierRegister()->schema(key: 'case_type_schema'),
			id: $caseTypeId,
		);
		if ($caseType === null) {
			throw new RuntimeException('Case type not found');
		}

		return (($caseType['consentRequiredInsideOrganisation'] ?? false) === true);
	}//end caseTypeInsideRule()

	/**
	 * The consents recorded for a case that name this receiver.
	 *
	 * @param string $caseId   The case.
	 * @param string $receiver The receiving organisation.
	 *
	 * @return array<int, array<string, mixed>> The candidates.
	 */
	private function consentsFor(string $caseId, string $receiver): array {
		$caseId = trim($caseId);
		if ($caseId === '' || $receiver === '') {
			return [];
		}

		try {
			[$objectService, $register] = $this->dossierRegister()->context();
			$rows = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $this->dossierRegister()->schema(key: 'consent_schema'),
				filters: ['caseId' => $caseId, '_limit' => self::PAGE_SIZE],
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq consent: the recorded consents could not be read',
				['caseId' => $caseId, 'exception' => $e->getMessage()],
			);

			return [];
		}

		return $this->namingReceiver(rows: $rows, receiver: $receiver);
	}//end consentsFor()

	/**
	 * The consent rows whose recipient parties name this receiver.
	 *
	 * @param array<int, array<string, mixed>> $rows     The recorded consents.
	 * @param string                           $receiver The receiving organisation.
	 *
	 * @return array<int, array<string, mixed>> The candidates.
	 */
	private function namingReceiver(array $rows, string $receiver): array {
		$named = [];
		foreach ($rows as $row) {
			foreach ((array)($row['recipientParties'] ?? []) as $party) {
				if (strtolower(trim((string)$party)) === strtolower($receiver)) {
					$named[] = $row;
					break;
				}
			}
		}

		return $named;
	}//end namingReceiver()

	/**
	 * Shape one verdict.
	 *
	 * @param Outcome $outcome Whether it may proceed, the refusal rule and sentence, and the covering consent.
	 * @param bool    $crosses Whether the boundary was crossed.
	 *
	 * @return array{
	 *     allowed: bool,
	 *     rule: string,
	 *     sentence: string,
	 *     consent: array<string, mixed>|null,
	 *     scope: array<int, string>,
	 *     until: string,
	 *     crossesOrganisation: bool
	 * } The verdict.
	 */
	private function verdict(array $outcome, bool $crosses): array {
		$consent = $outcome['consent'];
		return [
			'allowed' => $outcome['allowed'],
			'rule' => $outcome['rule'],
			'sentence' => $outcome['sentence'],
			'consent' => $consent,
			'scope' => $this->scopeOf(consent: $consent),
			'until' => trim((string)($consent['validTo'] ?? '')),
			'crossesOrganisation' => $crosses,
		];
	}//end verdict()

	/**
	 * A moment, or null when the value is empty or unreadable.
	 *
	 * @param string $value The candidate.
	 *
	 * @return DateTimeImmutable|null The moment.
	 */
	private function instant(string $value): ?DateTimeImmutable {
		$date = $this->standings->dateOf(value: $value);
		if ($date === false) {
			return null;
		}

		return $date;
	}//end instant()
	/**
	 * The dossier register and its configured schemas.
	 *
	 * @return DossierRegister The register reader.
	 */
	private function dossierRegister(): DossierRegister {
		return new DossierRegister(settingsService: $this->settingsService);
	}//end dossierRegister()
}//end class
