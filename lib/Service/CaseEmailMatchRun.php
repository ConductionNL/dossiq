<?php

/**
 * The state of one email case matching run over one user's mailbox.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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
 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCP\IUser;

/**
 * The mailbox, the resolved OpenRegister services and the progress of one run.
 *
 * {@see CaseEmailMatchService} builds it once the run is allowed to go ahead
 * and hands it to its helpers; it never leaves that class.
 *
 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
 */
final class CaseEmailMatchRun {

	/**
	 * OpenRegister's object service, which offers runAs().
	 *
	 * @var object
	 */
	public object $objectService;

	/**
	 * OpenRegister's email leaf, which offers linkEmail().
	 *
	 * @var object
	 */
	public object $linkService;

	/**
	 * The case number pattern.
	 *
	 * @var string
	 */
	public string $pattern = '';

	/**
	 * The configured register.
	 *
	 * @var string
	 */
	public string $register = '';

	/**
	 * The configured case schema.
	 *
	 * @var string
	 */
	public string $schema = '';

	/**
	 * The cursor the batch starts after.
	 *
	 * @var integer
	 */
	public int $cursor = 0;

	/**
	 * Links the batch has made so far.
	 *
	 * @var integer
	 */
	public int $linked = 0;

	/**
	 * The message being matched.
	 *
	 * @var array{id: int, uid: string, subject: string, preview: string}
	 */
	public array $message = ['id' => 0, 'uid' => '', 'subject' => '', 'preview' => ''];

	/**
	 * Constructor.
	 *
	 * @param IUser $owner     The mailbox owner.
	 * @param int   $accountId Their configured Mail account.
	 */
	public function __construct(
		public readonly IUser $owner,
		public readonly int $accountId,
	) {
	}//end __construct()
}//end class
