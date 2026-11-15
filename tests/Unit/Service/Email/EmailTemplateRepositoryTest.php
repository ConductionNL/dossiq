<?php
/**
 * Unit tests for EmailTemplateRepository: where it reads and writes email
 * templates and cases, and what it answers when OpenRegister or the schema
 * configuration is missing.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Email
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/case-email-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Email;

require_once __DIR__ . '/RecordingObjectService.php';

use JsonSerializable;
use OCA\Dossiq\Service\Email\EmailTemplateRepository;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Templates by case type, saving, and single reads of templates and cases.
 *
 * @covers \OCA\Dossiq\Service\Email\EmailTemplateRepository
 */
class EmailTemplateRepositoryTest extends TestCase {

	/**
	 * A repository over $objects and the given app config.
	 *
	 * @param RecordingObjectService|null $objects The object service, null when OpenRegister is absent.
	 * @param array<string, string>       $config  App config values.
	 *
	 * @return EmailTemplateRepository
	 */
	private function repository(?RecordingObjectService $objects, array $config = []): EmailTemplateRepository {
		$config += ['register' => 'dossiq', 'email_template_schema' => 'emailTemplate', 'case_schema' => 'case'];
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key): string => ($config[$key] ?? ''));

		return new EmailTemplateRepository(settingsService: $settings);
	}//end repository()

	/**
	 * Templates of a case type are searched by slug, at most 100, and only
	 * active ones (or ones without the flag) are returned.
	 *
	 * @return void
	 */
	public function testActiveTemplatesOfACaseType(): void {
		$objects = new RecordingObjectService(
			searched: [
				['id' => 't1', 'isActive' => true],
				['id' => 't2', 'isActive' => false],
				['id' => 't3'],
				['id' => 't4', 'isActive' => 'yes'],
			]
		);

		$this->assertSame(
			expected: [['id' => 't1', 'isActive' => true], ['id' => 't3']],
			actual: $this->repository(objects: $objects)->findActiveByCaseType(caseTypeId: 'ct-1')
		);
		$this->assertSame(
			expected: [['search', ['register' => 'dossiq', 'schema' => 'emailTemplate', 'filters' => ['caseType' => 'ct-1', '_limit' => 100]]]],
			actual: $objects->calls
		);
	}//end testActiveTemplatesOfACaseType()

	/**
	 * Without OpenRegister or a configured schema there are no templates.
	 *
	 * @return void
	 */
	public function testNoTemplatesWithoutStoreOrSchema(): void {
		$this->assertSame(expected: [], actual: $this->repository(objects: null)->findActiveByCaseType(caseTypeId: 'ct-1'));

		$objects = new RecordingObjectService(searched: [['id' => 't1']]);
		$this->assertSame(
			expected: [],
			actual: $this->repository(objects: $objects, config: ['email_template_schema' => ''])->findActiveByCaseType(caseTypeId: 'ct-1')
		);
		$this->assertSame(
			expected: [],
			actual: $this->repository(objects: $objects, config: ['register' => ''])->findActiveByCaseType(caseTypeId: 'ct-1')
		);
		$this->assertSame(expected: [], actual: $objects->calls);
	}//end testNoTemplatesWithoutStoreOrSchema()

	/**
	 * A template is saved in the template schema and the stored object is
	 * returned as an array.
	 *
	 * @return void
	 */
	public function testSaveReturnsTheStoredTemplate(): void {
		$stored = new class implements JsonSerializable {
			/**
			 * The stored template.
			 *
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return ['id' => 't9', 'name' => 'Welcome'];
			}
		};
		$objects = new RecordingObjectService(saved: $stored);

		$this->assertSame(
			expected: ['id' => 't9', 'name' => 'Welcome'],
			actual: $this->repository(objects: $objects)->saveTemplate(payload: ['name' => 'Welcome'])
		);
		$this->assertSame(
			expected: [['save', ['object' => ['name' => 'Welcome'], 'register' => 'dossiq', 'schema' => 'emailTemplate']]],
			actual: $objects->calls
		);
	}//end testSaveReturnsTheStoredTemplate()

	/**
	 * When the store answers with nothing usable, the payload is returned.
	 *
	 * @return void
	 */
	public function testSaveFallsBackToThePayload(): void {
		$this->assertSame(
			expected: ['name' => 'Welcome'],
			actual: $this->repository(objects: new RecordingObjectService(saved: 'not an object'))->saveTemplate(payload: ['name' => 'Welcome'])
		);
	}//end testSaveFallsBackToThePayload()

	/**
	 * Saving without OpenRegister or a configured schema is refused.
	 *
	 * @return void
	 */
	public function testSaveIsRefusedWithoutStoreOrSchema(): void {
		try {
			$this->repository(objects: null)->saveTemplate(payload: []);
			$this->fail(message: 'a save without OpenRegister must be refused');
		} catch (RuntimeException $e) {
			$this->assertSame(expected: 'ObjectService unavailable', actual: $e->getMessage());
		}

		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessage(message: 'emailTemplate schema is not configured');
		$this->repository(objects: new RecordingObjectService(), config: ['email_template_schema' => ''])->saveTemplate(payload: []);
	}//end testSaveIsRefusedWithoutStoreOrSchema()

	/**
	 * A template is read from the template schema; a failing read is null.
	 *
	 * @return void
	 */
	public function testFindTemplate(): void {
		$objects = new RecordingObjectService(found: ['id' => 't1', 'subject' => 'Hi']);
		$this->assertSame(expected: ['id' => 't1', 'subject' => 'Hi'], actual: $this->repository(objects: $objects)->findTemplate(templateId: 't1'));
		$this->assertSame(expected: [['find', ['id' => 't1', 'register' => 'dossiq', 'schema' => 'emailTemplate']]], actual: $objects->calls);

		$failing = new RecordingObjectService(findFails: new RuntimeException(message: 'gone'));
		$this->assertNull(actual: $this->repository(objects: $failing)->findTemplate(templateId: 't1'));
		$this->assertNull(actual: $this->repository(objects: new RecordingObjectService(found: 'x'))->findTemplate(templateId: 't1'));
		$this->assertNull(actual: $this->repository(objects: null)->findTemplate(templateId: 't1'));
	}//end testFindTemplate()

	/**
	 * A case is read from the case schema and marked final once it has an end date.
	 *
	 * @return void
	 */
	public function testFindCaseMarksAClosedCaseFinal(): void {
		$open = new RecordingObjectService(found: ['id' => 'c1', 'endDate' => '']);
		$this->assertSame(
			expected: ['id' => 'c1', 'endDate' => '', '_isFinal' => false],
			actual: $this->repository(objects: $open)->findCase(caseId: 'c1')
		);
		$this->assertSame(expected: 'case', actual: $open->calls[0][1]['schema']);

		$closed = new RecordingObjectService(found: ['id' => 'c2', 'endDate' => '2026-10-01']);
		$this->assertTrue(condition: $this->repository(objects: $closed)->findCase(caseId: 'c2')['_isFinal']);

		$this->assertNull(actual: $this->repository(objects: new RecordingObjectService(), config: ['case_schema' => ''])->findCase(caseId: 'c1'));
	}//end testFindCaseMarksAClosedCaseFinal()
}//end class
