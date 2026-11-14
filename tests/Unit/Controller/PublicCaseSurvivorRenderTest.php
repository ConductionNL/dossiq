<?php

/**
 * The public survivor endpoint renders the surviving case through OpenRegister's renderEntity().
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\PublicCaseSurvivorController;
use OCA\Dossiq\Service\CaseMergeService;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * @covers \OCA\Dossiq\Controller\PublicCaseSurvivorController
 * @uses \OCA\Dossiq\Service\Intake\CaseMatchSuggestion
 * @uses \OCA\Dossiq\Service\Support\SerialisedResult
 */
class PublicCaseSurvivorRenderTest extends TestCase {

	/**
	 * The case is rendered with RBAC and multitenancy on and nothing extended.
	 *
	 * @return void
	 */
	public function testTheSurvivorIsRenderedWithAccessChecksOn(): void {
		$objects = new class {

			/**
			 * The render calls, as [rbac, multitenancy].
			 *
			 * @var array<int, array{bool, bool}>
			 */
			public array $renders = [];

			/**
			 * Answer one case entity.
			 *
			 * @param mixed ...$args The find arguments.
			 *
			 * @return ObjectEntity The case.
			 */
			public function find(mixed ...$args): ObjectEntity {
				$entity = new ObjectEntity();
				$entity->setUuid('case-a');

				return $entity;
			}

			/**
			 * Render an entity, on OpenRegister's signature.
			 *
			 * @param ObjectEntity $entity        The entity.
			 * @param object|null  $selection     What to extend and filter; none here.
			 * @param bool         $_rbac         Apply RBAC.
			 * @param bool         $_multitenancy Apply organisation scoping.
			 *
			 * @return array<string, mixed> The rendered case.
			 */
			public function renderEntity(ObjectEntity $entity, ?object $selection = null, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->renders[] = [$_rbac, $_multitenancy];

				return ['id' => $entity->getUuid()];
			}
		};
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getConfigValue')->willReturnMap([['register', '', 'dossiq'], ['case_schema', '', 'case']]);

		$controller = new PublicCaseSurvivorController(
			appName: 'dossiq',
			request: $this->createMock(IRequest::class),
			mergeService: $this->createMock(CaseMergeService::class),
			settingsService: $settings,
			logger: $this->createMock(LoggerInterface::class),
		);
		$render = new ReflectionMethod($controller, 'renderPublicly');

		$this->assertSame(['id' => 'case-a'], $render->invoke($controller, 'case-a'));
		$this->assertSame([[true, true]], $objects->renders);
	}//end testTheSurvivorIsRenderedWithAccessChecksOn()
}//end class
