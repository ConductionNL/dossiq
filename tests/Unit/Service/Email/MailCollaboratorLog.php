<?php

/**
 * A logger that keeps what it was told, for the Mail gateway's collaborator tests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Email
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Email;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Records each log call as [level, message].
 */
final class MailCollaboratorLog extends AbstractLogger {

	/**
	 * The records.
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	public array $records = [];

	/**
	 * Keep one record.
	 *
	 * @param mixed              $level   The level.
	 * @param string|Stringable  $message The message.
	 * @param array<mixed>       $context Ignored.
	 *
	 * @return void
	 */
	public function log($level, string|Stringable $message, array $context = []): void {
		$this->records[] = [(string)$level, (string)$message];
	}//end log()
}//end class
