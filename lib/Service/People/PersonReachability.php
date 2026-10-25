<?php

/**
 * How a person can be reached: their portal mailbox and their last contact.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\Dossiq\Service\People
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/contact-reachability/specs/party-pages/spec.md#requirement-a-contact-says-how-the-person-can-be-reached
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\People;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\Support\TrustedRevealRead;
use OCP\IL10N;
use Throwable;

/**
 * Board DqContact "Bereikbaar via": the portal mailbox when the person has a
 * portal account ("Mijn Zuiddrecht, berichtenbox", answered by portaliq) and
 * the newest contact moment. The person is read under the user's own rights
 * first: a person the user may not read gives no answer at all.
 *
 * @spec openspec/changes/contact-reachability/specs/party-pages/spec.md#requirement-a-contact-says-how-the-person-can-be-reached
 */
class PersonReachability {

	use SearchesObjects;

	/**
	 * portaliq's presence service.
	 */
	public const PRESENCE_CLASS = 'OCA\\Portaliq\\Service\\Identity\\PortalAccountPresence';

	/**
	 * Constructor.
	 *
	 * @param SettingsService   $settingsService The register and the object service.
	 * @param IL10N             $l10n            The user's language.
	 * @param TrustedRevealRead $trusted         Reads the BSN the presence question needs.
	 * @param object|null       $presence        portaliq's PortalAccountPresence; looked up when not given.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly IL10N $l10n,
		private readonly TrustedRevealRead $trusted,
		private readonly ?object $presence = null,
	) {
	}//end __construct()

	/**
	 * How the person can be reached, or null when the user may not read the person.
	 *
	 * @param string $personId The brpPerson uuid.
	 *
	 * @return array{portal: string, lastContactAt: string, lastContactChannel: string}|null
	 *
	 * @throws Throwable When the register cannot be read; the caller answers that, not "not found".
	 *
	 * @spec openspec/changes/contact-reachability/specs/party-pages/spec.md#requirement-a-contact-says-how-the-person-can-be-reached
	 */
	public function forPerson(string $personId): ?array {
		$person = $this->person(personId: $personId);
		if ($person === null) {
			return null;
		}

		$last = $this->newestMoment(personId: $personId);

		return [
			'portal' => $this->portalLine(bsn: (string)($person['citizenServiceNumber'] ?? '')),
			'lastContactAt' => (string)($last['startTime'] ?? ''),
			'lastContactChannel' => (string)($last['notificationChannel'] ?? ''),
		];
	}//end forPerson()

	/**
	 * The person, read under the user's rights, or null.
	 *
	 * @param string $personId The brpPerson uuid.
	 *
	 * @return array<string, mixed>|null
	 */
	private function person(string $personId): ?array {
		$rows = $this->rows(schema: 'brpPerson', filters: ['_ids' => [$personId], '_limit' => 1], trusted: true);
		if (is_array($rows[0] ?? null) === false) {
			return null;
		}

		return $rows[0];
	}//end person()

	/**
	 * The person's newest contact moment, or [].
	 *
	 * @param string $personId The brpPerson uuid.
	 *
	 * @return array<string, mixed>
	 */
	private function newestMoment(string $personId): array {
		$rows = $this->rows(schema: 'contactmoment', filters: ['contact' => $personId, '_order' => ['startTime' => 'desc'], '_limit' => 1], trusted: false);
		if (is_array($rows[0] ?? null) === false) {
			return [];
		}

		return $rows[0];
	}//end newestMoment()

	/**
	 * "Mijn Zuiddrecht, berichtenbox" when portaliq says the person has an
	 * active portal account, else ''.
	 *
	 * @param string $bsn The person's BSN.
	 *
	 * @return string
	 */
	private function portalLine(string $bsn): string {
		if ($bsn === '') {
			return '';
		}

		try {
			$presence = $this->presenceService();
			if ($presence === null || method_exists($presence, 'forCitizen') === false) {
				return '';
			}

			$answer = (array)$presence->forCitizen($bsn);
		} catch (Throwable) {
			return '';
		}

		$name = trim((string)($answer['portalName'] ?? ''));
		if (($answer['allowed'] ?? false) !== true || ($answer['hasAccount'] ?? false) !== true || $name === '') {
			return '';
		}

		return $this->l10n->t('%s, message box', [$name]);
	}//end portalLine()

	/**
	 * portaliq's presence service, or null when portaliq is absent.
	 *
	 * @return object|null
	 *
	 * @throws Throwable When the container cannot build it.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) `\OCP\Server::get()` is the public container lookup for another app's service.
	 */
	private function presenceService(): ?object {
		if ($this->presence !== null) {
			return $this->presence;
		}

		if (class_exists(self::PRESENCE_CLASS) === false) {
			return null;
		}

		return \OCP\Server::get(self::PRESENCE_CLASS);
	}//end presenceService()

	/**
	 * Rows of one schema under the user's rights; trusted reads see the BSN.
	 *
	 * @param string               $schema  The schema slug.
	 * @param array<string, mixed> $filters The filters.
	 * @param bool                 $trusted Whether the read needs explicit-reveal values.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @throws Throwable When the register cannot be read.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Whether the read needs the BSN is the one thing that differs.
	 */
	private function rows(string $schema, array $filters, bool $trusted): array {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		if ($objectService === null || $register === '') {
			return [];
		}

		$read = fn (): array => $this->searchObjectsAsArrays(objectService: $objectService, register: $register, schema: $schema, filters: $filters);
		if ($trusted === true) {
			return (array)$this->trusted->run($read);
		}

		return $read();
	}//end rows()
}//end class
