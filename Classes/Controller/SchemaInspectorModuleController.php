<?php
namespace FluidTYPO3\Schemaker\Controller;

/*
 * This file is part of the FluidTYPO3/Schemaker project under GPLv2 or later.
 *
 * For the full copyright and license information, please read the
 * LICENSE.md file that was distributed with this source code.
 */

use FluidTYPO3\Schemaker\Service\SchemaService;
use TYPO3\CMS\Backend\Template\DocumentTemplate;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Extbase\Reflection\ClassReflection;
use TYPO3\CMS\Extbase\Reflection\DocCommentParser;
use TYPO3\CMS\Extbase\Reflection\ObjectAccess;

/**
 * Class SchemaInspectorModuleController
 */
class SchemaInspectorModuleController extends SchemaController
{

    /**
     * @var array
     */
    protected $blackistedSystemExtensionKeys = array(
        'core',
        'install',
        'belog',
        'sys_note',
        'cshmanual',
        'extensionmanager',
        'scheduler',
        'reports',
        'backend',
        'form'
    );

    /**
     * @var array
     */
    protected $systemExtensionKeyMap = array();

    /**
     * @var SchemaService
     */
    protected $schemaService;

    /**
     * @var DocCommentParser
     */
    protected $docCommentParser;

    /**
     * @param SchemaService $schemaService
     * @return void
     */
    public function injectSchemaService(SchemaService $schemaService)
    {
        $this->schemaService = $schemaService;
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
     * @return void
     */
    protected function initializeAction()
    {
        $typoScript = GeneralUtility::removeDotsFromTS($this->configurationManager
            ->getConfiguration(ConfigurationManagerInterface::CONFIGURATION_TYPE_FULL_TYPOSCRIPT));
        $this->blackistedSystemExtensionKeys = GeneralUtility::trimExplode(
            ',',
            ObjectAccess::getPropertyPath($typoScript, 'module.tx_schemaker.settings.blacklistedExtensionKeys')
        );
        $this->systemExtensionKeyMap = (array) ObjectAccess::getPropertyPath(
            $typoScript,
            'module.tx_schemaker.settings.extensionKeyClassNamespaceMap'
        );
    }

    /**
     * @param string $extensionKey
     * @param string $p1
     * @param string $p2
     * @param string $p3
     * @param string $p4
     * @param string $p5
     * @return string
     * @return void
     */
    public function indexAction($extensionKey = null, $p1 = null, $p2 = null, $p3 = null, $p4 = null, $p5 = null)
    {
        if (!$extensionKey && !$p1 && !$p2 && !$p3 && !$p4 && !$p5) {
            list ($extensionKey, $p1, $p2, $p3, $p4, $p5) = array_pad(
                (array) $this->getBackendUserAuthentication()->getModuleData('tools_schema_arguments'),
                6,
                null
            );
        } else {
            $this->getBackendUserAuthentication()->pushModuleData('tools_schema_arguments', array(
                $extensionKey, $p1, $p2, $p3, $p4, $p5
            ));
        }
        if (null === $extensionKey) {
            $extensionKey = 'TYPO3.Fluid';
        }
        list ($vendor, $legacyExtensionKey) = $this->schemaService
            ->getRealExtensionKeyAndVendorFromCombinedExtensionKey($extensionKey);
        $version = ExtensionManagementUtility::getExtensionVersion($extensionKey);

        $namespaceName = str_replace('_', '', $legacyExtensionKey);
        $namespaceName = strtolower($namespaceName);
        $namespaceAlias = str_replace('_', '', $extensionKey);
        if (isset($this->extensionKeyToNamespaceMap[$legacyExtensionKey])) {
            $namespaceAlias = $this->extensionKeyToNamespaceMap[$legacyExtensionKey];
        }

        $segments = array($p1, $p2, $p3, $p4, $p5);
        $segments = $this->trimPathSegments($segments);

        $arguments = $this->segmentsToArguments($extensionKey, '', $segments);
        $extensionName = GeneralUtility::underscoredToUpperCamelCase($legacyExtensionKey);
        $extensionKeys = $this->detectExtensionsContainingViewHelpers();
        ;
        $displayHeadsUp = false;
        if (isset($this->extensionKeyToNamespaceMap[$namespaceName])) {
            $namespaceName = $this->extensionKeyToNamespaceMap[$namespaceName];
        }

        $tree = $this->buildTreeFromClassPath(
            ExtensionManagementUtility::extPath($legacyExtensionKey, 'Classes/ViewHelpers/')
        );
        $viewHelperArguments = array();
        $node = null;
        $docComment = '';

        $className = implode('/', $segments);
        if (true === ExtensionManagementUtility::isLoaded($legacyExtensionKey)) {
            $extensionPath = ExtensionManagementUtility::extPath($legacyExtensionKey);
            if (!empty($className)) {
                $className = $vendor . '\\' . $extensionName . '\\ViewHelpers\\' . str_replace('/', '\\', $className);
                $viewHelperArguments = $this->objectManager->get($className)->prepareArguments();
                $reflection = new ClassReflection($className);
                $docComment = $reflection->getDocComment();
                $this->docCommentParser->parseDocComment($docComment);
                $docComment = $this->docCommentParser->getDescription();
                $docComment = trim($docComment, "/ \n");
            } else {
                $readmeFile = $extensionPath . 'Classes/ViewHelpers/README.md';
                if (true === file_exists($readmeFile)) {
                    $readmeFile = file_get_contents($readmeFile);
                } else {
                    unset($readmeFile);
                }
            }
        }

        $variables = array(
            'view' => 'Index',
            'action' => 'index',
            'readmeFile' => $readmeFile,
            'history' => $history,
            'name' => end($segments),
            'schemaFile' => $relativeSchemaFile,
            'keys' => array(),
            'namespaceUrl' => $targetNamespaceUrl,
            'displayHeadsUp' => $displayHeadsUp,
            'namespaceName' => $namespaceName,
            'namespaceAlias' => $namespaceAlias,
            'className' => $className,
            'ns' => $namespaceName,
            'isFile' => class_exists($className),
            'arguments' => $arguments,
            'segments' => $segments,
            'markdownBlacklisted' => in_array($extensionKey, $this->markdownBlacklistedExtensionKeys),
            'viewHelperArguments' => $viewHelperArguments,
            'docComment' => $docComment,
            'tree' => $tree,
            'version' => $version,
            'extensionKey' => $extensionKey,
            'extensionKeys' => $extensionKeys,
            'extensionName' => $extensionName
        );
        $this->view->assignMultiple($variables);
    }

    /**
     * @return array
     */
    protected function detectExtensionsContainingViewHelpers()
    {
        $detected = array();
        foreach (ExtensionManagementUtility::getLoadedExtensionListArray() as $extensionKey) {
            if (!in_array($extensionKey, $this->blackistedSystemExtensionKeys)) {
                if (is_dir(GeneralUtility::getFileAbsFileName('EXT:' . $extensionKey . '/Classes/ViewHelpers'))) {
                    if (isset($this->systemExtensionKeyMap[$extensionKey])) {
                        $detected[] = $this->systemExtensionKeyMap[$extensionKey];
                    } else {
                        $detected[] = $extensionKey;
                    }
                }
            }
        }
        return $detected;
    }

    /**
     * @param string $classPath
     * @return array
     */
    protected function buildTreeFromClassPath($classPath)
    {
        $tree = array();
        $classPathLength = strlen($classPath);
        $files = GeneralUtility::getAllFilesAndFoldersInPath(array(), $classPath, 'php');
        foreach ($files as $index => $filePathAndFilename) {
            if (substr(pathinfo($filePathAndFilename, PATHINFO_FILENAME), 0, 8) === 'Abstract') {
                continue;
            }
            if (substr(pathinfo($filePathAndFilename, PATHINFO_FILENAME), -9) === 'Interface') {
                continue;
            }
            $identifier = substr($filePathAndFilename, $classPathLength, -4);
            $node = &$tree;
            $segments = explode('/', $identifier);
            $last = array_pop($segments);
            foreach ($segments as $segment) {
                if (!isset($node[$segment])) {
                    $node[$segment] = array();
                }
                $node =& $node[$segment];
            }
            $node[$last] = $last;
        }
        return $this->sortTree($tree);
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
            'version' => ExtensionManagementUtility::getExtensionVersion($extensionKey)
        );
        foreach ($segments as $index => $segment) {
            $arguments['p' . ($index + 1)] = $segment;
        }
        return $arguments;
    }

    /**
     * @return DocumentTemplate
     */
    protected function getModuleTemplate()
    {
        return $GLOBALS['TBE_TEMPLATE'];
    }

    /**
     * @return BackendUserAuthentication
     */
    protected function getBackendUserAuthentication()
    {
        return $GLOBALS['BE_USER'];
    }
}
