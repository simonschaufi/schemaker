<?php
namespace FluidTYPO3\Schemaker\Tests\Unit\Service;

/*
 * This file is part of the FluidTYPO3/Schemaker project under GPLv2 or later.
 *
 * For the full copyright and license information, please read the
 * LICENSE.md file that was distributed with this source code.
 */

use FluidTYPO3\Schemaker\Service\SchemaService;
use TYPO3\CMS\Core\Tests\UnitTestCase;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Class SchemaServiceTest
 */
class SchemaServiceTest extends UnitTestCase
{

    /**
     * @test
     */
    public function testPerformsInjections()
    {
        $instance = GeneralUtility::makeInstance('TYPO3\\CMS\\Extbase\\Object\\ObjectManager')
            ->get('FluidTYPO3\\Schemaker\\Service\\SchemaService');
        $this->assertAttributeInstanceOf('TYPO3\\CMS\\Extbase\\Object\\ObjectManagerInterface', 'objectManager', $instance);
        $this->assertAttributeInstanceOf('TYPO3\\CMS\\Extbase\\Reflection\\DocCommentParser', 'docCommentParser', $instance);
        $this->assertAttributeInstanceOf('TYPO3\\CMS\\Extbase\\Reflection\\ReflectionService', 'reflectionService', $instance);
    }

    /**
     * @test
     */
    public function testGetClassNamesInExtension()
    {
        $instance = new SchemaService();
        $names = $this->callInaccessibleMethod($instance, 'getClassNamesInExtension', 'FluidTYPO3.Vhs');
        $this->assertNotEmpty($names);
    }

    /**
     * @param string $class
     * @param string $expected
     * @test
     * @dataProvider getTagNameForClassTestValues
     */
    public function testGetTagNameForClass($class, $expected)
    {
        /** @var SchemaService $instance */
        $instance = new SchemaService();
        $result = $this->callInaccessibleMethod($instance, 'getTagNameForClass', $class);
        $this->assertEquals($expected, $result);
    }

    /**
     * @return array
     */
    public function getTagNameForClassTestValues()
    {
        return array(
            array('FluidTYPO3\\Vhs\\ViewHelpers\\Content\\RenderViewHelper', 'content.render'),
            array('FluidTYPO3\\Vhs\\ViewHelpers\\Content\\GetViewHelper', 'content.get'),
            array('TYPO3\\CMS\\Fluid\\ViewHelpers\\IfViewHelper', 'if'),
            array('TYPO3\\CMS\\Fluid\\ViewHelpers\\Format\\HtmlentitiesViewHelper', 'format.htmlentities'),
        );
    }

    /**
     * @test
     */
    public function testGenerateXsdCreatesDocument()
    {
        /** @var SchemaService $service */
        $service = GeneralUtility::makeInstance('TYPO3\\CMS\\Extbase\\Object\\ObjectManager')
            ->get('FluidTYPO3\\Schemaker\\Service\\SchemaService');
        $schema = $service->generateXsd('FluidTYPO3.Vhs', 'test', true);
        $this->assertNotEmpty($schema);
    }

    /**
     * @test
     */
    public function testGenerateXsdErrorsWhenNoViewHelpersInExtension()
    {
        /** @var SchemaService $service */
        $service = GeneralUtility::makeInstance('TYPO3\\CMS\\Extbase\\Object\\ObjectManager')
            ->get('FluidTYPO3\\Schemaker\\Service\\SchemaService');
        $this->setExpectedException('RuntimeException');
        $service->generateXsd('FluidTYPO3.Schemaker', 'test', true);
    }

    /**
     * @dataProvider getConvertPhpTypeToXsdTypeTestValues
     * @param string $input
     * @param string $expected
     */
    public function testConvertPhpTypeToXsdType($input, $expected)
    {
        $instance = new SchemaService();
        $result = $this->callInaccessibleMethod($instance, 'convertPhpTypeToXsdType', $input);
        $this->assertEquals($expected, $result);
    }

    /**
     * @return array
     */
    public function getConvertPhpTypeToXsdTypeTestValues()
    {
        return array(
            array('', 'xsd:anySimpleType'),
            array('integer', 'xsd:integer'),
            array('float', 'xsd:float'),
            array('double', 'xsd:double'),
            array('boolean', 'xsd:boolean'),
            array('string', 'xsd:string'),
            array('array', 'xsd:array'),
            array('mixed', 'xsd:mixed'),
        );
    }

    /**
     * @dataProvider getRealExtensionKeyAndVendorFromCombinedExtensionKeyTestValues
     * @param string $input
     * @param string $expected
     */
    public function testGetRealExtensionKeyAndVendorFromCombinedExtensionKey($input, $expected)
    {
        $instance = new SchemaService();
        $result = $instance->getRealExtensionKeyAndVendorFromCombinedExtensionKey($input);
        $this->assertEquals($expected, $result);
    }

    /**
     * @return array
     */
    public function getRealExtensionKeyAndVendorFromCombinedExtensionKeyTestValues()
    {
        return array(
            array('vhs', array(null, 'vhs')),
            array('FluidTYPO3.Vhs', array('FluidTYPO3', 'vhs')),
            array('fluid', array(null, 'fluid')),
            array('TYPO3.Fluid', array('TYPO3\\CMS', 'fluid')),
        );
    }
}
