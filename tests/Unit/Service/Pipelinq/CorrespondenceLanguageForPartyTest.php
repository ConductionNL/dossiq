<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Pipelinq;

use OCA\Dossiq\Service\Pipelinq\CorrespondenceLanguageConsumer;
use OCA\Dossiq\Service\Pipelinq\PipelinqGateway;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Every answer `forParty` gives: no resolver, an unreadable party, and each rule.
 */
class CorrespondenceLanguageForPartyTest extends TestCase {

	/**
	 * A consumer whose resolver answers with `$answer`, or none when null.
	 *
	 * @param array<string, mixed>|null $answer The resolver's answer.
	 *
	 * @return CorrespondenceLanguageConsumer The consumer.
	 */
	private function consumer(?array $answer): CorrespondenceLanguageConsumer {
		$resolver = new class ($answer) {
			/**
			 * @param array<string, mixed>|null $answer The answer.
			 */
			public function __construct(private ?array $answer) {
			}

			/**
			 * @param string $partyId The party.
			 *
			 * @return mixed The answer.
			 */
			public function resolve(string $partyId): mixed {
				return $this->answer ?? 'not an array';
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($answer, $resolver): object {
				if ($answer === null && $id === PipelinqGateway::CORRESPONDENCE_LANGUAGE) {
					throw new RuntimeException('absent');
				}

				return $resolver;
			}
		);

		return new CorrespondenceLanguageConsumer(new PipelinqGateway($container, $this->createMock(LoggerInterface::class)));
	}//end consumer()

	/**
	 * Without a resolver the fallback answers and says why.
	 *
	 * @return void
	 */
	public function testNoResolverFallsBack(): void {
		$answer = $this->consumer(null)->forParty(partyId: ' party-1 ');

		$this->assertSame(
			[
				'available' => false,
				'language' => 'nl',
				'rule' => 'unavailable',
				'stated' => false,
				'sentence' => 'No preference can be read on this instance, so nl is used.',
			],
			$answer
		);
	}//end testNoResolverFallsBack()

	/**
	 * A refused read of the party falls back with its own sentence.
	 *
	 * @return void
	 */
	public function testAnUnreadablePartyFallsBack(): void {
		$answer = $this->consumer(['status' => 403, 'language' => 'en'])->forParty(partyId: 'party-1');

		$this->assertSame(
			[
				'available' => false,
				'language' => 'nl',
				'rule' => 'unavailable',
				'stated' => false,
				'sentence' => 'This party\'s language could not be read.',
			],
			$answer
		);
	}//end testAnUnreadablePartyFallsBack()

	/**
	 * A missing status counts as read, and the fields are trimmed.
	 *
	 * @return void
	 */
	public function testAnAnswerWithoutStatusIsRead(): void {
		$answer = $this->consumer(['language' => ' de ', 'rule' => ' fallback ', 'partyPreference' => ' '])
			->forParty(partyId: 'party-1');

		$this->assertSame(
			[
				'available' => true,
				'language' => 'de',
				'rule' => 'fallback',
				'stated' => false,
				'sentence' => 'No preference is recorded and this instance sets no default, so de is used.',
			],
			$answer
		);
	}//end testAnAnswerWithoutStatusIsRead()

	/**
	 * An answer without a language uses the fallback tag and the plain sentence.
	 *
	 * @return void
	 */
	public function testAnAnswerWithoutLanguageUsesTheFallbackTag(): void {
		$answer = $this->consumer(['status' => 200])->forParty(partyId: 'party-1');

		$this->assertSame('nl', $answer['language']);
		$this->assertSame('', $answer['rule']);
		$this->assertSame('No preference is recorded, so nl is used.', $answer['sentence']);
	}//end testAnAnswerWithoutLanguageUsesTheFallbackTag()
}//end class
