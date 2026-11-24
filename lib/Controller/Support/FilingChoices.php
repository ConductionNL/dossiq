<?php

/**
 * Dossiq filing choices.
 *
 * The two choices of the Link to case dialog, run on a message a handler
 * filed by hand: the sender put on the case as a party, and the case's
 * handler told.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller\Support
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
 * @spec openspec/specs/case-email-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller\Support;

use OCA\Dossiq\Controller\ManualFiling;
use OCA\Dossiq\Kernel\Contracts\FilingFollowUpsInterface;
use OCA\Dossiq\Service\Intake\MessagePreview;

/**
 * Runs the dialog's choices after a filing by hand; they never undo it.
 *
 * @spec openspec/specs/case-email-integration/spec.md
 */
final class FilingChoices {

	/**
	 * Constructor.
	 *
	 * @param FilingFollowUpsInterface $followUps The sender as a party, the handler told.
	 */
	public function __construct(
		private readonly FilingFollowUpsInterface $followUps,
	) {
	}//end __construct()

	/**
	 * Run the dialog's choices on a filed message: the sender as a party, the handler told.
	 *
	 * @param ManualFiling                                $filing   The message filed by hand.
	 * @param array{addSender: bool, notifyHandler: bool} $choices  What the caller chose.
	 * @param string                                      $callerId The handler who filed it.
	 *
	 * @return array<string, string> `party` and `handler`, each what happened, when chosen.
	 *
	 * @spec openspec/specs/case-email-integration/spec.md
	 */
	public function run(ManualFiling $filing, array $choices, string $callerId): array {
		$sender = (string)($filing->entry['sender'] ?? '');
		$name = (new MessagePreview())->read(original: (string)($filing->entry['original'] ?? ''))['senderName'];
		$done = [];
		if ($choices['addSender'] === true) {
			$done['party'] = $this->followUps->addSenderAsParty(caseId: $filing->caseId, address: $sender, name: $name);
		}

		if ($choices['notifyHandler'] === true) {
			$label = $name;
			if ($label === '') {
				$label = $sender;
			}

			$done['handler'] = $this->followUps->notifyHandler(
				caseId: $filing->caseId,
				callerId: $callerId,
				sender: $label,
				subject: (string)($filing->entry['subject'] ?? '')
			);
		}

		return $done;
	}//end run()
}//end class
