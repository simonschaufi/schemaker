<?php
namespace FluidTYPO3\Schemaker\Command;

/*
 * This file is part of the FluidTYPO3/Schemaker project under GPLv2 or later.
 *
 * For the full copyright and license information, please read the
 * LICENSE.md file that was distributed with this source code.
 */

use FluidTYPO3\Schemaker\Service\SchemaService;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\CommandController;

/**
 * Command controller for Fluid documentation rendering
 *
 * @package Schemaker
 * @subpackage Command
 */
class SchemaCommandController extends CommandController
{

    /**
     * @var SchemaService
     */
    protected $schemaService;

    /**
     * @param SchemaService $schemaService
     * @return void
     */
    public function injectSchemaService(SchemaService $schemaService)
    {
        $this->schemaService = $schemaService;
    }

    /**
     * Generate Fluid ViewHelper XSD Schema
     *
     * Generates Schema documentation (XSD) for your ViewHelpers, preparing the
     * file to be placed online and used by any XSD-aware editor.
     * After creating the XSD file, reference it in your IDE and import the namespace
     * in your Fluid template by adding the xmlns:* attribute(s):
     * <html xmlns="http://www.w3.org/1999/xhtml" xmlns:f="http://typo3.org/ns/TYPO3/Fluid/ViewHelpers" ...>
     *
     * @param string $extensionKey Extension key of generated extension. If namespaces are desired, the extension key
     *                             should be in the format VendorName.ExtensionName (e.g. UpperCamelCase,
     *                             dot-containing, no underscores)
     * @param string $xsdNamespace Unique target namespace used in the XSD schema (for example
     *                             "http://yourdomain.org/ns/viewhelpers"). Defaults to
     *                             "http://typo3.org/ns/<php namespace>".
     * @param boolean $enablePhpTypes if TRUE it will generate php:types and include its associated xmlns:php
     * @param boolean $enableDocumentation If FALSE no textual documentation will be added to the xsd
     * @return void
     */
    public function generateCommand(
        $extensionKey,
        $xsdNamespace = null,
        $enablePhpTypes = false,
        $enableDocumentation = true
    ) {
        try {
            $enablePhpTypes = (boolean) $enablePhpTypes;
            $schema = $this->generate($extensionKey, $xsdNamespace, $enablePhpTypes, $enableDocumentation);
            $this->output($schema);
        } catch (\Exception $exception) {
            $this->outputLine('An error occured while trying to generate the XSD schema for "' . $extensionKey . '":');
            $this->outputLine('%s', array($exception->getMessage()));
            $this->quit(1);
        }
    }

    /**
     * Generates scheduled XSD file output
     *
     * Uses either $extensionKey or $spool. If $extensionKey is not set,
     * $spool (file path) is expected to exist. If $spool is not set,
     * $extensionKey's XSD will be regenerated on every execution.
     * $spool is a JSON-encoded file containing a simple object of
     * extension keys and a TRUE vale. Example file content:
     * {"vhs": true, "flux": true}. This spool file can be written by
     * github hook listener scripts such as the one included with this
     * extension under Resources/Private/Scripts. The included hook
     * listener is extremely simple - intentionally so - in order to
     * minimise the time it takes for services to call the hook.
     *
     * @param string $extensionKey Optional extension key; if not specified, $spool is read and extension keys gathered
     *                             from here. If specified, schemaker will only generate XSD for this extension key.
     * @param string $spool Optional (but default set) spool file which contains an {"extkey": true} object to indicate
     *                      that this extension's XSD must be regenerated.
     * @param string $outputDir The target folder for XSD files. The resulting files will be written here, inside a
     *                          folder named according to extension key.
     * @param boolean $gitMode If TRUE, the extension's folder is considered a git repository and Schemaker will
     *                         attempt to check out branches for each of the tags contained on the master branch and
     *                         generate an XSD for each. The resulting files will be named with versions in the filename
     *                         except for the current master which bears the "raw" XSD schema name.
     * @return void
     */
    public function scheduledCommand(
        $extensionKey = null,
        $spool = 'typo3temp/schemaker-spool.json',
        $outputDir = 'fileadmin/',
        $gitMode = false
    ) {
        $spool = GeneralUtility::getFileAbsFileName($spool);
        if (false === empty($extensionKey)) {
            $this->generateAndWriteWithOrWithoutGit($extensionKey, $outputDir, $gitMode);
        }
        if (true === file_exists($spool)) {
            $spoolData = json_decode(file_get_contents($spool), JSON_OBJECT_AS_ARRAY);
            foreach ($spoolData as $spooledExtensionKey => $unused) {
                $this->generateAndWriteWithOrWithoutGit($spooledExtensionKey, $outputDir, $gitMode);
            }
            unlink($spool);
        }
    }

    /**
     * @param string $baseName
     * @param array $schemas
     * @return void
     */
    protected function writeSchemas($baseName, $schemas)
    {
        foreach ($schemas as $name => $schema) {
            $filename = $baseName . '-' . $name . '.xsd';
            if (0 !== strpos($filename, '/')) {
                $filename = GeneralUtility::getFileAbsFileName($filename);
            }
            GeneralUtility::writeFile($filename, $schema);
        }
    }

    /**
     * Wrapper function for generating and writing schemas by extension key, with the
     * generating behavior governed by the $gitMode flag.
     *
     * @param string $extensionKey
     * @param string $outputDir
     * @param boolean $gitMode
     * @return void
     */
    protected function generateAndWriteWithOrWithoutGit($extensionKey, $outputDir, $gitMode)
    {
        // extension key is provided: generate using current state or rolling git checkouts as requested by $gitMode
        if (true === $gitMode) {
            $schemas = $this->generateWithGit($extensionKey);
        } else {
            $schemas = array(
                $extensionKey => $this->generate($extensionKey)
            );
        }
        $this->writeSchemas($outputDir . $extensionKey, $schemas);
    }

    /**
     * @param string $extensionKey
     * @return array
     */
    protected function generateWithGit($extensionKey)
    {
        $tags = array();
        $code = 0;
        $path = ExtensionManagementUtility::extPath($extensionKey);
        $command = 'cd ' . $path . ' && git fetch --all && git tag';
        exec($command, $tags, $code);
        exec(
            sprintf(
                'cd %s && git checkout master && git reset --hard && git clean -qfdx && git pull origin master --tags',
                $path
            )
        );
        $schemas = array(
            'master' => $this->generate($extensionKey)
        );
        exec($command, $tags, $code);
        exec(
            sprintf(
                'cd %s && git checkout development && git reset --hard && ' .
                'git clean -qfdx && git pull origin development --tags',
                $path
            )
        );
        $schemas['development'] = $this->generate($extensionKey);
        $output = array();
        foreach ($tags as $tag) {
            exec('cd ' . $path . ' && git checkout -b ' . $tag . ' ' . $tag, $output, $code);
            if (0 !== $code) {
                $this->output(
                    sprintf(
                        'Could not check out tag %s from git repository %s, skipping this tag.',
                        $tags,
                        $extensionKey
                    )
                );
                continue;
            }
            $schemas[$tag] = $this->generate($extensionKey);
            exec('cd ' . $path . ' && git checkout master -f');
            exec('cd ' . $path . ' && git branch -D ' . $tag);
        }
        exec($command);
        return $schemas;
    }

    /**
     * @param string $extensionKey
     * @param string $xsdNamespace
     * @param boolean $enablePhpTypes
     * @return string
     */
    protected function generate(
        $extensionKey,
        $xsdNamespace = null,
        $enablePhpTypes = false,
        $enableDocumentation = true
    ) {
        if ($xsdNamespace === null) {
            if (false !== strpos($extensionKey, '.')) {
                $xsdExtensionKeySegment = str_replace('.', '/', $extensionKey);
            } else {
                $xsdExtensionKeySegment = $extensionKey;
            }
            $xsdNamespace = sprintf('http://typo3.org/ns/%s/ViewHelpers', $xsdExtensionKeySegment);
        }
        $xsdSchema = $this->schemaService->generateXsd(
            $extensionKey,
            $xsdNamespace,
            $enablePhpTypes,
            $enableDocumentation
        );
        if (function_exists('tidy_repair_string') === true) {
            $xsdSchema = tidy_repair_string($xsdSchema, array(
                'output-xml' => true,
                'input-xml' => true
            ));
        }
        return $xsdSchema;
    }
}
