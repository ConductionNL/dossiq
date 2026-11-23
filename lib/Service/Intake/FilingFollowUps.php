<?php

/**
 * What linking a message to a case may also do (board DqKoppelAanZaak).
 *
 * The Link to case dialog offers two choices under "When linking": put the
 * sender on the case as a party when they are not on it yet, and tell the
 * case's handler a message was added. Both run after the message is filed;
 * neither undoes the filing when it cannot be done.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Intake
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Intake;

use DateTime;
use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Kernel\Contracts\FilingFollowUpsInterface;
use OCA\Dossiq\Notification\Notifier;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\ReadsConfiguredSource;
use OCA\Dossiq\Service\Support\ReferenceId;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Notification\IManager;

/**
 * Puts a message's sender on the case and tells its handler.
 *
 * @spec openspec/specs/case-email-integration/spec.md
 */
class FilingFollowUps implements FilingFollowUpsInterface {
	use SearchesObjects;
	use ReadsConfiguredSource;

	/**
	 * The generic roles a sender may take on a case, first fit first.
	 */
	private const SENDER_ROLES = ['stakeholder', 'contact'];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Schema slug bridge.
	 * @param IManager        $notifications   Nextcloud's notification manager.
	 * @param IFactory        $l10nFactory     The handler's language.
	 * @param IUserManager    $users           The handler as a user, for their language.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly IManager $notifications,
		private readonly IFactory $l10nFactory,
		private readonly IUserManager $users,
	) {
	}//end __construct()

	/**
	 * Put the sender on the case as a party, unless they already are one.
	 *
	 * @param string $caseId  The case.
	 * @param string $address The sender's address.
	 * @param string $name    The sender's name, '' when the message gave none.
	 *
	 * @return string `added`, `already`, or `no_role_type` when the case type has no party role to give.
	 *
	 * @spec openspec/specs/case-email-integration/spec.md
	 */
	public function addSenderAsParty(string $caseId, string $address, string $name): string {
		$participant = 'mailto:' . strtolower(trim($address));
		$roles = $this->configuredSource(schemaKey: 'role_schema');
		$case = $this->caseOf(caseId: $caseId);
		if ($roles === null || $case === null || $participant === 'mailto:') {
			return 'no_role_type';
		}

		[$objectService, $register, $schema] = $roles;
		$existing = $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			filters: ['case' => $caseId, '_limit' => 200]
		);
		foreach ($existing as $role) {
			if (strtolower((string)($role['participant'] ?? '')) === $participant) {
				return 'already';
			}
		}

		$roleType = $this->senderRoleType(caseTypeId: ReferenceId::of(value: ($case['caseType'] ?? null)));
		if ($roleType === '') {
			return 'no_role_type';
		}

		$label = trim($name);
		if ($label === '') {
			$label = trim($address);
		}

		$this->saveObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			object: [
				'name' => $label,
				'roleType' => $roleType,
				'case' => $caseId,
				'participant' => $participant,
			]
		);

		return 'added';
	}//end addSenderAsParty()

	/**
	 * Tell the case's handler a message was linked to it, unless the handler linked it.
	 *
	 * @param string $caseId   The case.
	 * @param string $callerId Who linked the message.
	 * @param string $sender   The sender, by name or address.
	 * @param string $subject  The message's subject.
	 *
	 * @return string `notified`, or `none` when there is no other handler to tell.
	 *
	 * @spec openspec/specs/case-email-integration/spec.md
	 */
	public function notifyHandler(string $caseId, string $callerId, string $sender, string $subject): string {
		$case = $this->caseOf(caseId: $caseId);
		$handler = trim((string)($case['assignee'] ?? ''));
		if ($handler === '' || $handler === $callerId) {
			return 'none';
		}

		$l = $this->l10nFactory->get(AppIdentity::APP_ID, $this->l10nFactory->getUserLanguage($this->users->get($handler)));
		$notification = $this->notifications->createNotification();
		$notification->setApp(AppIdentity::APP_ID)
			->setUser($handler)
			->setDateTime(new DateTime())
			->setObject('case', $caseId)
			->setSubject(
				Notifier::SUBJECT_CASE_ROLE_NOTIFIED,
				['message' => $l->t('A message from %1$s was linked to this case: %2$s', [$sender, $subject])]
			);
		$this->notifications->notify($notification);

		return 'notified';
	}//end notifyHandler()

	/**
	 * The case, or null when it cannot be read.
	 *
	 * @param string $caseId The case.
	 *
	 * @return array<string, mixed>|null
	 */
	private function caseOf(string $caseId): ?array {
		$source = $this->configuredSource(schemaKey: 'case_schema');
		if ($source === null) {
			return null;
		}

		[$objectService, $register, $schema] = $source;

		return $this->findObjectAsArray(objectService: $objectService, register: $register, schema: $schema, id: $caseId);
	}//end caseOf()

	/**
	 * The case type's role type a sender takes on its cases, or '' when it has none.
	 *
	 * @param string $caseTypeId The case type.
	 *
	 * @return string The role type id.
	 */
	private function senderRoleType(string $caseTypeId): string {
		$source = $this->configuredSource(schemaKey: 'role_type_schema');
		if ($source === null || $caseTypeId === '') {
			return '';
		}

		[$objectService, $register, $schema] = $source;
		$types = $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			filters: ['caseType' => $caseTypeId, '_limit' => 100]
		);
		foreach (self::SENDER_ROLES as $generic) {
			foreach ($types as $type) {
				if (($type['genericRole'] ?? '') === $generic) {
					return (string)($type['id'] ?? ReferenceId::of(value: ($type['@self'] ?? null)));
				}
			}
		}

		return '';
	}//end senderRoleType()
}//end class
