<?php
namespace FluidTYPO3\Schemaker\Controller;

/*
 * This file is part of the FluidTYPO3/Schemaker project under GPLv2 or later.
 *
 * For the full copyright and license information, please read the
 * LICENSE.md file that was distributed with this source code.
 */

use FluidTYPO3\Schemaker\Service\SchemaService;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Fluid\Core\ViewHelper\ArgumentDefinition;

/**
 * Schema Controller
 *
 * Renders browsable FE output documenting Viewhelper arguments and usage.
 *
 * @package Schemaker
 * @subpackage Controller
 * @route NoMatch('bypass')
 */
class SchemaController extends ActionController
{

    /**
     * @var SchemaService
     */
    protected $schemaService;

    /**
     * @var CacheManager
     */
    protected $manager;

    /**
     * @var VariableFrontend
     */
    protected $cache;

    /**
     * @var array
     */
    protected $extensionKeyToNamespaceMap = array(
        'fluid' => 'f',
        'vhs' => 'v',
        'fluidwidget' => 'w',
        'fluidbackend' => 'be'
    );

    /**
     * @var array
     */
    protected $markdownBlacklistedExtensionKeys = array('fluid', 'news');

    /**
     * @param SchemaService $schemaService
     * @return void
     */
    public function injectSchemaService(SchemaService $schemaService)
    {
        $this->schemaService = $schemaService;
    }

    /**
     * @param CacheManager $manager
     * @return void
     */
    public function injectCacheManager(CacheManager $manager)
    {
        $this->manager = $manager;
    }

    /**
     * @return void
     */
    public function initializeObject()
    {
        $this->cache = $this->manager->getCache('schemaker');
    }

    /**
     * Renders browsable schema for ViewHelpers in extension selected in
     * plugin onfiguration. Has a maximum namespace depth of five levels
     * from the Tx_ExtensionKey_ViewHelpers location which should fit
     * all reasonable setups.
     *
     * @param string $extensionKey
     * @param string $version
     * @param string $p1
     * @param string $p2
     * @param string $p3
     * @param string $p4
     * @param string $p5
     * @return string
     * @route NoMatch('bypass')
     */
    public function schemaAction(
        $extensionKey = null,
        $version = null,
        $p1 = null,
        $p2 = null,
        $p3 = null,
        $p4 = null,
        $p5 = null
    ) {
        if (null === $extensionKey) {
            $extensionKey = $this->getExtensionKeySetting();
            if (null === $extensionKey) {
                $extensionKey = 'TYPO3.Fluid';
            }
            if (null === $version) {
                $version = 'master';
            }
        }
        list ($vendor, $extensionKey) = $this->schemaService->getRealExtensionKeyAndVendorFromCombinedExtensionKey(
            $extensionKey
        );
        $schemaFile = $this->getXsdStoragePathSetting() . $extensionKey . '-' . $version . '.xsd';
        $schemaFile = GeneralUtility::getFileAbsFileName($schemaFile);
        $namespaceName = str_replace('_', '', $extensionKey);
        $namespaceName = strtolower($namespaceName);
        $namespaceAlias = str_replace('_', '', $extensionKey);
        if (isset($this->extensionKeyToNamespaceMap[$extensionKey])) {
            $namespaceAlias = $this->extensionKeyToNamespaceMap[$extensionKey];
        }

        $relativeSchemaFile = substr($schemaFile, strlen(GeneralUtility::getFileAbsFileName('.')) - 1);

        $segments = array($p1, $p2, $p3, $p4, $p5);
        $segments = $this->trimPathSegments($segments);
        if (true === empty($version)) {
            $version = 'master';
        }

        $arguments = $this->segmentsToArguments($extensionKey, $version, $segments);
        $extensionName = GeneralUtility::underscoredToLowerCamelCase($extensionKey);
        $extensionName = ucfirst($extensionName);
        $extensionKeys = $this->getExtensionKeysSetting();
        $versions = $this->getVersionsByExtensionKey($extensionKey);
        $displayHeadsUp = false;
        if (isset($this->extensionKeyToNamespaceMap[$namespaceName])) {
            $namespaceName = $this->extensionKeyToNamespaceMap[$namespaceName];
        }

        list ($tree, $node, $viewHelperArguments, $docComment, $targetNamespaceUrl) = $this->getSchemaData(
            $extensionKey,
            $version,
            $segments
        );

        $gitCommand = '/usr/bin/git';
        if (false === file_exists($gitCommand)) {
            $gitCommand = '/usr/local/bin/git';
        }

        $className = implode('/', $segments);
        if (true === ExtensionManagementUtility::isLoaded($extensionKey)) {
            if (empty($className)) {
                $extensionPath = ExtensionManagementUtility::extPath($extensionKey);
                $readmeFile = $extensionPath . 'Classes/ViewHelpers/README.md';
                if (true === file_exists($readmeFile)) {
                    $readmeFile = file_get_contents($readmeFile);
                } else {
                    unset($readmeFile);
                }
            }
        }

        $variables = array(
            'action' => 'schema',
            'readmeFile' => $readmeFile,
            'name' => end($segments),
            'schemaFile' => $relativeSchemaFile,
            'keys' => array(),
            'namespaceUrl' => $targetNamespaceUrl,
            'displayHeadsUp' => $displayHeadsUp,
            'namespaceName' => $namespaceName,
            'namespaceAlias' => $namespaceAlias,
            'className' => $className,
            'ns' => $namespaceName,
            'isFile' => (null !== $node),
            'arguments' => $arguments,
            'segments' => $segments,
            'markdownBlacklisted' => in_array($extensionKey, $this->markdownBlacklistedExtensionKeys),
            'viewHelperArguments' => $viewHelperArguments,
            'docComment' => $docComment,
            'tree' => $tree,
            'version' => $version,
            'versions' => $versions,
            'extensionKey' => $extensionKey,
            'extensionKeys' => $extensionKeys,
            'extensionName' => $extensionName,
            'showJumpLinks' => true
        );
        $this->view->assignMultiple($variables);
    }

    /**
     * @param string $extensionKey
     * @param string $version
     * @param array $segments
     * @return array
     */
    protected function getSchemaData($extensionKey, $version, $segments)
    {
        if (false === ExtensionManagementUtility::isLoaded($extensionKey)) {
            return array();
        }
        $baseCacheKey = $extensionKey . $version;
        $baseCacheKey = preg_replace('/[^a-z0-9]+/i', '-', $baseCacheKey);
        $cacheKey = $baseCacheKey . implode('', $segments);
        if (true === $this->cache->has($cacheKey)) {
            return $this->cache->get($cacheKey);
        }
        $className = implode('/', $segments);
        $url = GeneralUtility::getIndpEnv('TYPO3_REQUEST_URL');
        $schemaFile = $this->getXsdStoragePathSetting() . $extensionKey . '-' . $version . '.xsd';
        $schemaFile = GeneralUtility::getFileAbsFileName($schemaFile);
        $schemaSource = shell_exec('cat ' . $schemaFile . ' | tr -cd \'[:print:]\r\n\t\'');

        $document = new \DOMDocument();
        $document->validateOnParse = true;
        $document->strictErrorChecking = true;
        $document->loadXML($schemaSource);
        if (true === $this->cache->has($baseCacheKey . 'tree')) {
            $tree = $this->cache->get($baseCacheKey . 'tree');
        } else {
            $tree = $this->buildTreeFromSchema($document);
            $this->cache->set($baseCacheKey . 'tree', $tree);
        }
        $node = $this->findCurrentViewHelperNode($document, $segments);
        $targetNamespaceUrl = $document->documentElement->getAttribute('targetNamespace');
        if (0 < count($segments)) {
            $viewHelperArguments = $this->makeArgumentDefinitions($node, $extensionKey, $className);
            $docComment = $node->getElementsByTagName('documentation')->item(0)->nodeValue;
            $additionalDocumentationFile = ExtensionManagementUtility::extPath(
                $extensionKey,
                'Documentation/Classes/ViewHelpers/' . $className . '/README.md'
            );
            $nextDiv = false;
            if (true === file_exists($additionalDocumentationFile)) {
                $alerts = array('warning', 'danger', 'success', 'info');
                $additionalDocumentation = file_get_contents($additionalDocumentationFile);
                $parts = explode('```', $additionalDocumentation);
                foreach ($parts as $index => &$part) {
                    $firstText = substr($part, 0, strpos($part, LF));
                    if (true === in_array($firstText, $alerts)) {
                        $part = '<span class="alert alert-' . $firstText . '"><span class="lead">' .
                            ucfirst($firstText) . '</span><br />' . substr($part, strlen($firstText));
                        $nextDiv = true;
                    } else {
                        if (true === $nextDiv) {
                            $part = '</span>' . $part;
                            $nextDiv = false;
                        } elseif (0 < $index) {
                            $part = '```' . $part;
                        }
                    }
                }
                $additionalDocumentation = implode('', $parts);
                $additionalDocumentation = preg_replace(
                    '/Arguments\/([a-z0-9^\s\/]+)\.md/i',
                    $url . '#argument-$1',
                    $additionalDocumentation
                );
                $additionalDocumentation = preg_replace(
                    '/(```)([a-z\s]+)(.[\`]{3})(```)/i',
                    $url . '<div class="alert alert-$1">$2</div>',
                    $additionalDocumentation
                );
                $docComment .= LF . LF . $additionalDocumentation;
            }
        }
        $data = array($tree, $node, $viewHelperArguments, $docComment, $targetNamespaceUrl);
        $this->cache->set($cacheKey, $data);
        return $data;
    }

    /**
     * @param array $segments
     * @return array
     */
    protected function trimPathSegments($segments)
    {
        foreach ($segments as $index => $value) {
            if ($value === null) {
                unset($segments[$index]);
            }
        }
        return $segments;
    }

    /**
     * @return string
     */
    protected function getExtensionKeySetting()
    {
        $fallback = null;
        if (ExtensionManagementUtility::isLoaded($GLOBALS['TSFE']->page['title'])) {
            $fallback = $GLOBALS['TSFE']->page['title'];
        }
        return true === isset($this->settings['extensionKey']) ? $this->settings['extensionKey'] : $fallback;
    }

    /**
     * @return string
     */
    protected function getXsdStoragePathSetting()
    {
        return true === isset($this->settings['xsdStoragePath']) ? $this->settings['xsdStoragePath'] : null;
    }

    /**
     * @return string
     */
    protected function getExtensionKeysSetting()
    {
        if (isset($this->settings['extensionKey'])) {
            $keys = $this->settings['extensionKey'];
        } else {
            $keys = $this->getExtensionKeysSetting();
        }
        if (false === is_array($keys)) {
            $keys = GeneralUtility::trimExplode(',', $keys);
        }
        sort($keys);
        return $keys;
    }

    /**
     * @param string $extensionKey
     * @return string
     */
    protected function getVersionsByExtensionKey($extensionKey)
    {
        $path = $this->getXsdStoragePathSetting();
        $pattern = GeneralUtility::getFileAbsFileName($path) . $extensionKey . '-*.xsd';
        $versions = array();
        foreach (glob($pattern) as $file) {
            $version = basename($file, '.xsd');
            $version = substr($version, strlen($extensionKey) + 1);
            array_push($versions, $version);
        }
        rsort($versions);
        return $versions;
    }

    /**
     * @param \DOMDocument $document
     * @return array
     */
    protected function buildTreeFromSchema(\DOMDocument $document)
    {
        $tree = array();
        $nodes = $document->getElementsByTagName('element');
        foreach ($nodes as $element) {
            $name = $element->getAttribute('name');
            $parts = explode('.', $name);
            $node =& $tree;
            while ($part = array_shift($parts)) {
                $part = ucfirst($part);
                if (0 === count($parts)) {
                    $part .= 'ViewHelper';
                    $node[$part] = $part;
                } elseif (false === is_array($node[$part]) && 0 < count($parts)) {
                    $node[$part] = array();
                }
                $node = &$node[$part];
            }
        }
        return $this->sortTree($tree);
    }

    /**
     * @param mixed $tree
     * @return mixed
     */
    protected function sortTree($tree)
    {
        if (false === is_array($tree)) {
            return $tree;
        }
        $files = array();
        $folders = array();
        foreach ($tree as $key => $item) {
            if (true === is_array($item)) {
                $folders[$key] = $this->sortTree($item);
            } else {
                $files[$key] = $item;
            }
        }
        $tree = $folders + $files;
        return $tree;
    }

    /**
     * @param \DOMDocument $document
     * @param array $segments
     * @return \DOMElement
     */
    protected function findCurrentViewHelperNode(\DOMDocument $document, $segments)
    {
        $segments = array_map('lcfirst', $segments);
        $name = substr(implode('.', $segments), 0, -10);
        $elements = $document->getElementsByTagName('element');
        foreach ($elements as $element) {
            if ($name === $element->getAttribute('name')) {
                return $element;
            }
        }
        return null;
    }

    /**
     * @param \DOMElement $node
     * @param string $extensionKey
     * @param string $className
     * @return ArgumentDefinition[]
     */
    protected function makeArgumentDefinitions(\DOMElement $node, $extensionKey, $className)
    {
        $arguments = $node->getElementsByTagName('attribute');
        $definitions = array();
        $url = GeneralUtility::getIndpEnv('TYPO3_REQUEST_URL');
        foreach ($arguments as $attribute) {
            $complexType = 'xsd:complexType' === $attribute->parentNode->tagName ? $attribute->parentNode : null;
            $name = $attribute->getAttribute('name');
            $type = array_pop(explode(':', $attribute->getAttribute('type')));
            if ($attribute->getAttribute('php:type')) {
                $type = $attribute->getAttribute('php:type');
            }
            $default = $attribute->getAttribute('default');
            $description = $attribute->getElementsByTagName('documentation')->item(0)->nodeValue;
            $additionalDocumentationFile = ExtensionManagementUtility::extPath(
                $extensionKey,
                'Documentation/Classes/ViewHelpers/' . $className . '/Arguments/' . $name . '.md'
            );
            if (true === file_exists($additionalDocumentationFile)) {
                $additionalDocumentation = file_get_contents($additionalDocumentationFile);
                $pattern = '/([a-z0-9^\s\/]+)\.md/i';
                $additionalDocumentation = preg_replace($pattern, $url . '#argument-$1', $additionalDocumentation);
                $description .= LF . LF . $additionalDocumentation;
            }
            $required = (boolean) (
                $complexType->getElementsByTagName('any')->item(0)->getAttribute('minOccurs') ||
                'required' === $attribute->getAttribute('use')
            );
            $definition = new ArgumentDefinition($name, $type, $description, $required, $default);
            $definitions[$name] = $definition;
        }
        return $definitions;
    }

    /**
     * @param string $extensionKey
     * @param string $version
     * @param array $segments
     * @return array
     */
    protected function segmentsToArguments($extensionKey, $version, $segments)
    {
        $arguments = array(
            'extensionKey' => $extensionKey,
            'version' => $version
        );
        foreach ($segments as $index => $segment) {
            $arguments['p' . ($index + 1)] = $segment;
        }
        return $arguments;
    }
}
