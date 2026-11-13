<?php

/**
 * File Restriction Mirror Test
 *
 * Where OpenRegister's restriction service does not resolve, the mirror says
 * so and refuses to restrict or free a file instead of silently doing nothing.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Zaakdossier
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-every-path-that-returns-the-document-or-its-file-honours-the-exception
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zaakdossier;

use OCA\Dossiq\Service\Zaakdossier\FileRestrictionMirror;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Zaakdossier\FileRestrictionMirror
 */
class FileRestrictionMirrorTest extends TestCase {

	protected function setUp(): void {
		if (class_exists(FileRestrictionMirror::SERVICE) === true) {
			self::markTestSkipped('OpenRegister\'s restriction service is installed beside this app');
		}
	}//end setUp()

	/**
	 * A mirror whose container must not be asked: the service class is not there to resolve.
	 *
	 * @return FileRestrictionMirror
	 */
	private function mirror(): FileRestrictionMirror {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects(self::never())->method('get');

		return new FileRestrictionMirror(container: $container);
	}//end mirror()

	public function testUnavailableWithoutOpenRegistersService(): void {
		self::assertFalse($this->mirror()->isAvailable());
	}//end testUnavailableWithoutOpenRegistersService()

	public function testRestrictIsRefusedWithoutTheService(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('OpenRegister on this instance cannot restrict a file');

		$this->mirror()->restrict(42, ['alice'], ['juristen'], 'Privacy');
	}//end testRestrictIsRefusedWithoutTheService()

	public function testLiftIsRefusedWithoutTheService(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('OpenRegister on this instance cannot restrict a file');

		$this->mirror()->lift(42);
	}//end testLiftIsRefusedWithoutTheService()
}//end class
