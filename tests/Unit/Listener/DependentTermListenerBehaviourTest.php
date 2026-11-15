<?php

/**
 * Behaviour tests for DependentTermListener: how the moved term's case and
 * title are found, and what happens when they cannot be.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\DependentTermListener;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Term\DependentTermOffer;
use OCA\Dossiq\Service\TermijnService;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The case and title behind a moved term.
 */
class DependentTermListenerBehaviourTest extends TestCase {

	/**
	 * Offers made: [case, title, days].
	 *
	 * @var list<array{0: string, 1: string, 2: int}>
	 */
	private array $offered = [];

	/**
	 * What getTermijnInstance() answers.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $instance = ['id' => 'term-1', 'case' => 'case-a'];

	/**
	 * The object service the settings hand out, or null.
	 *
	 * @var object|null
	 */
	private ?object $objectService = null;

	/**
	 * Config values by key.
	 *
	 * @var array<string, string>
	 */
	private array $config = [
		'register' => '1',
		'case_schema' => '2',
		'termijn_gebeurtenis_schema' => 'termijnGebeurtenis',
	];

	/**
	 * A term-event creation.
	 *
	 * @param array<string, mixed> $over Overrides
	 *
	 * @return Event
	 */
	private function moved(array $over = []): Event {
		return new MovedTermEvent(
			array_merge(
				[
					'@self' => ['schema' => 'termijnGebeurtenis'],
					'type' => 'verleng',
					'daysImpact' => 10,
					'deadlineInstance' => 'term-1',
				],
				$over
			)
		);

	}//end moved()

	/**
	 * A case store answering one case, or throwing.
	 *
	 * @param array<string, mixed>|\Throwable $case The case, or what find() throws
	 *
	 * @return object
	 */
	private static function caseStore(array|\Throwable $case): object {
		return new class($case) {
			/** @var list<array<string, mixed>> */
			public array $finds = [];

			/**
			 * Constructor.
			 *
			 * @param array<string, mixed>|\Throwable $case The answer
			 */
			public function __construct(private array|\Throwable $case) {
			}

			/**
			 * Find a case.
			 *
			 * @param string $id The id
			 * @param mixed $register The register
			 * @param mixed $schema The schema
			 *
			 * @return array<string, mixed>
			 */
			public function find(string $id, mixed $register = null, mixed $schema = null): array {
				$this->finds[] = ['id' => $id, 'register' => $register, 'schema' => $schema];
				if ($this->case instanceof \Throwable) {
					throw $this->case;
				}

				return $this->case;
			}
		};

	}//end caseStore()

	/**
	 * Build the listener.
	 *
	 * @param LoggerInterface|null $logger Optional logger
	 *
	 * @return DependentTermListener
	 */
	private function listener(?LoggerInterface $logger = null): DependentTermListener {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			fn (string $key, string $default = ''): string => (string)($this->config[$key] ?? $default)
		);
		$settings->method('getObjectService')->willReturnCallback(fn (): ?object => $this->objectService);

		$terms = $this->createMock(TermijnService::class);
		$terms->method('getTermijnInstance')->willReturnCallback(fn (string $termInstanceId): ?array => $this->instance);

		$offers = $this->createMock(DependentTermOffer::class);
		$offers->method('offer')->willReturnCallback(
			function (string $sourceCaseId, string $sourceTitle, int $daysImpact): int {
				$this->offered[] = [$sourceCaseId, $sourceTitle, $daysImpact];
				return 1;
			}
		);

		return new DependentTermListener(
			settingsService: $settings,
			terms: $terms,
			offers: $offers,
			logger: ($logger ?? $this->createMock(LoggerInterface::class))
		);

	}//end listener()

	/**
	 * The offer carries the case's title, read from the configured register and schema.
	 *
	 * @return void
	 */
	public function testTheOfferCarriesTheCaseTitle(): void {
		$store = self::caseStore(['title' => 'Bouwvergunning Dorpsstraat', 'identifier' => 'Z-1']);
		$this->objectService = $store;

		$this->listener()->handle($this->moved(['type' => 'pauze']));

		$this->assertSame([['case-a', 'Bouwvergunning Dorpsstraat', 10]], $this->offered);
		$this->assertSame([['id' => 'case-a', 'register' => '1', 'schema' => '2']], $store->finds);

	}//end testTheOfferCarriesTheCaseTitle()

	/**
	 * Without a title the identifier is used; a case reference given as an object is unwrapped.
	 *
	 * @return void
	 */
	public function testIdentifierAndObjectCaseReference(): void {
		$this->objectService = self::caseStore(['title' => '', 'identifier' => 'Z-2026-7']);
		$this->instance = ['id' => 'term-1', 'case' => ['id' => 'case-b']];

		$this->listener()->handle($this->moved());

		$this->assertSame([['case-b', 'Z-2026-7', 10]], $this->offered);

	}//end testIdentifierAndObjectCaseReference()

	/**
	 * A failing title lookup, a missing case schema or no object service still offer, with an empty title.
	 *
	 * @return void
	 */
	public function testAnUnreadableTitleIsEmpty(): void {
		$this->objectService = self::caseStore(new RuntimeException('register down'));
		$this->listener()->handle($this->moved());

		$this->objectService = self::caseStore(['title' => 'T']);
		$this->config['case_schema'] = '';
		$this->listener()->handle($this->moved());

		$this->assertSame([['case-a', '', 10], ['case-a', '', 10]], $this->offered);

	}//end testAnUnreadableTitleIsEmpty()

	/**
	 * Without a term instance reference, an unknown instance, or an instance without a case, nothing is offered.
	 *
	 * @return void
	 */
	public function testNoCaseMeansNoOffer(): void {
		$this->listener()->handle($this->moved(['deadlineInstance' => '']));

		$this->instance = null;
		$this->listener()->handle($this->moved());

		$this->instance = ['id' => 'term-1', 'case' => ['title' => 'no id']];
		$this->listener()->handle($this->moved());

		$this->assertSame([], $this->offered);

	}//end testNoCaseMeansNoOffer()

	/**
	 * Negative days, an event without an object, or no configured term schema offer nothing.
	 *
	 * @return void
	 */
	public function testNonMovesAreIgnored(): void {
		$this->listener()->handle($this->moved(['daysImpact' => -3]));
		$this->listener()->handle(new Event());

		$this->config['termijn_gebeurtenis_schema'] = '';
		$this->listener()->handle($this->moved());

		$this->assertSame([], $this->offered);

	}//end testNonMovesAreIgnored()

	/**
	 * A failing offer is logged as a warning rather than breaking the save.
	 *
	 * @return void
	 */
	public function testAFailingOfferIsLogged(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			fn (string $key, string $default = ''): string => (string)($this->config[$key] ?? $default)
		);
		$terms = $this->createMock(TermijnService::class);
		$terms->method('getTermijnInstance')->willReturn(['id' => 'term-1', 'case' => 'case-a']);
		$offers = $this->createMock(DependentTermOffer::class);
		$offers->method('offer')->willThrowException(new RuntimeException('task schema missing'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with('Dossiq: a moved term could not be offered to its dependents: task schema missing');

		(new DependentTermListener(settingsService: $settings, terms: $terms, offers: $offers, logger: $logger))->handle($this->moved());

	}//end testAFailingOfferIsLogged()
}//end class

/**
 * An object-created event carrying a payload.
 */
final class MovedTermEvent extends Event {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $object The created object
	 */
	public function __construct(private readonly array $object) {
		parent::__construct();

	}//end __construct()

	/**
	 * The created object.
	 *
	 * @return array<string, mixed>
	 */
	public function getObject(): array {
		return $this->object;

	}//end getObject()
}//end class
