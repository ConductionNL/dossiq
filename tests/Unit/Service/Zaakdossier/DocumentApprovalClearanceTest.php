<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Zaakdossier
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/besluitvorming-leaf/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zaakdossier;

use OCA\Dossiq\Service\Zaakdossier\DocumentApprovalClearance;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * decidiq's clearance per document, one lookup for many, and the row marker.
 *
 * @covers \OCA\Dossiq\Service\Zaakdossier\DocumentApprovalClearance
 * @uses \OCA\Dossiq\Support\FleetAppId
 */
class DocumentApprovalClearanceTest extends TestCase {

	/**
	 * A container that answers decidiq's two services, or nothing.
	 *
	 * @param object|null $routes The route service, or null for no decidiq.
	 * @param object|null $clearance The clearance service.
	 * @param int $lookups Incremented once per container get().
	 *
	 * @return ContainerInterface The container.
	 */
	private function container(?object $routes, ?object $clearance, int &$lookups): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($routes, $clearance, &$lookups): object {
				$lookups++;
				if ($routes !== null && str_ends_with($id, 'Service\\ApprovalRouteService') === true) {
					return $routes;
				}

				if ($clearance !== null && str_ends_with($id, 'Service\\SubjectClearanceService') === true) {
					return $clearance;
				}

				throw new \RuntimeException('not registered: ' . $id);
			}
		);

		return $container;
	}//end container()

	/**
	 * Many documents are answered with decidiq looked up once, each with its own answer.
	 *
	 * @return void
	 */
	public function testManyDocumentsLookDecidiqUpOnce(): void {
		$routes = new class {
			/**
			 * @param string $subject The document.
			 * @return array<int, array<string, string>>
			 */
			public function routesWithStagesFor(string $subject): array {
				if ($subject === 'doc-broken') {
					throw new \RuntimeException('decidiq fell over');
				}

				return ($subject === 'doc-a') ? [['id' => 'route-1']] : [];
			}
		};
		$clearance = new class {
			/**
			 * @param array<int, mixed> $routes The routes.
			 * @return array<string, mixed>
			 */
			public function clearanceFor(array $routes): array {
				return ['cleared' => false, 'waitingOn' => [['routeName' => 'Teamleider', 'actor' => 'Jan']]];
			}
		};

		$lookups = 0;
		$reader = new DocumentApprovalClearance(
			$this->container($routes, $clearance, $lookups),
			$this->createMock(LoggerInterface::class),
		);

		$answers = $reader->forDocuments(['doc-a', 'doc-b', 'doc-broken', '', 'doc-a']);
		$afterBatch = $lookups;

		self::assertSame(['doc-a', 'doc-b', 'doc-broken'], array_keys($answers));
		self::assertTrue($answers['doc-a']['routed']);
		self::assertFalse($answers['doc-a']['cleared']);
		self::assertSame(DocumentApprovalClearance::NOT_ROUTED, $answers['doc-b']);
		self::assertSame(DocumentApprovalClearance::NOT_ROUTED, $answers['doc-broken'], 'one failure costs one answer');

		$reader->forDocuments(['doc-a', 'doc-b', 'doc-c']);
		self::assertSame($afterBatch * 2, $lookups, 'the lookup does not grow with the number of documents');

		self::assertSame(
			['routed' => true, 'cleared' => false, 'waitingOn' => [['routeName' => 'Teamleider', 'actor' => 'Jan']], 'reason' => 'Teamleider, waiting on Jan'],
			$reader->markerFor($answers['doc-a'])
		);
		self::assertSame($answers['doc-a'], $reader->forDocument('doc-a'));
	}//end testManyDocumentsLookDecidiqUpOnce()

	/**
	 * Without decidiq every document is unrouted, and an unrouted one has no marker.
	 *
	 * @return void
	 */
	public function testWithoutDecidiqEveryDocumentIsUnrouted(): void {
		$lookups = 0;
		$reader = new DocumentApprovalClearance(
			$this->container(null, null, $lookups),
			$this->createMock(LoggerInterface::class),
		);

		self::assertSame(['doc-a' => DocumentApprovalClearance::NOT_ROUTED], $reader->forDocuments(['doc-a']));
		self::assertSame([], $reader->forDocuments(['', '  ']));
		self::assertSame(DocumentApprovalClearance::NOT_ROUTED, $reader->forDocument(' '));
		self::assertNull($reader->markerFor(DocumentApprovalClearance::NOT_ROUTED));
	}//end testWithoutDecidiqEveryDocumentIsUnrouted()
}//end class
