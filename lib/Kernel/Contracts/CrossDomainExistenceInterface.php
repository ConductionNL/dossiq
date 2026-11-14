<?php

/**
 * Contract of CrossDomainExistence
 *
 * The public surface of CrossDomainExistence that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Dossiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Kernel\Contracts;

use OCA\Dossiq\Exception\RefusedException;

/**
 * Contract of CrossDomainExistence.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CrossDomainExistenceInterface {

	/**
	 * The grounds a lookup may be made on.
	 *
	 * A CLOSED list, because an open text field is a field that fills up with
	 * "onderzoek" and answers nobody's question afterwards. Each of these is a
	 * ground somebody can be held to.
	 *
	 * @var array<string, string>
	 */
	public const GROUNDS = [
		'wettelijke-taak' => 'Carrying out a statutory task in this domain',
		'toestemming' => 'The person has consented to this lookup',
		'vitaal-belang' => 'A vital interest of the person or of another',
		'samenwerkingsafspraak' => 'A recorded cooperation agreement between the domains',
	];

	/**
	 * Whether an open case exists in another domain, and who to contact.
	 *
	 * @param string $bsn       The person looked up.
	 * @param string $ownDomain The domain the caller works in, which is excluded.
	 * @param string $ground    The chosen ground, from {@see GROUNDS}.
	 * @param string $requester The uid of whoever is asking.
	 *
	 * @return array<int, array{exists: bool, domain: string, contact: string}> One entry per domain that holds one.
	 *
	 * @throws RefusedException When no ground is chosen, or the store cannot be read.
	 *
	 * @spec openspec/specs/dossiq-sociaal-domein-avg-consent/spec.md#requirement-another-domain-answers-only-that-a-case-exists-req-xdv-01
	 */
	public function lookUp(string $bsn, string $ownDomain, string $ground, string $requester): array;

	/**
	 * What was looked up about one person, with the grounds and the dates.
	 *
	 * @param string $bsn The person.
	 *
	 * @return array<int, array<string, mixed>> The lookups, newest first.
	 *
	 * @throws RefusedException When the log cannot be read.
	 *
	 * @spec openspec/specs/dossiq-sociaal-domein-avg-consent/spec.md#requirement-the-lookup-requires-a-ground-chosen-first-and-logged-req-xdv-02
	 */
	public function lookupsAbout(string $bsn): array;
}//end interface
