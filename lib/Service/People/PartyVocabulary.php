<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\People;

use OCP\IL10N;

/**
 * What kinds of party a case takes, and the roles they may hold on it.
 *
 * OpenRegister reads two configuration keys off the case schema. `partyKinds`
 * says which kinds of party the case accepts, and refuses a write naming
 * another. `linkRoles` carries the label for every role a party link may
 * name, which is what the Roles widget renders `byRole` under.
 *
 * The kinds live here rather than in the register descriptor because the
 * labels are translated: OpenRegister normalises a `linkRoles` entry down to
 * `{key, label, description?}` and DROPS anything else, so a per-language map
 * inside the entry would disappear without a word. One label is stored, in
 * the language the sync ran in, and the widget translates the well-known keys
 * again on screen so the reader sees their own language either way.
 *
 * Person and organisation deliberately name NO roles. A kind naming roles
 * holds only those, and the person links `people-on-the-case` writes carry a
 * role type uuid as their role, so binding a role list to those two kinds
 * would refuse every role type this instance declares. Address names one,
 * because an address is where the case is, never who represents the
 * applicant, and that refusal is the one worth having.
 *
 * @spec openspec/changes/gemachtigde-role-on-every-case-type/specs/roles-decisions/spec.md#requirement-the-case-declares-the-kinds-of-party-it-takes-req-role-011
 */
class PartyVocabulary {

	/**
	 * The kind an address party carries, and the only role it may hold.
	 */
	public const ROLE_LOCATION = 'locatie';

	/**
	 * The requester: the party the case is filed for. The primary party of
	 * the case carries this role.
	 */
	public const ROLE_REQUESTER = 'aanvrager';

	/**
	 * The authorised representative, Awb 2:1. Every case type offers it,
	 * because anyone may let a representative act for them in any case.
	 */
	public const ROLE_REPRESENTATIVE = 'gemachtigde';

	/**
	 * @param IL10N $l10n Translates the labels that are stored on the schema.
	 */
	public function __construct(
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * The kinds of party a case accepts.
	 *
	 * @return array<int, array<string, mixed>> The `partyKinds` entries.
	 *
	 * @spec openspec/changes/gemachtigde-role-on-every-case-type/specs/roles-decisions/spec.md#requirement-the-case-declares-the-kinds-of-party-it-takes-req-role-011
	 */
	public function kinds(): array {
		return [
			[
				'key' => 'person',
				'label' => $this->l10n->t('Person'),
				'description' => $this->l10n->t('A natural person, with or without an account on this instance.'),
			],
			[
				'key' => 'organisation',
				'label' => $this->l10n->t('Organisation'),
				'description' => $this->l10n->t('A company, authority or other body acting on the case.'),
			],
			[
				'key' => 'address',
				'label' => $this->l10n->t('Address'),
				'description' => $this->l10n->t('A place the case is about, such as the address a permit is asked for.'),
				'roles' => [self::ROLE_LOCATION],
			],
		];
	}//end kinds()

	/**
	 * The roles a party may hold on a case, whatever its case type.
	 *
	 * These sit beside the instance's own role types rather than instead of
	 * them: a role type is what an organisation calls a seat on its process,
	 * and these six are what the law calls a party.
	 *
	 * @return array<int, array<string, string>> The `linkRoles` entries.
	 *
	 * @spec openspec/changes/gemachtigde-role-on-every-case-type/specs/roles-decisions/spec.md#requirement-every-case-type-offers-the-generic-party-roles-req-role-012
	 */
	public function roles(): array {
		return [
			[
				'key' => self::ROLE_REQUESTER,
				'label' => $this->l10n->t('Requester'),
				'description' => $this->l10n->t('The party the case is filed for.'),
			],
			[
				'key' => self::ROLE_REPRESENTATIVE,
				'label' => $this->l10n->t('Authorised representative'),
				'description' => $this->l10n->t('Acts for another party on this case, under Awb 2:1.'),
			],
			[
				'key' => 'belanghebbende',
				'label' => $this->l10n->t('Interested party'),
				'description' => $this->l10n->t('Whose interest is directly affected by the decision.'),
			],
			[
				'key' => 'afzender',
				'label' => $this->l10n->t('Sender'),
				'description' => $this->l10n->t('Sent a document that is on this case.'),
			],
			[
				'key' => 'geadresseerde',
				'label' => $this->l10n->t('Addressee'),
				'description' => $this->l10n->t('A document on this case was addressed to them.'),
			],
			[
				'key' => self::ROLE_LOCATION,
				'label' => $this->l10n->t('Location'),
				'description' => $this->l10n->t('The place this case is about.'),
			],
		];
	}//end roles()
}//end class
