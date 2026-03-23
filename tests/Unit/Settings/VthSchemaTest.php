<?php

/**
 * VTH Schema Unit Tests
 *
 * Validates that the procest_register.json schema configuration contains all
 * required VTH (Vergunningen, Toezicht, Handhaving) schema definitions and
 * that the VTH template files are valid JSON.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Unit tests verifying the VTH workflow configuration schema and template files.
 *
 * @covers \OCA\Procest\Service\SettingsService
 */
class VthSchemaTest extends TestCase
{

    /**
     * The decoded procest_register data.
     *
     * @var array
     */
    private array $registerData;

    /**
     * Path to the VTH templates directory.
     *
     * @var string
     */
    private string $vthTemplatesDir;


    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $schemaFilePath = __DIR__.'/../../../lib/Settings/procest_register.json';
        $content        = file_get_contents($schemaFilePath);
        $this->registerData    = json_decode($content, true);
        $this->vthTemplatesDir = __DIR__.'/../../../lib/Settings/vth-templates';

    }//end setUp()


    /**
     * Test that all four VTH schemas are registered in procest_register.json.
     *
     * @return void
     */
    public function testAllVthSchemasAreRegistered(): void
    {
        $schemas = $this->registerData['components']['schemas'];

        $vthSchemas = [
            'inspectieChecklist',
            'inspectieRapport',
            'handhavingsactie',
            'adviesAanvraag',
        ];

        foreach ($vthSchemas as $schemaName) {
            $this->assertArrayHasKey(
                $schemaName,
                $schemas,
                "VTH schema '{$schemaName}' must be defined in procest_register.json"
            );
        }

    }//end testAllVthSchemasAreRegistered()


    /**
     * Test that the VTH templates directory exists.
     *
     * @return void
     */
    public function testVthTemplatesDirectoryExists(): void
    {
        $this->assertDirectoryExists(
            $this->vthTemplatesDir,
            'lib/Settings/vth-templates/ directory must exist'
        );

    }//end testVthTemplatesDirectoryExists()


    /**
     * Test that each VTH template file is valid JSON with required fields.
     *
     * @return void
     */
    public function testVthTemplateFilesAreValidJson(): void
    {
        $templateFiles = glob($this->vthTemplatesDir.'/*.json');

        $this->assertNotEmpty(
            $templateFiles,
            'At least one VTH template JSON file must exist in vth-templates/'
        );

        foreach ($templateFiles as $file) {
            $content = file_get_contents($file);
            $data    = json_decode($content, true);
            $name    = basename($file);

            $this->assertSame(
                JSON_ERROR_NONE,
                json_last_error(),
                "VTH template file '{$name}' must be valid JSON"
            );

            $this->assertIsArray($data, "VTH template '{$name}' must decode to an array");
        }

    }//end testVthTemplateFilesAreValidJson()


    /**
     * Test that the expected VTH case type template files are present.
     *
     * @return void
     */
    public function testExpectedVthTemplateFilesArePresent(): void
    {
        $expected = [
            'omgevingsvergunning-regulier.json',
            'handhavingszaak.json',
        ];

        foreach ($expected as $filename) {
            $this->assertFileExists(
                $this->vthTemplatesDir.'/'.$filename,
                "Expected VTH template '{$filename}' must exist"
            );
        }

    }//end testExpectedVthTemplateFilesArePresent()


    /**
     * Test that the vth_seed_data.json exists and is valid JSON.
     *
     * @return void
     */
    public function testVthSeedDataFileExistsAndIsValid(): void
    {
        $seedPath = __DIR__.'/../../../lib/Settings/vth_seed_data.json';

        $this->assertFileExists($seedPath, 'vth_seed_data.json must exist');

        $content  = file_get_contents($seedPath);
        $seedData = json_decode($content, true);

        $this->assertSame(JSON_ERROR_NONE, json_last_error(), 'vth_seed_data.json must be valid JSON');
        $this->assertIsArray($seedData);

    }//end testVthSeedDataFileExistsAndIsValid()


}//end class
