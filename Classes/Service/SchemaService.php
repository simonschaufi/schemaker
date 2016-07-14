<?php
namespace FluidTYPO3\Schemaker\Service;

/*
 * This file is part of the FluidTYPO3/Schemaker project under GPLv2 or later.
 *
 * For the full copyright and license information, please read the
 * LICENSE.md file that was distributed with this source code.
 */

use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Object\ObjectManagerInterface;
use TYPO3\CMS\Extbase\Reflection\ClassReflection;
use TYPO3\CMS\Extbase\Reflection\DocCommentParser;
use TYPO3\CMS\Extbase\Reflection\ReflectionService;
use TYPO3\CMS\Fluid\Core\ViewHelper\AbstractViewHelper;
use TYPO3\CMS\Fluid\Core\ViewHelper\ArgumentDefinition;
use TYPO3\CMS\Fluid\Fluid;

/**
 * @package Schemaker
 * @subpackage Service
 */
class SchemaService implements SingletonInterface
{

    /**
     * @var ObjectManagerInterface
     */
    protected $objectManager;

    /**
     * @var ClassReflection
     */
    protected $abstractViewHelperReflectionClass;

    /**
     * @var DocCommentParser
     */
    protected $docCommentParser;

    /**
     * @var ReflectionService
     */
    protected $reflectionService;

    /**
     * @param ObjectManagerInterface $objectManager
     * @return void
     */
    public function injectObjectManager(ObjectManagerInterface $objectManager)
    {
        $this->objectManager = $objectManager;
    }

    /**
     * @param DocCommentParser $docCommentParser
     * @return void
     */
    public function injectDocCommentParser(DocCommentParser $docCommentParser)
    {
        $this->docCommentParser = $docCommentParser;
    }

    /**
     * @param ReflectionService $reflectionService
     * @return void
     */
    public function injectReflectionService(ReflectionService $reflectionService)
    {
        $this->reflectionService = $reflectionService;
    }

    /**
     *
     */
    public function __construct()
    {
        // We want ViewHelper argument documentation
        Fluid::$debugMode = true;
        $this->abstractViewHelperReflectionClass = new ClassReflection(AbstractViewHelper::class);
    }

    /**
     * Get all class names inside this namespace and return them as array.
     *
     * @param string $combinedExtensionKey Extension Key with (possibly) leading Vendor Prefix
     * @return array
     */
    protected function getClassNamesInExtension($combinedExtensionKey)
    {
        $allViewHelperClassNames = array();
        list ($vendor, $extensionKey) = $this->getRealExtensionKeyAndVendorFromCombinedExtensionKey(
            $combinedExtensionKey
        );
        $path = ExtensionManagementUtility::extPath($extensionKey, 'Classes/ViewHelpers/');
        $filesInPath = GeneralUtility::getAllFilesAndFoldersInPath(array(), $path, 'php');
        foreach ($filesInPath as $filePathAndFilename) {
            $className = $this->getRealClassNameBasedOnExtensionAndFilenameAndExistence(
                $combinedExtensionKey,
                $filePathAndFilename
            );
            if (class_exists($className)) {
                $parent = $className;
                while ($parent = get_parent_class($parent)) {
                    array_push($allViewHelperClassNames, $className);
                }
            }
        }
        $affectedViewHelperClassNames = array();
        foreach ($allViewHelperClassNames as $viewHelperClassName) {
            $classReflection = new \ReflectionClass($viewHelperClassName);
            if ($classReflection->isAbstract() === true) {
                continue;
            }
            $affectedViewHelperClassNames[] = $viewHelperClassName;
        }
        sort($affectedViewHelperClassNames);
        return $affectedViewHelperClassNames;
    }

    /**
     * Get a tag name for a given ViewHelper class.
     * Example: For the View Helper Tx_Fluid_ViewHelpers_Form_SelectViewHelper, and the
     * namespace prefix Tx_Fluid_ViewHelpers, this method returns "form.select".
     *
     * @param string $className Class name
     * @return string
     */
    protected function getTagNameForClass($className)
    {
        $separator = false !== strpos($className, '\\') ? '\\' : '_';
        $className = substr($className, 0, -10);
        $classNameParts = explode($separator, $className);
        $startPosition = array_search('ViewHelpers', $classNameParts) + 1;
        $classNameParts = array_slice($classNameParts, $startPosition);
        $classNameParts = array_map('lcfirst', $classNameParts);
        $tagName = implode('.', $classNameParts);
        return $tagName;
    }

    /**
     * Add a child node to $parentXmlNode, and wrap the contents inside a CDATA section.
     *
     * @param \SimpleXMLElement $parentXmlNode Parent XML Node to add the child to
     * @param string $childNodeName Name of the child node
     * @param string $childNodeValue Value of the child node. Will be placed inside CDATA.
     * @return \SimpleXMLElement the new element
     */
    protected function addChildWithCData(\SimpleXMLElement $parentXmlNode, $childNodeName, $childNodeValue)
    {
        $parentDomNode = dom_import_simplexml($parentXmlNode);
        $domDocument = new \DOMDocument();
        $childNode = $domDocument->appendChild($domDocument->createElement($childNodeName));
        $childNode->appendChild($domDocument->createCDATASection($childNodeValue));
        $childNodeTarget = $parentDomNode->ownerDocument->importNode($childNode, true);
        $parentDomNode->appendChild($childNodeTarget);
        return simplexml_import_dom($childNodeTarget);
    }

    /**
     * Generate the XML Schema definition for a given namespace.
     * It will generate an XSD file for all view helpers in this namespace.
     *
     * @param string $extensionKey Namespace identifier to generate the XSD for, without leading Backslash.
     * @param string $xsdNamespace unique target namespace used in the XSD schema (for example
     *                             "http://yourdomain.org/ns/viewhelpers")
     * @param boolean $enablePhpTypes if TRUE it will generate php:types and include its associated xmlns:php
     * @param boolean $enableDocumentation If FALSE no textual documentation will be added to the xsd
     * @return string XML Schema definition
     * @throws \RuntimeException
     */
    public function generateXsd($extensionKey, $xsdNamespace, $enablePhpTypes, $enableDocumentation = true)
    {
        $classNames = $this->getClassNamesInExtension($extensionKey);
        if (count($classNames) === 0) {
            throw new \RuntimeException(sprintf('No ViewHelpers found in namespace "%s"', $extensionKey), 1330029328);
        }
        $xmlRootNode = new \SimpleXMLElement(
            '<?xml version="1.0" encoding="UTF-8"?>
			<xsd:schema xmlns:xsd="http://www.w3.org/2001/XMLSchema"' .
            (true === $enablePhpTypes ? ' xmlns:php="http://www.php.net/"' : '') .
            ' targetNamespace="' . $xsdNamespace . '"></xsd:schema>'
        );
        foreach ($classNames as $className) {
            $this->generateXmlForClassName($className, $xmlRootNode, $enablePhpTypes, $enableDocumentation);
        }
        return $xmlRootNode->asXML();
    }

    /**
     * Generate the XML Schema for a given class name.
     *
     * @param string $className Class name to generate the schema for.
     * @param \SimpleXMLElement $xmlRootNode XML root node where the xsd:element is appended.
     * @param boolean $enablePhpTypes if TRUE it will generate php:types
     * @param boolean $enableDocumentation If FALSE no textual documentation will be added to the xsd
     * @return void
     */
    protected function generateXmlForClassName(
        $className,
        \SimpleXMLElement $xmlRootNode,
        $enablePhpTypes,
        $enableDocumentation
    ) {
        $reflectionClass = new ClassReflection($className);
        if ($reflectionClass->isSubclassOf($this->abstractViewHelperReflectionClass)) {
            $tagName = $this->getTagNameForClass($className);

            $xsdElement = $xmlRootNode->addChild('xsd:element');
            $xsdElement['name'] = $tagName;
            $this->docCommentParser->parseDocComment($reflectionClass->getDocComment());
            if ($enableDocumentation) {
                $this->addDocumentation($this->docCommentParser->getDescription(), $xsdElement);
            }

            $xsdComplexType = $xsdElement->addChild('xsd:complexType');
            $xsdComplexType['mixed'] = 'true';
            $xsdSequence = $xsdComplexType->addChild('xsd:sequence');
            $xsdAny = $xsdSequence->addChild('xsd:any');
            $xsdAny['minOccurs'] = '0';
            $xsdAny['maxOccurs'] = '1';

            $this->addAttributes($className, $xsdComplexType, $enablePhpTypes, $enableDocumentation);
        }

    }

    /**
     * Add attribute descriptions to a given tag.
     * Initializes the view helper and its arguments, and then reads out the list of arguments.
     *
     * @param string $className Class name where to add the attribute descriptions
     * @param \SimpleXMLElement $xsdElement XML element to add the attributes to.
     * @param boolean $enablePhpTypes if it si TRUE it will generate php:types
     * @param boolean $enableDocumentation If FALSE no textual documentation will be added to the xsd
     * @return void
     */
    protected function addAttributes($className, \SimpleXMLElement $xsdElement, $enablePhpTypes, $enableDocumentation)
    {
        $viewHelper = $this->objectManager->get($className);
        /** @var ArgumentDefinition[] $argumentDefinitions */
        $argumentDefinitions = $viewHelper->prepareArguments();

        foreach ($argumentDefinitions as $argumentDefinition) {
            $default = $argumentDefinition->getDefaultValue();
            $type = $argumentDefinition->getType();
            $xsdAttribute = $xsdElement->addChild('xsd:attribute');
            $xsdAttribute['type'] = $this->convertPhpTypeToXsdType($type);
            $xsdAttribute['name'] = $argumentDefinition->getName();
            if (true === $enablePhpTypes) {
                $xsdAttribute['php:type'] = $type;
            }
            if ($argumentDefinition->isRequired()) {
                $xsdAttribute['use'] = 'required';
            } else {
                $xsdAttribute['use'] = 'optional';
                $xsdAttribute['default'] = var_export($default, true);
            }
            if ($enableDocumentation) {
                $this->addDocumentation($argumentDefinition->getDescription(), $xsdAttribute);
            }
        }
    }

    /**
     * @param string $type
     * @return string
     */
    protected function convertPhpTypeToXsdType($type)
    {
        switch ($type) {
            case 'integer':
                return 'xsd:integer';
            case 'float':
                return 'xsd:float';
            case 'double':
                return 'xsd:double';
            case 'boolean':
                return 'xsd:boolean';
            case 'string':
                return 'xsd:string';
            case 'array':
                return 'xsd:array';
            case 'mixed':
                return 'xsd:mixed';
            default:
                return 'xsd:anySimpleType';
        }
    }

    /**
     * Add documentation XSD to a given XML node
     *
     * @param string $documentation Documentation string to add.
     * @param \SimpleXMLElement $xsdParentNode Node to add the documentation to
     * @return void
     */
    protected function addDocumentation($documentation, \SimpleXMLElement $xsdParentNode)
    {
        $documentation = preg_replace('/[^(\x00-\x7F)]*/', '', $documentation);
        $documentation = preg_replace('/(^\ |$)/m', '', $documentation);
        $xsdAnnotation = $xsdParentNode->addChild('xsd:annotation');
        $this->addChildWithCData($xsdAnnotation, 'xsd:documentation', $documentation);
    }

    /**
     * Returns the true class name of the ViewHelper as defined
     * by the extensionKey (which may be vendorname.extensionkey)
     * and the class name. If vendorname is used, namespaced
     * classes are assumed. If no vendorname is used a namespaced
     * class is first attempted, if this does not exist the old
     * Tx_ prefixed class name is tried. If this too does not exist,
     * an Exception is thrown.
     *
     * @param string $combinedExtensionKey
     * @param string $filename
     * @return string
     * @throws \Exception
     */
    protected function getRealClassNameBasedOnExtensionAndFilenameAndExistence($combinedExtensionKey, $filename)
    {
        list ($vendor, $extensionKey) = $this->getRealExtensionKeyAndVendorFromCombinedExtensionKey(
            $combinedExtensionKey
        );
        $filename = str_replace(
            ExtensionManagementUtility::extPath($extensionKey, 'Classes/ViewHelpers/'),
            '',
            $filename
        );
        $stripped = substr($filename, 0, -4);
        if ($vendor) {
            $classNamePart = str_replace('/', '\\', $stripped);
            $className = $vendor . '\\' . ucfirst(GeneralUtility::underscoredToLowerCamelCase($extensionKey)) .
                '\\ViewHelpers\\' . $classNamePart;
        }
        return $className;
    }

    /**
     * @param string $extensionKey
     * @return array
     */
    public function getRealExtensionKeyAndVendorFromCombinedExtensionKey($extensionKey)
    {
        if (false !== strpos($extensionKey, '.')) {
            list ($vendor, $extensionKey) = explode('.', $extensionKey);
            if ('TYPO3' === $vendor) {
                $vendor = 'TYPO3\\CMS';
            }
        } else {
            $vendor = null;
        }
        $extensionKey = GeneralUtility::camelCaseToLowerCaseUnderscored($extensionKey);
        return array($vendor, $extensionKey);
    }
}
