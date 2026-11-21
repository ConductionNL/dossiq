<?php

/**
 * The refusals of an acknowledgement of receipt.
 *
 * A confirmation recorded by hand that does not say how receipt was confirmed,
 * and an acknowledgement no transport took: the refusal a caller gets, built
 * without a store.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Notification
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
 * @spec openspec/specs/burger-notifications/spec.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Notification;

use OCA\Dossiq\Exception\NoticeNotSentException;
use OCA\Dossiq\Exception\RefusedException;

/**
 * Builds the refusals an acknowledgement of receipt answers with.
 *
 * @spec openspec/specs/burger-notifications/spec.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class AcknowledgementRefusals {

	/**
	 * How receipt was confirmed, trimmed, or a refusal when it is not said.
	 *
	 * @param string $how How receipt was confirmed.
	 *
	 * @return string The trimmed answer.
	 *
	 * @throws RefusedException When no answer was given.
	 *
	 * @spec openspec/specs/burger-notifications/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function howStated(string $how): string {
		$how = trim($how);
		if ($how === '') {
			throw new RefusedException(
				rule: 'acknowledgement-needs-a-reason',
				sentence: 'Say how receipt was confirmed, so the case records more than a tick.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		return $how;
	}//end howStated()

	/**
	 * The refusal for an acknowledgement no transport took.
	 *
	 * @param NoticeNotSentException $notice What the sender answered.
	 *
	 * @return RefusedException The refusal, carrying the transport's sentence.
	 *
	 * @spec openspec/specs/burger-notifications/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function notSent(NoticeNotSentException $notice): RefusedException {
		if ($notice->getReasonCode() === 'no-channel') {
			return new RefusedException(
				rule: 'acknowledgement-no-address',
				sentence: 'This case carries no address, so receipt could not be confirmed. '
					. 'Add the applicant\'s address, or record that you confirmed it another way.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		return new RefusedException(
			rule: 'acknowledgement-not-sent',
			sentence: $notice->getMessage(),
			status: RefusedException::STATUS_INDETERMINATE,
		);
	}//end notSent()
}//end class
