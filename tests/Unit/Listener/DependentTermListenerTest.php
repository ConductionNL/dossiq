<?php

/**
 * Dossiq DependentTermListener test.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\DependentTermListener;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Term\DependentTermOffer;
use OCA\Dossiq\Service\TermijnService;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Which term events make an offer, and which make none.
 *
 * @spec openspec/changes/dependent-term-follows-predecessor/specs/related-case-linking/spec.md
 */
class DependentTermListenerTest extends TestCase {
	/**
	 * The offers asked for, in order.
	 *
	 * @var array<int, array{case: string, days: int}>
	 */
	private array $offered = [];

	/**
	 * A verlenging is offered on, and names the case and the days.
	 *
	 * @return void
	 */
	public function testAnExtensionIsOfferedToTheWaitingCases(): void {
		$this->listener()->handle($this->created(type: 'verleng', days: 14));

		$this->assertSame([['case' => 'case-a', 'days' => 14]], $this->offered);
	}//end testAnExtensionIsOfferedToTheWaitingCases()

	/**
	 * A pause moves the date too, so it is offered on as well.
	 *
	 * @return void
	 */
	public function testAPauseIsOfferedToo(): void {
		$this->listener()->handle($this->created(type: 'pauze', days: 7));

		$this->assertSame([['case' => 'case-a', 'days' => 7]], $this->offered);
	}//end testAPauseIsOfferedToo()

	/**
	 * A start moves nothing, so nobody is bothered about it.
	 *
	 * @return void
	 */
	public function testAStartIsNotAMove(): void {
		$this->listener()->handle($this->created(type: 'start', days: 42));

		$this->assertSame([], $this->offered);
	}//end testAStartIsNotAMove()

	/**
	 * A verlenging of nought days moves nothing either.
	 *
	 * @return void
	 */
	public function testAnExtensionOfNoDaysIsNotAMove(): void {
		$this->listener()->handle($this->created(type: 'verleng', days: 0));

		$this->assertSame([], $this->offered);
	}//end testAnExtensionOfNoDaysIsNotAMove()

	/**
	 * A create on another schema is somebody else's event.
	 *
	 * @return void
	 */
	public function testAnotherSchemasCreateIsIgnored(): void {
		$this->listener()->handle(
			new FakeCreatedEvent(
				[
					'@self' => ['schema' => 'role'],
					'type' => 'verleng',
					'daysImpact' => 14,
					'deadlineInstance' => 'term-1',
				]
			)
		);

		$this->assertSame([], $this->offered);
	}//end testAnotherSchemasCreateIsIgnored()

	/**
	 * One created term event.
	 *
	 * @param string $type The event type.
	 * @param int    $days How far it moved the date.
	 *
	 * @return Event The event.
	 */
	private function created(string $type, int $days): Event {
		return new FakeCreatedEvent(
			[
				'@self' => ['schema' => 'termijnGebeurtenis'],
				'type' => $type,
				'daysImpact' => $days,
				'deadlineInstance' => 'term-1',
			]
		);
	}//end created()

	/**
	 * The listener over the doubles.
	 *
	 * @return DependentTermListener The listener under test.
	 */
	private function listener(): DependentTermListener {
		$config = [
			'register' => '1',
			'case_schema' => '2',
			'termijn_gebeurtenis_schema' => 'termijnGebeurtenis',
		];

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => (string)($config[$key] ?? $default)
		);
		$settings->method('getObjectService')->willReturn(null);

		$terms = $this->createMock(TermijnService::class);
		$terms->method('getTermijnInstance')->willReturn(['id' => 'term-1', 'case' => 'case-a']);

		$offers = $this->createMock(DependentTermOffer::class);
		$offers->method('offer')->willReturnCallback(
			function (string $sourceCaseId, string $sourceTitle, int $daysImpact): int {
				$this->offered[] = ['case' => $sourceCaseId, 'days' => $daysImpact];
				return 1;
			}
		);

		return new DependentTermListener(
			settingsService: $settings,
			terms: $terms,
			offers: $offers,
			logger: new NullLogger()
		);
	}//end listener()
}//end class

/**
 * The shape of OpenRegister's `ObjectCreatedEvent`, as this listener reads it.
 */
final class FakeCreatedEvent extends Event {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $object The created object.
	 */
	public function __construct(private readonly array $object) {
		parent::__construct();
	}//end __construct()

	/**
	 * The created object.
	 *
	 * @return array<string, mixed> The object.
	 */
	public function getObject(): array {
		return $this->object;
	}//end getObject()
}//end class
