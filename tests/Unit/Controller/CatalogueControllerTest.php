<?php

/**
 * Dossiq Catalogue Controller Test
 *
 * The object types endpoint is admin-only and answers the catalogue's rows
 * under `results`, which is where the catalogue page reads them.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/catalogue-object-types/specs/catalogue/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\CatalogueController;
use OCA\Dossiq\Service\Catalogue\ObjectTypeCatalogue;
use OCA\Dossiq\Settings\AdminSettings;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;
use RuntimeException;

/**
 * The catalogue's object types endpoint.
 */
final class CatalogueControllerTest extends TestCase {
	/**
	 * The rows come back under `results`, with their total.
	 *
	 * @return void
	 */
	public function testAnswersTheRowsUnderResults(): void {
		$rows = [['id' => 'building', 'title' => 'building', 'cases' => 2]];
		$catalogue = $this->createMock(ObjectTypeCatalogue::class);
		$catalogue->expects($this->once())->method('list')->willReturn($rows);

		$controller = new CatalogueController($this->createMock(IRequest::class), $catalogue, new NullLogger());

		$this->assertSame(['results' => $rows, 'total' => 1], $controller->objectTypes()->getData());
	}

	/**
	 * A register OpenRegister cannot read answers 503, not an empty list.
	 *
	 * @return void
	 */
	public function testAFailedReadIsNotAnEmptyList(): void {
		$catalogue = $this->createMock(ObjectTypeCatalogue::class);
		$catalogue->method('list')->willThrowException(new RuntimeException('no such register'));

		$response = (new CatalogueController($this->createMock(IRequest::class), $catalogue, new NullLogger()))->objectTypes();

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame([], $response->getData()['results']);
	}

	/**
	 * Only an administrator reaches it: Nextcloud enforces the admin setting
	 * before the method runs, and nothing opens it to every user.
	 *
	 * @return void
	 */
	public function testIsAdminOnly(): void {
		$method = new ReflectionMethod(CatalogueController::class, 'objectTypes');

		$admin = $method->getAttributes(AuthorizedAdminSetting::class);
		$this->assertCount(1, $admin);
		$this->assertSame(AdminSettings::class, $admin[0]->getArguments()['settings']);
		$this->assertSame([], $method->getAttributes(NoAdminRequired::class));
	}
}
