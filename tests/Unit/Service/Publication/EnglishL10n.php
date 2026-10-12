<?php

/**
 * An IL10N double that formats the English, for the unit tests.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Publication;

use OCP\IL10N;

trait EnglishL10n {

	/**
	 * A translator that answers the English with its parameters filled in.
	 *
	 * @return IL10N
	 */
	private function english(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));

		return $l10n;
	}//end english()
}//end trait
