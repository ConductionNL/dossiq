<?php

/**
 * A channel message's words for its channel become a slug on the case.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Intake
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Intake;

use OCA\Dossiq\Service\Email\IntakeLog;
use OCA\Dossiq\Service\Intake\ChannelIntake;
use OCA\Dossiq\Service\Intake\MessageFacts;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * @spec openspec/changes/portal-contact-channel-follows-the-resident/specs/portal-contribution/spec.md
 */
class ChannelIntakeCommunicationChannelTest extends TestCase {

	/**
	 * The case object a message with this channel value opens.
	 *
	 * @param string $channel The value the channel parsed.
	 *
	 * @return array<string, mixed>
	 */
	private function caseFor(string $channel): array {
		$facts = $this->createMock(MessageFacts::class);
		$facts->method('titleFor')->willReturn('Bericht');
		$facts->method('receivedDate')->willReturn('2026-10-10');
		$facts->method('correspondent')->willReturn('');
		$intake = new ChannelIntake(
			settingsService: $this->createMock(SettingsService::class),
			log: $this->createMock(IntakeLog::class),
			logger: new NullLogger(),
			facts: $facts
		);

		$method = new ReflectionMethod(ChannelIntake::class, 'caseObjectFor');
		return $method->invoke($intake, 'type-1', ['channelId' => 'c1'], ['communicationChannel' => $channel]);
	}//end caseFor()

	/**
	 * "E-mail" becomes `email` and the words are kept; a word that names no
	 * channel writes no channel, so the slug enum cannot refuse the case.
	 *
	 * @return void
	 */
	public function testTheChannelWordsBecomeASlugOrNothing(): void {
		$case = $this->caseFor(channel: 'E-mail');
		$this->assertSame('email', $case['communicationChannel']);
		$this->assertSame('E-mail', $case['communicationChannelSource']);

		$this->assertSame('portal', $this->caseFor(channel: 'portal')['communicationChannel']);
		$this->assertArrayNotHasKey('communicationChannelSource', $this->caseFor(channel: 'portal'));

		$unknown = $this->caseFor(channel: 'telefoon');
		$this->assertArrayNotHasKey('communicationChannel', $unknown);
		$this->assertSame('telefoon', $unknown['communicationChannelSource']);
	}//end testTheChannelWordsBecomeASlugOrNothing()
}//end class
