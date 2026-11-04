<?php

/**
 * Dossiq AI request
 *
 * One AI operation as asked: which operation, what it is about, who asked and
 * how its prompt is built.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Ai
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
 * @spec openspec/specs/ai-assistance/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Ai;

use Closure;

/**
 * One AI operation as asked.
 *
 * @spec openspec/specs/ai-assistance/spec.md
 */
final class AiRequest {
	/**
	 * Constructor.
	 *
	 * @param string               $type    The operation, which is also its feature flag and audit type.
	 * @param array<string, mixed> $subject The case (and document) it is about, in audit-entry order.
	 * @param string               $userId  The user who asked.
	 * @param Closure              $prompt  Builds the unscrubbed prompt; called only once the feature is allowed.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/ai-assistance/spec.md
	 */
	public function __construct(
		public readonly string $type,
		public readonly array $subject,
		public readonly string $userId,
		public readonly Closure $prompt,
	) {
	}//end __construct()

	/**
	 * The audit-entry fields every record of this request starts with.
	 *
	 * @param string $model  The model identifier.
	 * @param string $prompt The prompt as sent.
	 *
	 * @return array<string, mixed> type, subject, model and prompt, in that order.
	 *
	 * @spec openspec/specs/ai-assistance/spec.md
	 */
	public function head(string $model, string $prompt): array {
		return ['type' => $this->type] + $this->subject + ['model' => $model, 'prompt' => $prompt];
	}//end head()
}//end class
