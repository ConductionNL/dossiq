<?php

/**
 * OpenRegister's email leaf as case matching calls it for one Mail account.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Email
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
 * @spec openspec/specs/email-case-matching/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Email;

use OCA\OpenRegister\Service\EmailMessageRef;
use OCA\OpenRegister\Service\LinkTarget;

/**
 * Links Mail messages of one account to cases through OpenRegister's EmailLinkService.
 *
 * @spec openspec/specs/email-case-matching/spec.md
 */
final class CaseMailLeaf {
	/**
	 * Constructor.
	 *
	 * @param object $linkService OpenRegister's EmailLinkService.
	 * @param int    $accountId   The Mail account.
	 */
	public function __construct(
		private readonly object $linkService,
		private readonly int $accountId,
	) {
	}//end __construct()

	/**
	 * Link the message to the case.
	 *
	 * @param array{uuid: string, identifier: string, registerId: int, schemaId: int} $case    The case.
	 * @param array{id: int, uid: string, subject: string, preview: string}          $message The message.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/email-case-matching/spec.md
	 */
	public function link(array $case, array $message): void {
		$this->linkService->linkEmail(
			target: (new LinkTarget(objectUuid: $case['uuid'], registerId: $case['registerId']))
				->inSchema(schemaId: $case['schemaId']),
			message: (new EmailMessageRef(mailAccountId: $this->accountId, messageId: (string)$message['id']))
				->withUid(uid: $message['uid'])
		);
	}//end link()
}//end class
