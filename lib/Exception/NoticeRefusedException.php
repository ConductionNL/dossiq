<?php

/**
 * Dossiq NoticeRefusedException.
 *
 * A refusal that already carries its answer: `{ message, error, notice }` and
 * the HTTP status, so the controller returns it as it is.
 *
 * @category Exception
 * @package  OCA\Dossiq\Exception
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
 * @spec openspec/changes/case-page-answers-in-card-notices/specs/case-management/spec.md#requirement-an-action-on-the-case-page-answers-with-a-card-notice
 */

declare(strict_types=1);

namespace OCA\Dossiq\Exception;

use RuntimeException;

/**
 * A refusal with its notice envelope.
 *
 * @spec openspec/changes/case-page-answers-in-card-notices/specs/case-management/spec.md#requirement-an-action-on-the-case-page-answers-with-a-card-notice
 */
class NoticeRefusedException extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string                                                          $error   Machine-readable code.
	 * @param array{variant: string, role: string, title: string, text: string} $notice The card notice.
	 * @param int                                                             $status  The HTTP status.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly string $error,
		private readonly array $notice,
		private readonly int $status = 409,
	) {
		parent::__construct(message: trim($notice['title'] . ' ' . $notice['text']));
	}//end __construct()

	/**
	 * The response body: `{ message, error, notice }`.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/case-page-answers-in-card-notices/specs/case-management/spec.md#requirement-an-action-on-the-case-page-answers-with-a-card-notice
	 */
	public function body(): array {
		return ['message' => $this->getMessage(), 'error' => $this->error, 'notice' => $this->notice];
	}//end body()

	/**
	 * The HTTP status.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/case-page-answers-in-card-notices/specs/case-management/spec.md#requirement-an-action-on-the-case-page-answers-with-a-card-notice
	 */
	public function status(): int {
		return $this->status;
	}//end status()
}//end class
