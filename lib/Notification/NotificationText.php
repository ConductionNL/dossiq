<?php

/**
 * Dossiq notification text.
 *
 * What one notification's wording is rendered from: its subject key, its
 * stored subject parameters and the recipient's language.
 *
 * @category Notification
 * @package  OCA\Dossiq\Notification
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
 * @spec openspec/specs/ncvue-w2-leaves-adoption/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Notification;

use OCP\IL10N;

/**
 * One notification being worded.
 *
 * Built by {@see Notifier::prepare()} only; it never leaves the notifier.
 *
 * @spec openspec/specs/ncvue-w2-leaves-adoption/spec.md
 */
final class NotificationText {

	/**
	 * The subject key being rendered.
	 *
	 * @var string
	 */
	public string $subjectKey = '';

	/**
	 * The stored subject parameters.
	 *
	 * @var array<string, mixed>
	 */
	public array $parameters = [];

	/**
	 * The recipient-language localisation.
	 *
	 * @var IL10N
	 */
	public IL10N $l;
}//end class
