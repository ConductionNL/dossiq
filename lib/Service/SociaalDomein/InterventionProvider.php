<?php

/**
 * The provider of an intervention, as a party rather than as a name.
 *
 * 🔴 A STRING CANNOT BE PHONED. The provider is an organisation the
 * municipality already knows, usually with a contract and a contact, and
 * writing its name into a text field means it is spelled three ways across
 * four plans, nobody can report on it, and correcting it once corrects
 * nothing. So `intervention.provider` holds a reference into the platform's
 * contact model, and this class is the only thing that turns one into a party
 * to show.
 *
 * 🔑 WHAT COUNTS AS A REFERENCE IS DECIDED HERE AND NOWHERE ELSE. OpenRegister's
 * contact model spells a contact `user:<uid>` for a Nextcloud account and a
 * vCard uid otherwise, which is the same spelling
 * {@see \OCA\Dossiq\Service\People\PersonLinkReader} already reads. A value
 * that is neither is a name somebody typed, and it is refused on save rather
 * than stored and rendered as if it were a party: a plan that shows "Jeugdzorg
 * Midden" as a provider and cannot say who to call is exactly the string this
 * row replaces.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\SociaalDomein
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
 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-jeugdwet/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\SociaalDomein;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\SettingsService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolve an intervention's provider to a party with contact details.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-jeugdwet/spec.md
 */
class InterventionProvider {

	/**
	 * OpenRegister's contact service, by name.
	 *
	 * The published object contract carries no contact method, so this goes
	 * through the generic class resolver, the same exception ADR-084 already
	 * makes for the file service and that `PersonLinkReader` already takes.
	 *
	 * @var string
	 */
	public const CONTACT_SERVICE = 'OCA\\OpenRegister\\Service\\ContactService';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The OpenRegister seam.
	 * @param LoggerInterface $logger          The logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether a value is a reference into the contact model at all.
	 *
	 * A SHAPE test, deliberately, rather than "try the lookup and fall back":
	 * a fallback would turn every unreachable contact service into a silent
	 * pass for a typed name, which is the one thing this class exists to stop.
	 *
	 * @param string $value The stored value.
	 *
	 * @return boolean True when it is a contact reference.
	 *
	 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-jeugdwet/spec.md#requirement-a-case-plan-holds-interventions-with-a-goal-a-provider-and-dates-req-cpn-01
	 */
	public function isReference(string $value): bool {
		$value = trim($value);
		if ($value === '') {
			return false;
		}

		if (str_starts_with($value, 'user:') === true) {
			return (trim(substr($value, 5)) !== '');
		}

		// A vCard uid or an object uuid: no spaces, and long enough not to be
		// a word somebody typed. "Jeugdzorg Midden" fails on the space,
		// "Buurtteam" on the length.
		return (preg_match('/^[A-Za-z0-9._:-]{12,}$/', $value) === 1);
	}//end isReference()

	/**
	 * Refuse a provider that is a typed name rather than a party.
	 *
	 * @param string $value The stored value.
	 *
	 * @return void
	 *
	 * @throws RefusedException When it is not a reference.
	 *
	 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-jeugdwet/spec.md#requirement-a-case-plan-holds-interventions-with-a-goal-a-provider-and-dates-req-cpn-01
	 */
	public function assertReference(string $value): void {
		if (trim($value) === '') {
			throw new RefusedException(
				rule: 'intervention-needs-a-provider',
				sentence: 'Say who carries this intervention out. Pick them from the contacts, so the plan can say who to call.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		if ($this->isReference(value: $value) === false) {
			throw new RefusedException(
				rule: 'provider-is-not-a-party',
				sentence: 'Pick the provider from the contacts rather than typing a name. '
					. 'A typed name cannot be phoned, reported on, or corrected once for every plan that uses it.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}
	}//end assertReference()

	/**
	 * The party behind a provider reference, with its contact details.
	 *
	 * @param string $reference The contact reference.
	 * @param string $objectId  The object the contact is linked to, for the lookup.
	 *
	 * @return array<string, mixed>|null The party, or null when it cannot be resolved.
	 *
	 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-jeugdwet/spec.md#requirement-a-case-plan-holds-interventions-with-a-goal-a-provider-and-dates-req-cpn-01
	 */
	public function resolve(string $reference, string $objectId): ?array {
		if ($this->isReference(value: $reference) === false || trim($objectId) === '') {
			return null;
		}

		$contacts = $this->settingsService->getOpenRegisterClass(class: self::CONTACT_SERVICE);
		if ($contacts === null) {
			// NOT a refusal: the plan still reads, with the stored display name
			// beside a reference that could not be expanded. Refusing the whole
			// plan because the contact service is away would hide the goals too.
			$this->logger->warning(
				'InterventionProvider: OpenRegister\'s contact service is not reachable, so a provider is shown '
				. 'by its stored name and not by its party'
			);

			return null;
		}

		try {
			$listing = $contacts->getContactsForObject($objectId);
		} catch (Throwable $e) {
			$this->logger->warning(
				'InterventionProvider: the contacts of a plan could not be read',
				['object' => $objectId, 'error' => $e->getMessage()]
			);

			return null;
		}

		return $this->partyIn(listing: $listing, reference: $reference);
	}//end resolve()

	/**
	 * The party a listing holds under this reference, or null.
	 *
	 * @param mixed  $listing   Whatever the contact service answered.
	 * @param string $reference The contact reference being looked for.
	 *
	 * @return array<string, mixed>|null The party, or null when the listing does not hold it.
	 *
	 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-jeugdwet/spec.md#requirement-a-case-plan-holds-interventions-with-a-goal-a-provider-and-dates-req-cpn-01
	 */
	private function partyIn(mixed $listing, string $reference): ?array {
		$rows = [];
		if (is_array($listing) === true && is_array(($listing['results'] ?? null)) === true) {
			$rows = $listing['results'];
		}

		foreach ($rows as $row) {
			if (is_array($row) === true && (string)($row['contactUid'] ?? '') === $reference) {
				return [
					'contactUid' => $reference,
					'displayName' => trim((string)($row['displayName'] ?? '')),
					'email' => trim((string)($row['email'] ?? '')),
					'telephone' => trim((string)($row['telephone'] ?? ($row['phone'] ?? ''))),
					'organisation' => trim((string)($row['organisation'] ?? '')),
				];
			}
		}

		return null;
	}//end partyIn()
}//end class
