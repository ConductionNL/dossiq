<?php

/**
 * SchemaKeyReconciler after an import: the register id and every known schema
 * slug land in app config, and a missing schema mapper reconciles nothing.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Settings;

use OCA\Dossiq\Service\Settings\SchemaKeyReconciler;
use OCA\Dossiq\Service\Settings\SchemaSlugMap;
use OCA\Dossiq\Service\Settings\SchemaSlugResolver;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * What an import result writes into the app's schema config keys.
 */
#[CoversClass(SchemaKeyReconciler::class)]
#[UsesClass(SchemaSlugResolver::class)]
final class SchemaKeyReconcilerImportTest extends TestCase {

	private IAppConfig&MockObject $appConfig;
	private ContainerInterface&MockObject $container;
	private LoggerInterface&MockObject $logger;

	/**
	 * App config keys written, in order.
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	private array $written = [];

	protected function setUp(): void {
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturn('');
		$this->appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				TestCase::assertSame('dossiq', $app);
				$this->written[] = [$key, $value];
				return true;
			}
		);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->logger    = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The reconciler under test.
	 *
	 * @return SchemaKeyReconciler
	 */
	private function reconciler(): SchemaKeyReconciler {
		return new SchemaKeyReconciler(
			appConfig: $this->appConfig,
			container: $this->container,
			logger: $this->logger,
			slugResolver: new SchemaSlugResolver(appConfig: $this->appConfig, container: $this->container, logger: $this->logger)
		);
	}//end reconciler()

	/**
	 * An imported entity with an id and, optionally, a slug.
	 *
	 * @param int         $id   The id.
	 * @param string|null $slug The slug.
	 *
	 * @return object
	 */
	private static function entity(int $id, ?string $slug = null): object {
		return new class($id, $slug) {
			public function __construct(private int $id, private ?string $slug) {
			}

			public function getId(): int {
				return $this->id;
			}

			public function getSlug(): ?string {
				return $this->slug;
			}
		};
	}//end entity()

	public function testTheFirstImportedRegisterAndEveryKnownSchemaAreConfigured(): void {
		$catalogusKey = SchemaSlugMap::SLUG_TO_CONFIG_KEY['zgwCatalogus'];
		$workflowKey  = SchemaSlugMap::SLUG_TO_CONFIG_KEY[SchemaSlugMap::WORKFLOW_TEMPLATE_SLUG];

		$count = $this->reconciler()->autoConfigureAfterImport(
			importResult: [
				'registers' => ['not an entity', self::entity(23), self::entity(99)],
				'schemas' => [
					self::entity(7, 'zgwCatalogus'),
					self::entity(8, 'notADossiqSchema'),
					'not an entity',
					self::entity(9, SchemaSlugMap::WORKFLOW_TEMPLATE_SLUG),
				],
			]
		);

		$this->assertSame(2, $count);
		$this->assertSame(
			[
				['register', '23'],
				[$catalogusKey, '7'],
				[$workflowKey, '9'],
				[SchemaSlugMap::WORKFLOW_DEFINITION_ALIAS, '9'],
			],
			$this->written
		);
	}//end testTheFirstImportedRegisterAndEveryKnownSchemaAreConfigured()

	public function testAnEmptyImportConfiguresNothing(): void {
		$this->assertSame(0, $this->reconciler()->autoConfigureAfterImport(importResult: []));
		$this->assertSame([], $this->written);
	}//end testAnEmptyImportConfiguresNothing()

	public function testWithoutASchemaMapperNothingIsReconciled(): void {
		$this->container->method('get')->with('OCA\OpenRegister\Db\SchemaMapper')->willThrowException(new RuntimeException('not installed'));
		$this->logger->expects($this->once())
			->method('error')
			->with('Dossiq: Could not access OpenRegister SchemaMapper for reconcile', ['exception' => 'not installed']);

		$this->assertSame(0, $this->reconciler()->reconcile());
		$this->assertSame([], $this->written);
	}//end testWithoutASchemaMapperNothingIsReconciled()
}//end class
