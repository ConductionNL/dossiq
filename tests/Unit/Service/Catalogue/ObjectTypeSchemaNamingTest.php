<?php

/**
 * Unit tests for how ObjectTypeSchemaResolver reads the schema an object URL
 * names: only an OpenRegister objects path with a register and a schema
 * names one, its segments are URL decoded, and namesSchema() says whether
 * there is one at all.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Catalogue;

use OCA\Dossiq\Service\Catalogue\ObjectTypeSchemaResolver;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\Catalogue\ObjectTypeSchemaResolver
 */
class ObjectTypeSchemaNamingTest extends TestCase {

	private function resolver(): ObjectTypeSchemaResolver {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');
		return new ObjectTypeSchemaResolver($container, $this->createMock(LoggerInterface::class));
	}//end resolver()

	public function testAnObjectsPathNamesASchemaWithoutReadingIt(): void {
		$resolver = $this->resolver();

		$this->assertTrue($resolver->namesSchema('https://nc.example/index.php/apps/openregister/api/objects/7/trees/abc'));
		$this->assertTrue($resolver->namesSchema('/apps/openregister/api/objects/dossiq/42'));
		$this->assertFalse($resolver->namesSchema('/apps/openregister/api/objects/7'));
		$this->assertFalse($resolver->namesSchema('https://elsewhere.example/api/objects/7/trees/abc'));
		$this->assertFalse($resolver->namesSchema(''));
	}//end testAnObjectsPathNamesASchemaWithoutReadingIt()

	public function testTheRegisterAndSchemaSegmentsAreUrlDecoded(): void {
		$this->assertSame('bomen zuid', ObjectTypeSchemaResolver::schemaNamedIn('/apps/openregister/api/objects/my%20register/bomen%20zuid/x'));
		$this->assertSame('bomen/zuid', ObjectTypeSchemaResolver::schemaNamedIn('/apps/openregister/api/objects/7/bomen%2Fzuid/x'));
	}//end testTheRegisterAndSchemaSegmentsAreUrlDecoded()
}//end class
