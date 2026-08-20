<?php

/**
 * AiModelIdentity Unit Tests
 *
 * Guards the `<type>/<name>` identifier stamped onto every oversight audit
 * entry and reported by the AI health check — including the `local`/`unknown`
 * fallbacks an unconfigured instance relies on.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service\Ai
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ai-oversight-log/tasks.md#1.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service\Ai;

use OCA\Procest\Service\Ai\AiModelIdentity;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AiModelIdentity.
 *
 * @covers \OCA\Procest\Service\Ai\AiModelIdentity
 */
class AiModelIdentityTest extends TestCase {

	/**
	 * The configured model type and name are joined with a slash.
	 *
	 * @return void
	 */
	public function testIdentifierJoinsConfiguredTypeAndName(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')
			->willReturnCallback(
				static fn (string $app, string $key, string $default): string => match ($key) {
					'ai_model_type' => 'openai',
					'ai_model_name' => 'gpt-4o',
					default => $default,
				}
			);

		$this->assertSame('openai/gpt-4o', (new AiModelIdentity($appConfig))->identifier());
	}//end testIdentifierJoinsConfiguredTypeAndName()

	/**
	 * An unconfigured instance falls back to `local/unknown` rather than
	 * writing an empty `model` field into the audit trail.
	 *
	 * @return void
	 */
	public function testIdentifierFallsBackToLocalUnknown(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')
			->willReturnCallback(
				static fn (string $app, string $key, string $default): string => $default
			);

		$this->assertSame('local/unknown', (new AiModelIdentity($appConfig))->identifier());
	}//end testIdentifierFallsBackToLocalUnknown()
}//end class
