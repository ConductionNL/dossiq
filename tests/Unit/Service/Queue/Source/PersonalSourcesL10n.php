<?php

/**
 * A translator double for the queue source tests: it answers the English
 * source with its placeholders filled, as Nextcloud does without a catalogue.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Queue\Source;

use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;

trait PersonalSourcesL10n {

	private function l10n(): IL10N&MockObject {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		return $l10n;
	}//end l10n()
}//end trait
