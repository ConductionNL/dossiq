<?php

/**
 * Unit tests for DefaultRoute.
 *
 * A definition's route with the blank read as the default, the default a
 * case type records in either shape, and the pick among several active
 * routes: the recorded one, else the lowest slug and highest version, logged.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
 *
 * @covers \OCA\Dossiq\Service\Workflow\DefaultRoute
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Workflow;

use OCA\Dossiq\Service\Workflow\DefaultRoute;
use OCA\Dossiq\Service\Workflow\WorkflowDefinitionRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DefaultRouteTest extends TestCase {

	/**
	 * The route reader over the given case types.
	 *
	 * @param array<string, mixed>  $caseTypes The case types, by id.
	 * @param LoggerInterface|null $logger    The logger, or a silent one.
	 *
	 * @return DefaultRoute
	 */
	private function routes(array $caseTypes, ?LoggerInterface $logger = null): DefaultRoute {
		$repository = $this->createMock(originalClassName: WorkflowDefinitionRepository::class);
		$repository->method('findCaseType')->willReturnCallback(
			static fn (string $caseTypeId): ?array => ($caseTypes[$caseTypeId] ?? null)
		);

		return new DefaultRoute(
			repository: $repository,
			logger: ($logger ?? $this->createMock(originalClassName: LoggerInterface::class))
		);
	}//end routes()

	/**
	 * A blank route is the default route; a named one is trimmed.
	 *
	 * @return void
	 */
	public function testABlankRouteIsTheDefaultRoute(): void {
		$routes = $this->routes(caseTypes: []);

		$this->assertSame(expected: 'standaard', actual: $routes->variantOf(row: ['variant' => '  ']));
		$this->assertSame(expected: 'standaard', actual: $routes->variantOf(row: []));
		$this->assertSame(expected: 'spoed', actual: $routes->variantOf(row: ['variant' => ' spoed ']));
	}//end testABlankRouteIsTheDefaultRoute()

	/**
	 * The recorded default is read as an id or as an embedded row, and nothing else.
	 *
	 * @return void
	 */
	public function testTheRecordedDefaultIsReadInEitherShape(): void {
		$routes = $this->routes(
			caseTypes: [
				'ct-id'   => ['workflowDefinition' => 'def-1'],
				'ct-row'  => ['workflowDefinition' => ['uuid' => 'def-2']],
				'ct-none' => [],
			]
		);

		$this->assertSame(expected: 'def-1', actual: $routes->definitionIdFor(caseTypeId: 'ct-id'));
		$this->assertSame(expected: 'def-2', actual: $routes->definitionIdFor(caseTypeId: 'ct-row'));
		$this->assertSame(expected: '', actual: $routes->definitionIdFor(caseTypeId: 'ct-none'));
		$this->assertSame(expected: '', actual: $routes->definitionIdFor(caseTypeId: 'ct-unknown'));
		$this->assertSame(expected: '', actual: $routes->definitionIdFor(caseTypeId: ''));
	}//end testTheRecordedDefaultIsReadInEitherShape()

	/**
	 * The recorded default wins; without one the lowest route and its highest version, logged.
	 *
	 * @return void
	 */
	public function testTheRecordedDefaultWinsAndAnAmbiguousPickIsLogged(): void {
		$active = [
			['id' => 'b-1', 'variant' => 'spoed', 'version' => 1],
			['id' => 'a-1', 'variant' => '', 'version' => 1],
			['id' => 'a-3', 'variant' => '', 'version' => 3],
		];

		$pinned = $this->routes(caseTypes: ['ct' => ['workflowDefinition' => 'b-1']]);
		$this->assertSame(expected: 'b-1', actual: $pinned->among(active: $active, caseTypeId: 'ct')['id']);
		$this->assertSame(expected: 'a-1', actual: $pinned->among(active: [$active[1]], caseTypeId: 'ct')['id']);

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			$this->stringContains(string: 'no usable default route'),
			$this->callback(
				callback: static fn (array $context): bool => $context['chose'] === 'b-1'
					&& $context['routes'] === ['spoed', 'standaard', 'standaard']
			)
		);

		$ambiguous = $this->routes(caseTypes: ['ct' => []], logger: $logger);
		$this->assertSame(expected: 'b-1', actual: $ambiguous->among(active: $active, caseTypeId: 'ct')['id']);

		$sameRoute = $this->routes(caseTypes: ['ct' => []]);
		$this->assertSame(expected: 'a-3', actual: $sameRoute->among(active: [$active[1], $active[2]], caseTypeId: 'ct')['id']);
	}//end testTheRecordedDefaultWinsAndAnAmbiguousPickIsLogged()
}//end class
