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
 * @spec openspec/changes/custody-and-handover-of-a-case/specs/dossiq-sociaal-domein-avg-consent/spec.md
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
 * @spec openspec/changes/custody-and-handover-of-a-case/specs/dossiq-sociaal-domein-avg-consent/spec.md
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
	 * How many consents one case read takes at most.
	 *
	 * @var int
	 */
	public const PAGE_SIZE = 100;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Bridge to OpenRegister and the configured schemas.
	 * @param LoggerInterface $logger          Records a gate that could not be evaluated.
	 *
	 * @spec openspec/changes/custody-and-handover-of-a-case/specs/dossiq-sociaal-domein-avg-consent/spec.md
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
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
	 * @param string $receivingOrganisation The organisation receiving it.
	 * @param string $at                    The moment of the hand-off in ISO 8601, or empty for now.
	 *
	 * @return array{allowed: bool, rule: string, sentence: string, consent: array<string, mixed>|null, scope: array<int, string>, until: string, crossesOrganisation: bool}
	 *         The verdict, and the scope when there is one.
	 *
	 * @spec openspec/changes/custody-and-handover-of-a-case/specs/dossiq-sociaal-domein-avg-consent/spec.md#requirement-a-hand-off-across-organisations-needs-recorded-consent-req-cst-01
	 */
	public function assess(
		string $caseId,
		string $sourceOrganisation,
		string $receivingOrganisation,
		string $at = '',
	): array {
		$receiver = trim($receivingOrganisation);
		$crosses = $this->crossesOrganisation(
			sourceOrganisation: $sourceOrganisation,
			receivingOrganisation: $receiver,
		);

		if ($crosses === false && $this->consentRequiredInside(caseId: $caseId) === false) {
			// A move between two teams of one organisation is not a
			// disclosure (D-7), so there is nothing to consent to.
			return $this->verdict(allowed: true, rule: '', sentence: '', consent: null, crosses: false);
		}

		$moment = $this->instant(value: $at) ?? new DateTimeImmutable();
		$candidates = $this->consentsFor(caseId: $caseId, receiver: $receiver);

		if ($candidates === []) {
			return $this->verdict(
				allowed: false,
				rule: self::NO_CONSENT,
				sentence: 'There is no recorded consent for ' . $receiver . ', so the case was not handed on.',
				consent: null,
				crosses: $crosses,
			);
		}

		$lapsedOn = '';
		$withdrawn = false;
		foreach ($candidates as $consent) {
			if (($consent['withdrawn'] ?? false) === true) {
				$withdrawn = true;
				continue;
			}

			$grantedOn = $this->instant(value: (string)($consent['grantedDate'] ?? ''));
			if ($grantedOn !== null && $grantedOn > $moment) {
				continue;
			}

			$expiresOn = $this->instant(value: (string)($consent['validTo'] ?? ''));
			if ($expiresOn !== null && $expiresOn < $moment) {
				$lapsedOn = (string)$consent['validTo'];
				continue;
			}

			return $this->verdict(allowed: true, rule: '', sentence: '', consent: $consent, crosses: $crosses);
		}

		if ($lapsedOn !== '') {
			return $this->verdict(
				allowed: false,
				rule: self::CONSENT_LAPSED,
				sentence: 'The consent for ' . $receiver . ' ran to ' . $lapsedOn . ' and has ended, so the case was not handed on.',
				consent: null,
				crosses: $crosses,
			);
		}

		if ($withdrawn === true) {
			return $this->verdict(
				allowed: false,
				rule: self::CONSENT_WITHDRAWN,
				sentence: 'The consent for ' . $receiver . ' was withdrawn, so the case was not handed on.',
				consent: null,
				crosses: $crosses,
			);
		}

		return $this->verdict(
			allowed: false,
			rule: self::NO_CONSENT,
			sentence: 'There is no recorded consent for ' . $receiver . ' covering today, so the case was not handed on.',
			consent: null,
			crosses: $crosses,
		);
	}//end assess()

	/**
	 * Whether the hand-off leaves the organisation.
	 *
	 * An empty receiver is treated as crossing: a hand-off that cannot name
	 * where the case is going is the one case where guessing "internal" would
	 * skip the gate entirely.
	 *
	 * @param string $sourceOrganisation    The organisation letting go.
	 * @param string $receivingOrganisation The organisation receiving.
	 *
	 * @return bool True when the boundary is crossed.
	 */
	public function crossesOrganisation(string $sourceOrganisation, string $receivingOrganisation): bool {
		$source = strtolower(trim($sourceOrganisation));
		$receiver = strtolower(trim($receivingOrganisation));

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
	 * @spec openspec/changes/custody-and-handover-of-a-case/specs/dossiq-sociaal-domein-avg-consent/spec.md#requirement-the-recorded-scope-travels-with-the-share-req-cst-02
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
	 * An unreadable case or case type answers FALSE, and deliberately: this
	 * branch only ever runs for a move that stays inside one organisation,
	 * where the default is that no consent is needed. The cross-organisation
	 * refusal does not pass through here at all.
	 *
	 * @param string $caseId The case.
	 *
	 * @return bool Whether an internal move needs consent too.
	 *
	 * @spec openspec/changes/custody-and-handover-of-a-case/specs/dossiq-sociaal-domein-avg-consent/spec.md#requirement-a-hand-off-across-organisations-needs-recorded-consent-req-cst-01
	 */
	public function consentRequiredInside(string $caseId): bool {
		$caseId = trim($caseId);
		if ($caseId === '') {
			return false;
		}

		try {
			[$objectService, $register] = $this->context();
			$case = $this->findObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $this->schema(key: 'case_schema'),
				id: $caseId,
			);
			$caseTypeId = trim((string)($case['caseType'] ?? ''));
			if ($caseTypeId === '') {
				return false;
			}

			$caseType = $this->findObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $this->schema(key: 'case_type_schema'),
				id: $caseTypeId,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq consent: the case type could not be read for the internal-move rule',
				['caseId' => $caseId, 'exception' => $e->getMessage()],
			);

			return false;
		}

		return (($caseType['consentRequiredInsideOrganisation'] ?? false) === true);
	}//end consentRequiredInside()

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
			[$objectService, $register] = $this->context();
			$rows = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $this->schema(key: 'consent_schema'),
				filters: ['caseId' => $caseId, '_limit' => self::PAGE_SIZE],
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq consent: the recorded consents could not be read',
				['caseId' => $caseId, 'exception' => $e->getMessage()],
			);

			return [];
		}

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
	}//end consentsFor()

	/**
	 * Shape one verdict.
	 *
	 * @param bool                      $allowed  Whether the hand-off may proceed.
	 * @param string                    $rule     The refusal rule slug, empty when allowed.
	 * @param string                    $sentence The refusal sentence, empty when allowed.
	 * @param array<string, mixed>|null $consent  The covering consent, when there is one.
	 * @param bool                      $crosses  Whether the boundary was crossed.
	 *
	 * @return array{allowed: bool, rule: string, sentence: string, consent: array<string, mixed>|null, scope: array<int, string>, until: string, crossesOrganisation: bool} The verdict.
	 */
	private function verdict(bool $allowed, string $rule, string $sentence, ?array $consent, bool $crosses): array {
		return [
			'allowed' => $allowed,
			'rule' => $rule,
			'sentence' => $sentence,
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
		$value = trim($value);
		if ($value === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (Throwable $e) {
			return null;
		}
	}//end instant()

	/**
	 * The object service and the register, or an exception.
	 *
	 * @return array{0: object, 1: string} The service and the register.
	 *
	 * @throws RuntimeException When OpenRegister is absent or unconfigured.
	 */
	private function context(): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$register = $this->settingsService->getConfigValue('register');
		if ($register === '') {
			throw new RuntimeException('Dossier register not configured');
		}

		return [$objectService, $register];
	}//end context()

	/**
	 * A configured schema, or an exception naming the key.
	 *
	 * @param string $key The configuration key.
	 *
	 * @return string The schema id or slug.
	 *
	 * @throws RuntimeException When the key is unset.
	 */
	private function schema(string $key): string {
		$schema = $this->settingsService->getConfigValue($key);
		if ($schema === '') {
			throw new RuntimeException('Dossiq schema ' . $key . ' not configured');
		}

		return $schema;
	}//end schema()
}//end class
