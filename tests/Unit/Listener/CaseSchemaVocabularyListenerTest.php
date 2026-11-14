<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\CaseSchemaVocabularyListener;
use OCA\Dossiq\Service\People\CaseRoleVocabulary;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\SchemaUpdatedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Re-importing the register writes the case schema's shipped configuration,
 * which names only the generic party roles; the instance's role types are put
 * back at once, so the Roles card keeps naming them.
 *
 * @spec openspec/changes/case-roles-survive-a-schema-reimport/specs/people-on-the-case/spec.md#requirement-the-case-schemas-role-vocabulary-survives-a-rewrite-of-the-schema
 */
class CaseSchemaVocabularyListenerTest extends TestCase {

	/** @var CaseRoleVocabulary&MockObject The doubled vocabulary. */
	private CaseRoleVocabulary&MockObject $vocabulary;

	/** @var CaseSchemaVocabularyListener The listener under test. */
	private CaseSchemaVocabularyListener $listener;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => (['case_schema' => '172'][$key] ?? $default)
		);
		$this->vocabulary = $this->createMock(originalClassName: CaseRoleVocabulary::class);
		$this->listener = new CaseSchemaVocabularyListener(
			settingsService: $settings,
			vocabulary: $this->vocabulary,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end setUp()

	/**
	 * A schema with an id and a slug.
	 *
	 * @param int    $id   The id.
	 * @param string $slug The slug.
	 *
	 * @return Schema
	 */
	private function schema(int $id, string $slug): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setSlug(slug: $slug);

		return $schema;
	}//end schema()

	/**
	 * @return void
	 */
	public function testAnUpdateOfTheCaseSchemaWritesTheRoleTypesBack(): void {
		$this->vocabulary->expects($this->once())->method('sync')->willReturn(11);

		$this->listener->handle(event: new SchemaUpdatedEvent($this->schema(id: 172, slug: 'case'), $this->schema(id: 172, slug: 'case')));
	}//end testAnUpdateOfTheCaseSchemaWritesTheRoleTypesBack()

	/**
	 * @return void
	 */
	public function testAnotherSchemaOrEventLeavesTheVocabularyAlone(): void {
		$this->vocabulary->expects($this->never())->method('sync');

		$this->listener->handle(event: new SchemaUpdatedEvent($this->schema(id: 168, slug: 'roleType'), $this->schema(id: 168, slug: 'roleType')));
		$this->listener->handle(event: new Event());
	}//end testAnotherSchemaOrEventLeavesTheVocabularyAlone()

	/**
	 * @return void
	 */
	public function testAFailedSyncDoesNotFailTheSchemaWrite(): void {
		$this->vocabulary->method('sync')->willThrowException(new RuntimeException('down'));

		$this->listener->handle(event: new SchemaUpdatedEvent($this->schema(id: 172, slug: 'case'), $this->schema(id: 172, slug: 'case')));
		$this->addToAssertionCount(count: 1);
	}//end testAFailedSyncDoesNotFailTheSchemaWrite()
}//end class
