<?php

/**
 * @copyright  Marko Cupic 2020 <m.cupic@gmx.ch>
 * @author     Marko Cupic
 * @package    Contao Bundle Creator
 * @license    MIT
 * @see        https://github.com/markocupic/contao-bundle-creator
 *
 */

declare(strict_types=1);

namespace Markocupic\ContaoBundleCreatorBundle\ExtensionGenerator;

use Contao\Controller;
use Contao\File;
use Contao\Files;
use Contao\Folder;
use Contao\StringUtil;
use Markocupic\ContaoBundleCreatorBundle\Model\ContaoBundleCreatorModel;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Class ExtensionGenerator
 * @package Markocupic\ContaoBundleCreatorBundle\ExtensionGenerator
 */
class ExtensionGenerator
{
    /** @var SessionInterface */
    private $session;

    /** @var string|string */
    private $projectDir;

    /** @var string */
    const SAMPLE_DIR = 'vendor/markocupic/contao-bundle-creator-bundle/src/Samples/sample-repository';

    /**
     * @var string
     */
    const STR_INFO_FLASH_TYPE = 'contao.BE.info';

    /**
     * @var string
     */
    const STR_ERROR_FLASH_TYPE = 'contao.BE.error';

    /** @var ContaoBundleCreatorModel */
    protected $model;

    /**
     * ExtensionGenerator constructor.
     * @param SessionInterface $session
     * @param string $projectDir
     */
    public function __construct(SessionInterface $session, string $projectDir)
    {
        $this->session = $session;
        $this->projectDir = $projectDir;
    }

    /**
     * @param ContaoBundleCreatorModel $model
     */
    public function run(ContaoBundleCreatorModel $model): void
    {
        $this->model = $model;

        if ($this->bundleExists() && !$this->model->overwriteexisting)
        {
            $this->addErrorFlashMessage('An extension with the same name already exists. Please set the "override extension flag".');
            return;
        }

        $this->addInfoFlashMessage(sprintf('Started generating "%s/%s" bundle.', $this->model->vendorname, $this->model->repositoryname));

        // Generate the folders
        $this->generateFolders();

        // Generate the composer.json file
        $this->generateComposerJsonFile();

        // Generate the bundle class
        $this->generateBundleClass();

        // Generate the Contao Manager Plugin class
        $this->generateContaoManagerPluginClass();

        // Config files, assets, etc.
        $this->copyFiles();

        // Generate dca table
        if ($this->model->addDcaTable && $this->model->dcatable != '')
        {
            $this->generateDcaTable();
        }

        // Generate frontend module
        if ($this->model->addFrontendModule)
        {
            $this->generateFrontendModule();
        }

        $zipSource = sprintf('vendor/%s/%s', $this->model->vendorname, $this->model->repositoryname);
        $zipTarget = sprintf('system/tmp/%s.zip', $this->model->repositoryname);
        if ($this->zipData($zipSource, $zipTarget))
        {
            $this->session->set('CONTAO-BUNDLE-CREATOR-LAST-ZIP', $zipTarget);
        }
    }

    /**
     * Check if extension with same name already exists
     * @return bool
     */
    protected function bundleExists(): bool
    {
        return is_dir($this->projectDir . '/vendor/' . $this->model->vendorname . '/' . $this->model->repositoryname);
    }

    /**
     * Generate the plugiin folder structure
     * @throws \Exception
     */
    protected function generateFolders(): void
    {
        $arrFolders = [];

        $arrFolders[] = sprintf('vendor/%s/%s/src/ContaoManager', $this->model->vendorname, $this->model->repositoryname);
        $arrFolders[] = sprintf('vendor/%s/%s/src/Resources/config', $this->model->vendorname, $this->model->repositoryname);
        $arrFolders[] = sprintf('vendor/%s/%s/src/Resources/public', $this->model->vendorname, $this->model->repositoryname);
        $arrFolders[] = sprintf('vendor/%s/%s/src/Resources/contao/config', $this->model->vendorname, $this->model->repositoryname);
        $arrFolders[] = sprintf('vendor/%s/%s/src/Resources/contao/dca', $this->model->vendorname, $this->model->repositoryname);
        $arrFolders[] = sprintf('vendor/%s/%s/src/Resources/contao/languages/en', $this->model->vendorname, $this->model->repositoryname);
        $arrFolders[] = sprintf('vendor/%s/%s/src/Resources/contao/templates', $this->model->vendorname, $this->model->repositoryname);
        $arrFolders[] = sprintf('vendor/%s/%s/src/EventListener/ContaoHooks', $this->model->vendorname, $this->model->repositoryname);

        foreach ($arrFolders as $strFolder)
        {
            new Folder($strFolder);
        }

        // Add message
        $this->addInfoFlashMessage(sprintf('Generating folder structure in  "vendor/%s/%s".', $this->model->vendorname, $this->model->repositoryname));
    }

    /**
     * Generate the composer.json file
     */
    protected function generateComposerJsonFile(): void
    {
        $source = self::SAMPLE_DIR . '/composer.json';

        /** @var File $sourceFile */
        $sourceFile = new File($source);
        $content = $sourceFile->getContent();

        $content = str_replace('#vendorname#', $this->model->vendorname, $content);
        $content = str_replace('#repositoryname#', $this->model->repositoryname, $content);
        $content = str_replace('#composerdescription#', $this->model->composerdescription, $content);
        $content = str_replace('#license#', $this->model->license, $content);
        $content = str_replace('#authorname#', $this->model->authorname, $content);
        $content = str_replace('#authoremail#', $this->model->authoremail, $content);
        $content = str_replace('#authorwebsite#', $this->model->authorwebsite, $content);
        $content = str_replace('#toplevelnamespace#', $this->namespaceify($this->model->vendorname), $content);
        $content = str_replace('#sublevelnamespace#', $this->namespaceify($this->model->repositoryname), $content);
        // Add/remove version keyword
        if ($this->model->composerpackageversion == '')
        {
            $content = preg_replace('/(.*)version(.*)#composerpackageversion#(.*),[\r\n|\n]/', '', $content);
        }
        else
        {
            $content = preg_replace('/#composerpackageversion#/', $this->model->composerpackageversion, $content);
        }

        $target = sprintf('vendor/%s/%s/composer.json', $this->model->vendorname, $this->model->repositoryname);

        /** @var File $objTarget */
        $objTarget = new File($target);
        $objTarget->truncate();
        $objTarget->append($content);
        $objTarget->close();

        // Add message
        $this->addInfoFlashMessage('Generating composer.json file.');
    }

    /**
     * Generate the bundle class
     */
    protected function generateBundleClass(): void
    {
        $source = self::SAMPLE_DIR . '/src/BundleFile.php';

        /** @var File $sourceFile */
        $sourceFile = new File($source);
        $content = $sourceFile->getContent();

        $content = str_replace('#phpdoc#', $this->getPhpDoc(), $content);
        // Top-level namespace
        $content = str_replace('#toplevelnamespace#', $this->namespaceify($this->model->vendorname), $content);
        // Sub-level namespace
        $content = str_replace('#sublevelnamespace#', $this->namespaceify($this->model->repositoryname), $content);

        $target = sprintf('vendor/%s/%s/src/%s%s.php', $this->model->vendorname, $this->model->repositoryname, $this->namespaceify($this->model->vendorname), $this->namespaceify($this->model->repositoryname));

        /** @var File $objTarget */
        $objTarget = new File($target);
        $objTarget->truncate();
        $objTarget->append($content);
        $objTarget->close();

        // Add message
        $this->addInfoFlashMessage('Generating bundle class.');
    }

    /**
     * Generate the Contao Manager plugin class
     */
    protected function generateContaoManagerPluginClass(): void
    {
        $source = self::SAMPLE_DIR . '/src/ContaoManager/Plugin.php';

        /** @var File $sourceFile */
        $sourceFile = new File($source);
        $content = $sourceFile->getContent();

        $content = str_replace('#phpdoc#', $this->getPhpDoc(), $content);
        // Top-level namespace
        $content = str_replace('#toplevelnamespace#', $this->namespaceify($this->model->vendorname), $content);
        // Sub-level namespace
        $content = str_replace('#sublevelnamespace#', $this->namespaceify($this->model->repositoryname), $content);

        $target = sprintf('vendor/%s/%s/src/ContaoManager/Plugin.php', $this->model->vendorname, $this->model->repositoryname);

        /** @var File $objTarget */
        $objTarget = new File($target);
        $objTarget->truncate();
        $objTarget->append($content);
        $objTarget->close();

        // Add message
        $this->addInfoFlashMessage('Generating Contao Manager Plugin class.');
    }

    /**
     * Generate the dca table and
     * the corresponding language file
     */
    protected function generateDcaTable(): void
    {
        $arrFiles = [
            // dca table
            self::SAMPLE_DIR . '/src/Resources/contao/dca/tl_sample_table.php'          => sprintf('vendor/%s/%s/src/Resources/contao/dca/%s.php', $this->model->vendorname, $this->model->repositoryname, $this->model->dcatable),
            // lang file
            self::SAMPLE_DIR . '/src/Resources/contao/languages/en/tl_sample_table.php' => sprintf('vendor/%s/%s/src/Resources/contao/languages/en/%s.php', $this->model->vendorname, $this->model->repositoryname, $this->model->dcatable),
        ];

        foreach ($arrFiles as $source => $target)
        {
            /** @var File $sourceFile */
            $sourceFile = new File($source);
            $content = $sourceFile->getContent();

            $content = str_replace('#phpdoc#', $this->getPhpDoc(), $content);
            $content = str_replace('#dcatable#', $this->model->dcatable, $content);
            /** @var File $objTarget */
            $objTarget = new File($target);
            $objTarget->truncate();
            $objTarget->append($content);
            $objTarget->close();

            // Show message in the backend
            $msg = sprintf('Created file "%s".', $target);
            $this->addInfoFlashMessage($msg);
        }

        // Append backend module string to contao/config.php
        $target = sprintf('vendor/%s/%s/src/Resources/contao/config/config.php', $this->model->vendorname, $this->model->repositoryname);
        $objFile = new File($target);
        $objFile->append($this->getContentFromPartialFile('contao_config_be_mod.txt'));
        $objFile->close();

        // Append backend module string to contao/languages/en/modules.php
        $target = sprintf('vendor/%s/%s/src/Resources/contao/languages/en/modules.php', $this->model->vendorname, $this->model->repositoryname);
        $objFile = new File($target);
        $objFile->append($this->getContentFromPartialFile('contao_lang_en_be_modules.txt'));
        $objFile->close();
    }

    /**
     * Generate frontend module
     */
    protected function generateFrontendModule(): void
    {
        // Create folders
        $arrFolders = [];
        $arrFolders[] = sprintf('vendor/%s/%s/src/Controller/FrontendModule', $this->model->vendorname, $this->model->repositoryname);
        $arrFolders[] = sprintf('vendor/%s/%s/src/Resources/contao/templates', $this->model->vendorname, $this->model->repositoryname);

        foreach ($arrFolders as $strFolder)
        {
            new Folder($strFolder);
        }

        $objFile = new File(self::SAMPLE_DIR . '/src/Controller/FrontendModule/SampleModule.php');
        $content = $objFile->getContent();

        // Add phpdoc
        $content = str_replace('#phpdoc#', $this->getPhpDoc(), $content);

        // Top-level namespace
        $content = str_replace('#toplevelnamespace#', $this->namespaceify($this->model->vendorname), $content);

        // Sub-level namespace
        $content = str_replace('#sublevelnamespace#', $this->namespaceify($this->model->repositoryname), $content);

        // Frontend module name
        $strFrontendModuleName = $this->toCamelcase($this->model->frontendmodulename);
        $this->model->frontendmodulename = $strFrontendModuleName;
        $this->model->save();

        // Frontend module category
        $strFrontendModuleCategory = $this->toCamelcase($this->model->frontendmodulecategory);
        $this->model->frontendmodulecategory = $strFrontendModuleCategory;
        $this->model->save();

        // Template name
        $strFrontenModuleTemplateName = $this->getTemplateNameFromFrontendModuleName($strFrontendModuleName);

        // Frontend module classname
        $strFrontendModuleClassname = 'Module' . ucfirst($strFrontendModuleName);
        $content = str_replace('#frontendmoduleclassname#', $strFrontendModuleClassname, $content);

        // Add new frontend class to filesystem
        $strNewFile = sprintf('vendor/%s/%s/src/Controller/FrontendModule/%s.php', $this->model->vendorname, $this->model->repositoryname, $strFrontendModuleClassname);
        $objNewFile = new File($strNewFile);
        $objNewFile->truncate();
        $objNewFile->append($content);
        $objNewFile->close();

        // Add tl_module.php
        $target = sprintf('vendor/%s/%s/src/Resources/contao/dca/tl_module.php', $this->model->vendorname, $this->model->repositoryname);
        $objNewFile = new File($target);
        $objNewFile->truncate();

        $objSource = new File(self::SAMPLE_DIR . '/src/Resources/contao/dca/tl_module.php');
        $content = $objSource->getContent();

        // Add phpdoc
        $content = str_replace('#phpdoc#', $this->getPhpDoc(), $content);
        $objNewFile->append($content);

        // Add module palette to tl_module.php
        $content = str_replace('#frontendmodulename#', $strFrontendModuleName, $this->getContentFromPartialFile('contao_tl_module.txt'));
        $objNewFile->append($content);
        $objNewFile->close();

        // Add tags to config/services.yml
        // Add tl_module.php
        $target = sprintf('vendor/%s/%s/src/Resources/config/services.yml', $this->model->vendorname, $this->model->repositoryname);
        $objNewFile = new File($target);

        // Add module palette to tl_module.php
        $content = $this->getContentFromPartialFile('config_services_frontend_modules.txt');
        $content = str_replace('#toplevelnamespace#', $this->namespaceify($this->model->vendorname), $content);
        $content = str_replace('#sublevelnamespace#', $this->namespaceify($this->model->repositoryname), $content);
        $content = str_replace('#frontendmoduleclassname#', $strFrontendModuleClassname, $content);
        $content = str_replace('#frontendmodulecategory#', $strFrontendModuleCategory, $content);
        $content = str_replace('#frontendmoduletemplate#', $strFrontenModuleTemplateName, $content);
        $content = str_replace('#frontendmodulename#', $strFrontendModuleName, $content);

        $objNewFile->append($content);
        $objNewFile->close();

        // Add frontend module template
        $source = self::SAMPLE_DIR . '/src/Resources/contao/templates/mod_sample.html5';
        $target = sprintf('vendor/%s/%s/src/Resources/contao/templates/%s.html5', $this->model->vendorname, $this->model->repositoryname, $strFrontenModuleTemplateName);
        Files::getInstance()->copy($source, $target);

        // Append language array to contao/languages/en/modules.php
        $target = sprintf('vendor/%s/%s/src/Resources/contao/languages/en/modules.php', $this->model->vendorname, $this->model->repositoryname);
        $objFile = new File($target);
        $objFile->append($this->getContentFromPartialFile('contao_lang_en_fe_modules.txt'));
        $objFile->close();

        // Add message
        $this->addInfoFlashMessage(sprintf('Created frontend module "%s".', $strFrontendModuleClassname));
    }

    /**
     * Generate config files
     */
    protected function copyFiles(): void
    {
        // Config files
        $arrFiles = ['listener.yml', 'parameters.yml', 'services.yml'];
        foreach ($arrFiles as $file)
        {
            $source = sprintf('%s/src/Resources/config/%s', self::SAMPLE_DIR, $file);
            $target = sprintf('vendor/%s/%s/src/Resources/config/%s', $this->model->vendorname, $this->model->repositoryname, $file);

            Files::getInstance()->copy($source, $target);

            // Add message
            $this->addInfoFlashMessage(sprintf('Created file "%s".', $target));
        }

        // Contao config/config.php && languages/en/modules.php
        $arrFiles = [
            // Contao config.php
            sprintf('%s/src/Resources/contao/config/config.php', self::SAMPLE_DIR)        => sprintf('vendor/%s/%s/src/Resources/contao/config/config.php', $this->model->vendorname, $this->model->repositoryname),
            // Contao languages/en/modules.php
            sprintf('%s/src/Resources/contao/languages/en/modules.php', self::SAMPLE_DIR) => sprintf('vendor/%s/%s/src/Resources/contao/languages/en/modules.php', $this->model->vendorname, $this->model->repositoryname),

        ];

        foreach ($arrFiles as $source => $target)
        {
            Files::getInstance()->copy($source, $target);

            // Add phpdoc
            $objFile = new File($target);
            $content = $objFile->getContent();
            $content = str_replace('#phpdoc#', $this->getPhpDoc(), $content);
            $objFile->truncate();
            $objFile->append($content);
            $objFile->close();

            // Add message
            $this->addInfoFlashMessage(sprintf('Created file "%s".', $target));
        }

        // Assets in src/Resources/public
        $arrFiles = ['logo.png'];
        foreach ($arrFiles as $file)
        {
            $source = sprintf('%s/src/Resources/public/%s', self::SAMPLE_DIR, $file);
            $target = sprintf('vendor/%s/%s/src/Resources/public/%s', $this->model->vendorname, $this->model->repositoryname, $file);

            Files::getInstance()->copy($source, $target);

            // Add message
            $this->addInfoFlashMessage(sprintf('Created file "%s".', $target));
        }

        // README.md
        $arrFiles = ['README.md'];
        foreach ($arrFiles as $file)
        {
            $source = sprintf('%s/%s', self::SAMPLE_DIR, $file);
            $target = sprintf('vendor/%s/%s/%s', $this->model->vendorname, $this->model->repositoryname, $file);

            Files::getInstance()->copy($source, $target);

            // Add message
            $this->addInfoFlashMessage(sprintf('Created file "%s".', $target));
        }
    }

    /**
     * Get the php doc from the partial file
     * @return string
     * @throws \Exception
     */
    protected function getPhpDoc(): string
    {
        $source = self::SAMPLE_DIR . '/partials/phpdoc.txt';

        /** @var File $sourceFile */
        $sourceFile = new File($source);
        $content = $sourceFile->getContent();

        $content = str_replace('#bundlename#', $this->model->bundlename, $content);
        $content = str_replace('#year#', date('Y'), $content);
        $content = str_replace('#license#', $this->model->license, $content);
        $content = str_replace('#authorname#', $this->model->authorname, $content);
        $content = str_replace('#authoremail#', $this->model->authoremail, $content);
        $content = str_replace('#authorwebsite#', $this->model->authorwebsite, $content);
        $content = str_replace('#vendorname#', $this->model->vendorname, $content);
        $content = str_replace('#repositoryname#', $this->model->repositoryname, $content);

        return $content;
    }

    /**
     * Replace tags and return content from partials
     * @return string
     * @throws \Exception
     */
    protected function getContentFromPartialFile(string $strFilename): string
    {
        $source = self::SAMPLE_DIR . '/partials/' . $strFilename;

        /** @var File $sourceFile */
        $sourceFile = new File($source);
        $content = $sourceFile->getContent();

        // Handle dca table
        $content = str_replace('#dcatable#', $this->model->dcatable, $content);
        $content = str_replace('#bemodule#', str_replace('tl_', '', $this->model->dcatable), $content);

        // Handle frontend module
        $content = str_replace('#frontendmodulename#', $this->model->frontendmodulename, $content);
        $arrLabel = StringUtil::deserialize($this->model->frontendmoduletrans, true);
        $content = str_replace('#frontendmoduletrans_0#', $arrLabel[0], $content);
        $content = str_replace('#frontendmoduletrans_1#', $arrLabel[1], $content);
        if (strlen((string) $this->model->frontendmodulecategorytrans))
        {
            $content = str_replace('#frontendmodulecategorytrans#', $this->model->frontendmodulecategorytrans, $content);
            $content = str_replace('#frontendmodulecategory#', $this->model->frontendmodulecategory, $content);
            $content = preg_replace('/(#fmdcatstart#|#fmdcatend#)/', '', $content);
        }
        else
        {
            // Remove obolete frontend module category label
            $content = preg_replace('/([\r\n|\n])#fmdcatstart#(.*)#fmdcatend#([\r\n|\n])/', '', $content);
        }

        return $content;
    }

    /**
     * Convert string to namespace
     * "my_custom name-space" will become "MyCustomNameSpace"
     *
     * @param string $strName
     * @return string
     */
    private function namespaceify(string $strName): string
    {
        $strName = str_replace('_', '-', $strName);
        $strName = str_replace(' ', '-', $strName);
        $arrNamespace = explode('-', $strName);
        $arrNamespace = array_filter($arrNamespace, 'strlen');
        $arrNamespace = array_map('strtolower', $arrNamespace);
        $arrNamespace = array_map('ucfirst', $arrNamespace);
        $strBundleNamespace = implode('', $arrNamespace);

        return $strBundleNamespace;
    }

    /**
     * @param string $msg
     */
    private function addInfoFlashMessage(string $msg): void
    {
        $this->addFlashMessage($msg, self::STR_INFO_FLASH_TYPE);
    }

    /**
     * @param string $msg
     */
    private function addErrorFlashMessage(string $msg): void
    {
        $this->addFlashMessage($msg, self::STR_ERROR_FLASH_TYPE);
    }

    /**
     * @param string $msg
     * @param string $type
     */
    private function addFlashMessage(string $msg, string $type): void
    {
        // Get flash bag
        $flashBag = $this->session->getFlashBag();
        $arrFlash = [];
        if ($flashBag->has($type))
        {
            $arrFlash = $flashBag->get($type);
        }

        $arrFlash[] = $msg;

        $flashBag->set($type, $arrFlash);
    }

    /**
     * Converts string into camelcase string
     * MyNew_super NASA Module => myNewSuperNasaModule
     * @param string $str
     * @return string
     */
    protected function toCamelcase(string $str): string
    {
        $str = str_replace('_', ' ', $str);
        $str = str_replace('-', ' ', $str);

        $arrStr = explode(' ', $str);
        $arrStr = array_map('ucfirst', $arrStr);

        $str = implode('', $arrStr);

        // Make camelcase
        $arrStr = preg_split(
            '/(^[^A-Z]+|[A-Z][^A-Z]+)/',
            $str,
            -1, /* no limit for replacement count */
            PREG_SPLIT_NO_EMPTY /*don't return empty elements*/
            | PREG_SPLIT_DELIM_CAPTURE /*don't strip anything from output array*/
        );

        $str = implode(' ', $arrStr);
        $arrStr = array_map('strtolower', $arrStr);
        $arrStr = array_map('ucfirst', $arrStr);

        $str = implode('', $arrStr);
        $str = lcfirst($str);

        return $str;
    }

    /**
     * @param string $str
     * @param string $strPrefix
     * @return string
     */
    protected function getTemplateNameFromFrontendModuleName(string $str, string $strPrefix = 'mod_', string $strExtension = ''): string
    {
        $arrStr = preg_split(
            '/(^[^A-Z]+|[A-Z][^A-Z]+)/',
            $str,
            -1, /* no limit for replacement count */
            PREG_SPLIT_NO_EMPTY /*don't return empty elements*/
            | PREG_SPLIT_DELIM_CAPTURE /*don't strip anything from output array*/
        );
        $arrStr = array_map('strtolower', $arrStr);
        $arrStr = array_filter($arrStr, 'strlen');
        $str = implode('_', $arrStr);

        return $strPrefix . $str . $strExtension;
    }

    /**
     * @param string $source
     * @param string $destination
     * @return bool
     */
    protected function zipData(string $source, string $destination): bool
    {
        if (extension_loaded('zip'))
        {
            $source = $this->projectDir . '/' . $source;
            $destination = $this->projectDir . '/' . $destination;

            if (file_exists($source))
            {
                $zip = new \ZipArchive();
                if ($zip->open($destination, \ZipArchive::CREATE))
                {
                    $source = realpath($source);
                    if (is_dir($source))
                    {
                        $iterator = new \RecursiveDirectoryIterator($source);
                        // skip dot files while iterating
                        //$iterator->setFlags(\RecursiveDirectoryIterator::SKIP_DOTS);
                        $files = new \RecursiveIteratorIterator($iterator, \RecursiveIteratorIterator::SELF_FIRST);
                        foreach ($files as $objSplFileInfo)
                        {
                            $file = $objSplFileInfo->getRealPath();

                            if (is_dir($file))
                            {
                                $zip->addEmptyDir(str_replace($source . '/', '', $file . '/'));
                            }
                            else
                            {
                                if (is_file($file))
                                {
                                    $zip->addFromString(str_replace($source . '/', '', $file), file_get_contents($file));
                                }
                            }
                        }
                    }
                    else
                    {
                        if (is_file($source))
                        {
                            $zip->addFromString(basename($source), file_get_contents($source));
                        }
                    }
                }
                return $zip->close();
            }

            return false;
        }
    }

}
