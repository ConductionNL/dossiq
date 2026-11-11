<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Routing;

use OCA\Dossiq\Service\Routing\RoleDelegationResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Who a participant's work goes to: the delegate inside an active window, else the participant.
 */
class RoleDelegationGoldenTest extends TestCase {

	/**
	 * The warnings logged, as message and context.
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $warnings = [];

	/**
	 * Apply delegation over the roles.
	 *
	 * @param array<int, string>               $participants The participants.
	 * @param array<int, array<string, mixed>> $roles        The case roles.
	 *
	 * @return array<int, string> The result.
	 */
	private function apply(array $participants, array $roles): array {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context): void {
				$this->warnings[] = [$message, $context];
			}
		);

		return (new RoleDelegationResolver(logger: $logger))->apply(participants: $participants, roles: $roles);
	}//end apply()

	/**
	 * A delegation role.
	 *
	 * @param string $participant Who delegates.
	 * @param string $delegate    To whom.
	 * @param string $from        Window start.
	 * @param string $until       Window end.
	 *
	 * @return array<string, string> The role.
	 */
	private static function role(string $participant, string $delegate, string $from = '2000-01-01', string $until = '2999-01-01'): array {
		return ['participant' => $participant, 'delegate' => $delegate, 'delegateFrom' => $from, 'delegateUntil' => $until];
	}//end role()

	/**
	 * Allowed: an active window hands the work to the delegate, one hop only, in participant order.
	 *
	 * @return void
	 */
	public function testAnActiveWindowDelegatesOneHop(): void {
		self::assertSame(
			['bert', 'carla', 'erik', 'bert'],
			$this->apply(['anna', 'bert', 'erik', 'anna'], [self::role('anna', 'bert'), self::role('bert', 'carla')])
		);
		self::assertSame([], $this->warnings);
	}//end testAnActiveWindowDelegatesOneHop()

	/**
	 * Refused: outside the window, an incomplete or unreadable window, or no role keeps the participant.
	 *
	 * @return void
	 */
	public function testNoActiveWindowKeepsTheParticipant(): void {
		$roles = [
			self::role('a', 'x', '2998-01-01', '2999-01-01'),
			self::role('b', 'x', '2000-01-01', '2001-01-01'),
			self::role('c', ''),
			self::role('d', 'x', ''),
			self::role('e', 'x', '2000-01-01', ''),
			self::role('f', 'x', 'not a date'),
			self::role('g', 'x', '2000-01-01', 'not a date'),
			['participant' => '', 'delegate' => 'x', 'delegateFrom' => '2000-01-01', 'delegateUntil' => '2999-01-01'],
			['delegate' => 'x', 'delegateFrom' => '2000-01-01', 'delegateUntil' => '2999-01-01'],
		];

		self::assertSame(['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', ''], $this->apply(['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', ''], $roles));
		self::assertSame([], $this->warnings);
	}//end testNoActiveWindowKeepsTheParticipant()

	/**
	 * The last role of a participant counts.
	 *
	 * @return void
	 */
	public function testTheLastRoleCounts(): void {
		self::assertSame(['carla'], $this->apply(['anna'], [self::role('anna', 'bert'), self::role('anna', 'carla')]));
		self::assertSame(['anna'], $this->apply(['anna'], [self::role('anna', 'bert'), self::role('anna', '')]));
	}//end testTheLastRoleCounts()

	/**
	 * Delegating to oneself is a cycle: logged, and the participant keeps the work.
	 *
	 * @return void
	 */
	public function testASelfDelegationIsACycle(): void {
		self::assertSame(['anna', '7'], $this->apply(['anna', '7'], [self::role('anna', 'anna'), self::role('7', '7')]));
		self::assertSame(
			[
				['Dossiq: delegation cycle detected', ['event' => 'RoleRoutingDelegationCycle', 'original' => 'anna', 'delegate' => 'anna', 'app' => 'dossiq']],
				['Dossiq: delegation cycle detected', ['event' => 'RoleRoutingDelegationCycle', 'original' => '7', 'delegate' => '7', 'app' => 'dossiq']],
			],
			$this->warnings
		);
	}//end testASelfDelegationIsACycle()
}//end class
