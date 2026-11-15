<?php

/**
 * SendsHalHeaders — the HAL request headers of an integration.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Support;

use OCA\Dossiq\Kernel\Contracts\IntegrationModeInterface;
use OCA\Dossiq\Service\Support\SendsHalHeaders;
use PHPUnit\Framework\TestCase;

/**
 * With and without a configured API key.
 *
 * @covers \OCA\Dossiq\Service\Support\SendsHalHeaders
 */
final class SendsHalHeadersTest extends TestCase {

	/**
	 * The headers for an integration whose key is as given.
	 *
	 * @param string $apiKey The configured key.
	 *
	 * @return array<string, string>
	 */
	private function headersWith(string $apiKey): array {
		$mode = $this->createMock(IntegrationModeInterface::class);
		$mode->method('setting')->with('woz', 'apiKey')->willReturn($apiKey);
		$subject = new class($mode) {
			use SendsHalHeaders {
				halHeaders as public;
			}

			/**
			 * Constructor.
			 *
			 * @param IntegrationModeInterface $mode The integration mode.
			 */
			public function __construct(private readonly IntegrationModeInterface $mode) {
			}
		};

		return $subject->halHeaders('woz');
	}//end headersWith()

	/**
	 * The key is sent only when configured.
	 *
	 * @return void
	 */
	public function testHeaders(): void {
		self::assertSame(['Accept' => 'application/hal+json', 'X-Api-Key' => 'k1'], $this->headersWith('k1'));
		self::assertSame(['Accept' => 'application/hal+json'], $this->headersWith(''));
	}//end testHeaders()
}//end class
