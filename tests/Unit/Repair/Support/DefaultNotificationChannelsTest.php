<?php

/**
 * Unit tests for DefaultNotificationChannels: the five channels, the mapping
 * readiness with its two skip lines, and the report with and without refusals.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair\Support;

use OCA\Dossiq\Repair\Support\DefaultNotificationChannels;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Repair\Support\DefaultNotificationChannels
 *
 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
 */
class DefaultNotificationChannelsTest extends TestCase {

	/**
	 * What the output was told, as "level: message".
	 *
	 * @var list<string>
	 */
	private array $said = [];

	/**
	 * The five ZGW channels, in order.
	 *
	 * @return void
	 */
	public function testTheDefaultsAreTheFiveZgwChannels(): void {
		$this->assertSame(
			expected: ['zaken', 'documenten', 'besluiten', 'catalogi', 'autorisaties'],
			actual: array_column(array: (new DefaultNotificationChannels())->defaults(), column_key: 'name')
		);
	}//end testTheDefaultsAreTheFiveZgwChannels()

	/**
	 * A missing mapping and a mapping without register or schema are not ready, each with its own line.
	 *
	 * @return void
	 */
	public function testTheMappingIsReadyOnlyWithARegisterAndSchema(): void {
		$channels = new DefaultNotificationChannels();
		$output = $this->recordingOutput();

		$this->assertFalse(condition: $channels->mappingReady(output: $output, channelMapping: null));
		$this->assertFalse(
			condition: $channels->mappingReady(output: $output, channelMapping: ['sourceRegister' => 'reg-1', 'sourceSchema' => ''])
		);
		$this->assertFalse(condition: $channels->mappingReady(output: $output, channelMapping: ['sourceSchema' => 'channel-s']));
		$this->assertTrue(
			condition: $channels->mappingReady(output: $output, channelMapping: ['sourceRegister' => 'reg-1', 'sourceSchema' => 'channel-s'])
		);

		$this->assertSame(
			expected: [
				'info: Kanaal mapping not configured. Skipping default channels.',
				'info: Kanaal mapping has no register/schema configured yet. Skipping default channels.',
				'info: Kanaal mapping has no register/schema configured yet. Skipping default channels.',
			],
			actual: $this->said
		);
	}//end testTheMappingIsReadyOnlyWithARegisterAndSchema()

	/**
	 * A clean run is an info line; a refusal is a warning that names the refused channels.
	 *
	 * @return void
	 */
	public function testTheReportWarnsWhenAChannelWasRefused(): void {
		$channels = new DefaultNotificationChannels();
		$output = $this->recordingOutput();

		$channels->report(output: $output, result: ['created' => 5, 'refused' => []]);
		$channels->report(output: $output, result: ['created' => 3, 'refused' => ['zaken', 'besluiten']]);

		$this->assertSame(
			expected: [
				'info: Created 5 default notification channels.',
				'warning: Created 3 default notification channels; 2 REFUSED (zaken, besluiten). ZGW notifications on those channels cannot be delivered.',
			],
			actual: $this->said
		);
	}//end testTheReportWarnsWhenAChannelWasRefused()

	/**
	 * An output that records what it was told.
	 *
	 * @return IOutput
	 */
	private function recordingOutput(): IOutput {
		$output = $this->createMock(originalClassName: IOutput::class);
		foreach (['info', 'warning'] as $level) {
			$output->method($level)->willReturnCallback(
				function (string $message) use ($level): void {
					$this->said[] = $level . ': ' . $message;
				}
			);
		}

		return $output;
	}//end recordingOutput()
}//end class
