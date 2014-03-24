<?php

/**
 * Contao Open Source CMS
 *
 * @copyright  MEN AT WORK 2014
 * @package    syncCto
 * @license    GNU/LGPL 
 * @filesource
 */

/**
 * Class for file operations
 */
class SyncCtoFiles extends Backend
{
    ////////////////////////////////////////////////////////////////////////////
    // Vars
    ////////////////////////////////////////////////////////////////////////////

    // Singelten pattern
    protected static $instance         = null;
    // Vars
    protected $strSuffixZipName = "File-Backup.zip";
    protected $strTimestampFormat;
    protected $intMaxMemoryUsage;
    protected $intMaxExecutionTime;
    protected $strRDIFlags;
    // Lists
    protected $arrRootFolderList;
    // Objects 
    protected $objSyncCtoHelper;
    protected $objFiles;
    
    ////////////////////////////////////////////////////////////////////////////
    // Core
    ////////////////////////////////////////////////////////////////////////////

    /**
     * Constructor
     */
    protected function __construct()
    {
        parent::__construct();

        // Init
        $this->objSyncCtoHelper   = SyncCtoHelper::getInstance();
        $this->objFiles           = Files::getInstance();
        $this->strTimestampFormat = str_replace(array(':', ' '), array('', '_'), $GLOBALS['TL_CONFIG']['datimFormat']);

        // Load blacklists and whitelists
        $this->arrRootFolderList  = $this->objSyncCtoHelper->getWhitelistFolder();

        // Get memory limit
        $this->intMaxMemoryUsage = SyncCtoModuleClient::parseSize(ini_get('memory_limit'));
        $this->intMaxMemoryUsage = $this->intMaxMemoryUsage / 100 * 30;

        // Get execution limit
        $this->intMaxExecutionTime = intval(ini_get('max_execution_time'));
        $this->intMaxExecutionTime = intval($this->intMaxExecutionTime / 100 * 25);
        
        // Flags for file scanning.
        $this->strRDIFlags = RecursiveDirectoryIterator::FOLLOW_SYMLINKS | RecursiveDirectoryIterator::SKIP_DOTS | RecursiveDirectoryIterator::UNIX_PATHS;
    }

    /**
     * @return SyncCtoFiles 
     */
    public function __clone()
    {
        return self::$instance;
    }

    /**
     * @return SyncCtoFiles 
     */
    public static function getInstance()
    {
        if (self::$instance == null)
        {
            self::$instance = new SyncCtoFiles();
        }

        return self::$instance;
    }
    
    ////////////////////////////////////////////////////////////////////////////
    // Getter / Setter - Functions
    ////////////////////////////////////////////////////////////////////////////

    /**
     * Return zipname
     * 
     * @return string
     */
    public function getSuffixZipName()
    {
        return $this->strSuffixZipName;
    }

    /**
     * Set zipname
     * 
     * @param string $strSuffixZipName 
     */
    public function setSuffixZipName($strSuffixZipName)
    {
        $this->strSuffixZipName = $strSuffixZipName;
    }

    /**
     * Get timestamp format
     * 
     * @return string 
     */
    public function getTimestampFormat()
    {
        return $this->strTimestampFormat;
    }

    /**
     * Set timestamp format
     * 
     * @param type $strTimestampFormat 
     */
    public function setTimestampFormat($strTimestampFormat)
    {
        $this->strTimestampFormat = $strTimestampFormat;
    }

    /**
     * Check if the given path is in blacklist of folders
     * 
     * @param string $strPath
     * @return boolean 
     */
    public function isInBlackFolder($strPath)
    {
        $strPath = $this->objSyncCtoHelper->standardizePath($strPath);

        foreach ($this->objSyncCtoHelper->getPreparedBlacklistFolder() as $value)
        {
            // Search with preg for values
            if (preg_match("/^" . $value . "/i", $strPath . '/') != 0)
            {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the given path is in blacklist of files
     * 
     * @param string $strPath
     * @return boolean 
     */
    public function isInBlackFile($strPath)
    {
        $strPath = $this->objSyncCtoHelper->standardizePath($strPath);


        foreach ($this->objSyncCtoHelper->getPreparedBlacklistFiles() as $value)
        {
            // Check if the preg starts with a TL_ROOT
            if (preg_match("/^TL_ROOT/i", $value))
            {
                // Remove the TL_ROOT
                $value = preg_replace("/TL_ROOT\\\\\//i", "", $value);

                // Search with preg for values            
                if (preg_match("/^" . $value . "$/i", $strPath) != 0)
                {
                    return true;
                }
            }
            else
            {
                // Search with preg for values            
                if (preg_match("/" . $value . "$/i", $strPath) != 0)
                {
                    return true;
                }
            }
        }

        return false;
    }

    ////////////////////////////////////////////////////////////////////////////
    // DBAFS - Support for contao 3 and the tl_files
    ////////////////////////////////////////////////////////////////////////////

    /**
     * Get the file information from a file and add these to the file array.
     *
     * @param array $arrFileList A list with file information.
     *
     * @param bool  $blnAutoAdd  Flag if the system should add unknown files to the DBAFS.
     *
     * @return array Return the file list with dbafs information.
     */
    public function getDbafsInformationFor($arrFileList, $blnAutoAdd = true)
    {
        // Check if we have array.
        if (!is_array($arrFileList) || count($arrFileList) == 0)
        {
            return $arrFileList;
        }

        // ...and now the support for the uuid und dbafs system
        foreach ($arrFileList as $key => $value)
        {
            // Check if we have this file in the filesystem.
            if(!file_exists(TL_ROOT . PATH_SEPARATOR . $value['path']))
            {
                $arrFileList[$key]['tl_files'] = null;
            }

            // Add the file to the import array.
            $arrImport['files'][$key] = $value;

            // Get the information from the tl_files.
            $objModel = \FilesModel::findByPath($value['path']);

            // If the file is not in the dbafs and
            if ($objModel == null && $blnAutoAdd)
            {
                $objModel                      = \Dbafs::addResource($value['path']);
                $arrModelData                  = $objModel->row();
                $arrModelData['uuid']          = \String::binToUuid($arrModelData['uuid']);
                $arrFileList[$key]['tl_files'] = $arrModelData;
            }
            // If empty and auto add disable retrun null for this file.
            elseif ($objModel == null && !$blnAutoAdd)
            {
                $arrFileList[$key]['tl_files'] = null;
            }
            // If we have data add it to the file
            else
            {
                $arrModelData                  = $objModel->row();
                $arrModelData['uuid']          = \String::binToUuid($arrModelData['uuid']);
                $arrFileList[$key]['tl_files'] = $arrModelData;
            }
        }

        return $arrFileList;
    }

    ////////////////////////////////////////////////////////////////////////////
    // Generate function
    ////////////////////////////////////////////////////////////////////////////

    /**
     * Generate a array with files and some meta informations.
     * 
     * @param boolean $booCore Run for root folders/files
     * @param boolean $booFiles Run for tl_files/files
     * 
     * @return array A array with meta informations.
     */
    protected function generateChecksumFiles($booCore = false, $booFiles = false)
    {
        $arrChecksum = array();

        // Check each file
        foreach ($this->getFileList($booCore, $booFiles) as $objFile)
        {
            // Skipe if we have a dir.
            if($objFile->isDir())
            {
                continue;
            }

            // Get fileinformation.
            $strRelativePath = preg_replace('?' . $this->objSyncCtoHelper->getPreparedTlRoot() . '/?', '', $objFile->getPathname(), 1);
            $strFullPath     = $objFile->getPathname();
            $intSize         = $objFile->getSize();
            $intLasModified  = $objFile->getMTime();

            // Get metadata.
            if ($intSize < 0 && $intSize != 0)
            {
                $arrChecksum[md5($strRelativePath)] = array(
                    "path"         => $strRelativePath,
                    "checksum"     => 0,
                    "size"         => -1,
                    "state"        => SyncCtoEnum::FILESTATE_BOMBASTIC_BIG,
                    "transmission" => SyncCtoEnum::FILETRANS_WAITING,
                    "lastModified" => $intLasModified
                );
            }
            else if ($intSize >= $GLOBALS['SYC_SIZE']['limit_ignore'])
            {
                $arrChecksum[md5($strRelativePath)] = array(
                    "path"         => $strRelativePath,
                    "checksum"     => 0,
                    "size"         => $intSize,
                    "state"        => SyncCtoEnum::FILESTATE_BOMBASTIC_BIG,
                    "transmission" => SyncCtoEnum::FILETRANS_WAITING,
                    "lastModified" => $intLasModified
                );
            }
            else if ($intSize >= $GLOBALS['SYC_SIZE']['limit'])
            {
                $arrChecksum[md5($strRelativePath)] = array(
                    "path"         => $strRelativePath,
                    "checksum"     => md5_file($strFullPath),
                    "size"         => $intSize,
                    "state"        => SyncCtoEnum::FILESTATE_TOO_BIG,
                    "transmission" => SyncCtoEnum::FILETRANS_WAITING,
                    "lastModified" => $intLasModified
                );
            }
            else
            {
                $arrChecksum[md5($strRelativePath)] = array(
                    "path"         => $strRelativePath,
                    "checksum"     => md5_file($strFullPath),
                    "size"         => $intSize,
                    "state"        => SyncCtoEnum::FILESTATE_FILE,
                    "transmission" => SyncCtoEnum::FILETRANS_WAITING,
                    "lastModified" => $intLasModified
                );
            }
        }

        return $arrChecksum;
    }
    
    /**
     * Generate a array with folders and some meta informations.
     * 
     * @param boolean $booCore Run for root folders/files
     * @param boolean $booFiles Run for tl_files/files
     * 
     * @return array A array with meta informations.
     */
    protected function generateChecksumFolders($booCore = false, $booFiles = false)
    {
        $arrChecksum   = array();

        // Check each file
        foreach ($this->getFolderList($booCore, $booFiles) as $objFolder)
        {
            $strRelativePath = preg_replace('?' . $this->objSyncCtoHelper->getPreparedTlRoot() . '/?', '', $objFolder->getPathname(), 1);
            
            $arrChecksum[md5($strRelativePath)] = array(
                "path"         => $strRelativePath,
                "checksum"     => 0,
                "size"         => 0,
                "state"        => SyncCtoEnum::FILESTATE_FOLDER,
                "transmission" => SyncCtoEnum::FILETRANS_WAITING,
            );
        }

        return $arrChecksum;
    }

    /**
     * Create a xml file with all files
     * 
     * @param string $strXMLFile Full filepath
     * @param $intInformations $intSize The size
     * @param boolean $booCore Core scan
     * @param boolean $booFiles Files scan
     * @return boolean 
     */
    public function generateChecksumFileAsXML($strXMLFile, $booCore = false, $booFiles = false, $intInformations = SyncCtoEnum::FILEINFORMATION_SMALL)
    {
        $strXMLFile = $this->objSyncCtoHelper->standardizePath($strXMLFile);

        $objFileXML = new \File($strXMLFile, false);
        $objFileXML->blnSyncDb = false;
        $objFileXML->delete();
        $objFileXML->close();

        $objFileIterator = $this->getFileList($booCore, $booFiles);

        if (!$objFileIterator->valid())
        {
            return false;
        }

        // Create XML File
        $objXml = new XMLWriter();
        $objXml->openMemory();
        $objXml->setIndent(true);
        $objXml->setIndentString("\t");

        // XML Start
        $objXml->startDocument('1.0', 'UTF-8');
        $objXml->startElement('fileslist');

        // Write meta (header)
        $objXml->startElement('metatags');
        $objXml->writeElement('version', $GLOBALS['SYC_VERSION']);
        $objXml->writeElement('create_unix', time());
        $objXml->writeElement('create_date', date('Y-m-d', time()));
        $objXml->writeElement('create_time', date('H:i', time()));
        $objXml->endElement(); // End metatags

        $objXml->startElement('files');

        $i = 0;
        foreach ($objFileIterator as $objFile)
        {
            // Skipe if we have a dir.
            if($objFile->isDir())
            {
                continue;
            }
            
            // Get fileinformation.
            $strRelativePath = preg_replace('?' . $this->objSyncCtoHelper->getPreparedTlRoot() . '/?', '', $objFile->getPathname(), 1);
            $intSize         = $objFile->getSize();

            if ($intSize < 0 && $intSize != 0)
            {
                continue;
            }
            else
            {
                if ($intInformations == SyncCtoEnum::FILEINFORMATION_SMALL)
                {
                    $objXml->startElement('file');
                    $objXml->writeAttribute("id", md5($strRelativePath));
                    $objXml->writeAttribute("ai", $i);
                    $objXml->text($strRelativePath);
                    $objXml->endElement(); // End file
                }
                else if ($intInformations == SyncCtoEnum::FILEINFORMATION_BIG)
                {
                    $objXml->startElement('file');
                    $objXml->writeAttribute("id", md5($strRelativePath));
                    $objXml->writeAttribute("ai", $i);
                    $objXml->text($strRelativePath);
                    $objXml->endElement(); // End file
                }
            }

            if (($i % 10) == 0)
            {
                $objFileXML->append($objXml->flush(true), "");
                $objFileXML->close();
            }

            $i++;
        }

        $objXml->endElement(); // End files
        $objXml->endElement(); // End fileslist

        $objFileXML->append($objXml->flush(true), "");
        $objFileXML->close();

        if(file_exists(TL_ROOT . '/' . $strXMLFile))
        {
            return true;
        }
        else
        {
            return false;
        }
    }

    ////////////////////////////////////////////////////////////////////////////
    // Run functions
    ////////////////////////////////////////////////////////////////////////////
    
    /**
     * Create a checksum list from contao core folders
     * 
     * @CtoCommunication Enable
     * @return array 
     */
    public function runChecksumFolderCore()
    {
        return $this->generateChecksumFolders(true, false);
    }

    /**
     * Create a checksum list from contao folders
     * 
     * @CtoCommunication Enable
     * @return array 
     */
    public function runChecksumFolderFiles()
    {
        return $this->generateChecksumFolders(false, true);
    }

    /**
     * Create a checksum list from contao core
     * 
     * @CtoCommunication Enable
     * @return array 
     */
    public function runChecksumCore()
    {
        return $this->generateChecksumFiles(true, false);
    }

    /**
     * Create a checksum list from contao files
     * 
     * @CtoCommunication Enable
     * @return array 
     */
    public function runChecksumFiles()
    {
        return $this->generateChecksumFiles(false, true);
    }

    /**
     * Check a filelist with the current filesystem
     * 
     * @param array $arrChecksumList
     * @return array 
     */
    public function runCecksumCompare($arrChecksumList)
    {
        $arrFileList = array();

        foreach ($arrChecksumList as $key => $value)
        {
            if ($value['state'] == SyncCtoEnum::FILESTATE_BOMBASTIC_BIG)
            {
                $arrFileList[$key]        = $arrChecksumList[$key];
                $arrFileList[$key]["raw"] = "file bombastic";
            }
            else if (file_exists(TL_ROOT . "/" . $value['path']))
            {
                if (md5_file(TL_ROOT . "/" . $value['path']) == $value['checksum'])
                {
                    // Do nothing
                }
                else
                {
                    if ($value['state'] == SyncCtoEnum::FILESTATE_TOO_BIG)
                    {
                        $arrFileList[$key]          = $arrChecksumList[$key];
                        $arrFileList[$key]["state"] = SyncCtoEnum::FILESTATE_TOO_BIG_NEED;
                    }
                    else
                    {
                        $arrFileList[$key]          = $arrChecksumList[$key];
                        $arrFileList[$key]["state"] = SyncCtoEnum::FILESTATE_NEED;
                    }
                }
            }
            else
            {
                if ($value['state'] == SyncCtoEnum::FILESTATE_TOO_BIG)
                {
                    $arrFileList[$key]          = $arrChecksumList[$key];
                    $arrFileList[$key]["state"] = SyncCtoEnum::FILESTATE_TOO_BIG_MISSING;
                }
                else
                {
                    $arrFileList[$key]          = $arrChecksumList[$key];
                    $arrFileList[$key]["state"] = SyncCtoEnum::FILESTATE_MISSING;
                }
            }
        }

        return $arrFileList;
    }

    /**
     * Search for deleteable folders.
     * 
     * @param array $arrChecksumList List with all folders.
     * 
     * @return string
     */
    public function searchDeleteFolders($arrChecksumList)
    {
        $arrFolderList = array();

        foreach ($arrChecksumList as $keyItem => $valueItem)
        {
            if (!file_exists(TL_ROOT . "/" . $valueItem["path"]))
            {
                $arrFolderList[$keyItem]          = $valueItem;
                $arrFolderList[$keyItem]["state"] = SyncCtoEnum::FILESTATE_FOLDER_DELETE;
                $arrFolderList[$keyItem]["css"]   = "deleted";
            }
        }
        
        return $arrFolderList;
    }

    /**
     * Check for deleted files with a filelist from an other system
     * 
     * @param array $arrFilelist 
     */
    public function checkDeleteFiles($arrFilelist)
    {
        $arrReturn = array();

        foreach ($arrFilelist as $keyItem => $valueItem)
        {
            if (!file_exists(TL_ROOT . "/" . $valueItem["path"]))
            {
                $arrReturn[$keyItem]          = $valueItem;
                $arrReturn[$keyItem]["state"] = SyncCtoEnum::FILESTATE_DELETE;
                $arrReturn[$keyItem]["css"]   = "deleted";
            }
        }

        return $arrReturn;
    }

    ////////////////////////////////////////////////////////////////////////////
    // Dump Functions
    ////////////////////////////////////////////////////////////////////////////

    /**
     * Make a backup from a filelist
     * 
     * @CtoCommunication Enable
     * @param string $strZip
     * @param array $arrFileList
     * @return string Filename 
     */
    public function runDump($strZip = "", $booCore = false, $arrFiles = array())
    {
        if ($strZip == "")
        {
            $strFilename = date($this->strTimestampFormat) . "_" . $this->strSuffixZipName;
        }
        else
        {
            $strFilename = standardize(str_replace(array(" "), array("_"), preg_replace("/\.zip\z/i", "", $strZip))) . ".zip";
        }
        
        // Replace special chars from the filename..
        $strFilename = str_replace(array_keys($GLOBALS['SYC_CONFIG']['folder_file_replacement']), array_values($GLOBALS['SYC_CONFIG']['folder_file_replacement']), $strFilename);
        
        $strPath = $this->objSyncCtoHelper->standardizePath($GLOBALS['SYC_PATH']['file'], $strFilename);

        $objZipArchive = new ZipArchiveCto();

        if (($mixError = $objZipArchive->open($strPath, ZipArchiveCto::CREATE)) !== true)
        {
            throw new Exception($GLOBALS['TL_LANG']['MSC']['error'] . ": " . $objZipArchive->getErrorDescription($mixError));
        }

        $arrFileSkipped = array();

        // Run backup for the core files.
        if ($booCore)
        {
            foreach ($this->getFileList(true, false) as $objFile)
            {
                // Skipe all folders.
                if($objFile->isDir())
                {
                    continue;
                }
                
                // Build path witout tl_root.
                $strFile = preg_replace('?' . $this->objSyncCtoHelper->getPreparedTlRoot() . '/?', '', $objFile->getPathname(), 1);
                
                // Add file to zip.
                if ($objZipArchive->addFile($strFile, $strFile) == false)
                {
                    $arrFileSkipped[] = $strFile;
                }
            }
        }

        // Run backup for tl_files/files
        foreach ((array) $arrFiles as $file)
        {
            // Scann folders.
            if (file_exists(TL_ROOT . '/' . $file) && is_dir(TL_ROOT . '/' . $file))
            {
                // Scann.
                $objDirectoryIt     = new RecursiveDirectoryIterator(TL_ROOT . '/' . $this->objSyncCtoHelper->standardizePath($file), $this->strRDIFlags);
                $objFilterIt     = new SyncCtoFilterIteratorBase($objDirectoryIt);
                $objRecursiverIt = new RecursiveIteratorIterator($objFilterIt, RecursiveIteratorIterator::SELF_FIRST);

                $this->getFileListFromFolders();

                foreach ($objRecursiverIt as $objFile)
                {
                    // Skipe all folders.
                    if ($objFile->isDir())
                    {
                        continue;
                    }

                    // Build path witout tl_root.
                    $strFile = preg_replace('?' . $this->objSyncCtoHelper->getPreparedTlRoot() . '/?', '', $objFile->getPathname(), 1);

                    // Add file to zip.
                    if ($objZipArchive->addFile($strFile, $strFile) == false)
                    {
                        $arrFileSkipped[] = $strFile;
                    }
                }
            }
            // Add files.
            else if (file_exists(TL_ROOT . '/' . $file))
            {
                if ($objZipArchive->addFile($file, $file) == false)
                {
                    $arrFileSkipped[] = $file;
                }
            }
        }

        // Close zip, write data.
        $objZipArchive->close();

        return array("name"    => $strFilename, "skipped" => $arrFileSkipped);
    }

    /**
     * Make a incremental backup from a filelist
     * 
     * @param string $srtXMLFilelist  Path to XML filelist
     * @param stirng $strZipFolder Path to the folder
     * @param string $strZipFile Name of zipfile. If empty a filename will be create.
     * @return array array{"folder"=>[string],"file"=>[string],"fullpath"=>[string],"xml"=>[string],"done"=>[boolean]}
     * @throws Exception 
     */
    public function runIncrementalDump($srtXMLFilelist, $strZipFolder, $strZipFile = null, $intMaxFilesPerRun = 5)
    {
        $floatTimeStart = microtime(true);

        // Check if filelist exists
        if (!file_exists(TL_ROOT . "/" . $srtXMLFilelist))
        {
            throw new Exception("File not found: " + $srtXMLFilelist);
        }

        // Create, check zip name
        if ($strZipFile == null || $strZipFile == "")
        {
            $strZipFile = date($this->strTimestampFormat) . "_" . $this->strSuffixZipName;
        }
        else
        {
            $strZipFile = str_replace(array(" "), array("_"), preg_replace("/\.zip\z/i", "", $strZipFile)) . ".zip";
        }

        // Build Path
        $strZipFolder = $this->objSyncCtoHelper->standardizePath($strZipFolder);
        $strZipPath   = $this->objSyncCtoHelper->standardizePath($strZipFolder, $strZipFile);

        // Open XML Reader
        $objXml = new DOMDocument("1.0", "UTF-8");
        $objXml->load(TL_ROOT . "/" . $srtXMLFilelist);

        // Check if we have some files
        if ($objXml->getElementsByTagName("file")->length == 0)
        {
            return array(
                "folder"   => $strZipFolder,
                "file"     => $strZipFile,
                "fullpath" => $strZipPath,
                "xml"      => $srtXMLFilelist,
                "done"     => true
            );
        }

        // Open ZipArchive
        $objZipArchive = new ZipArchiveCto();
        if (($mixError      = $objZipArchive->open($strZipPath, ZipArchiveCto::CREATE)) !== true)
        {
            throw new Exception($GLOBALS['TL_LANG']['MSC']['error'] . ": " . $objZipArchive->getErrorDescription($mixError));
        }

        // Get all files
        $objFilesList = $objXml->getElementsByTagName("file");
        $objNodeFiles = $objXml->getElementsByTagName("files")->item(0);
        $arrFinished  = array();
        $intRuns = 0;

        // Run through each
        foreach ($objFilesList as $file)
        {
            // Check if file exists
            if (file_exists(TL_ROOT . "/" . $file->nodeValue))
            {
                $objZipArchive->addFile($file->nodeValue, $file->nodeValue);
            }

            // Add file to finished list
            $arrFinished[] = $file;
            $intRuns++;

            // After 5 files add all to zip
            if ($intRuns == $intMaxFilesPerRun)
            {
                $objZipArchive->close();
                $objZipArchive->open($strZipPath, ZipArchiveCto::CREATE);
                $intRuns = 0;
            }

            // Check time out
            if ((microtime(true) - $floatTimeStart) > $this->intMaxExecutionTime)
            {
                break;
            }
        }

        // Remove finished files from xml
        foreach ($arrFinished as $value)
        {
            $objNodeFiles->removeChild($value);
        }

        // Close XML and zip
        $objXml->save(TL_ROOT . "/" . $srtXMLFilelist);
        $objZipArchive->close();

        if ($objXml->getElementsByTagName("file")->length == 0)
        {
            $booFinished = true;
        }
        else
        {
            $booFinished = false;
        }

        // Return informations
        return array(
            "folder"   => $strZipFolder,
            "file"     => $strZipFile,
            "fullpath" => $strZipPath,
            "xml"      => $srtXMLFilelist,
            "done"     => $booFinished
        );
    }

    /**
     * Unzip files
     * 
     * @param string $strRestoreFile Path to the zip file
     * @return mixes True - If ervething is okay, Array - If some files could not be extract to a given path.
     * @throws Exception if the zip file was not able to open.
     */
    public function runRestore($strRestoreFile)
    {
        $objZipArchive = new ZipArchiveCto();

        if (($mixError = $objZipArchive->open($strRestoreFile)) !== true)
        {
            throw new Exception($GLOBALS['TL_LANG']['MSC']['error'] . ": " . $objZipArchive->getErrorDescription($mixError));
        }

        if ($objZipArchive->numFiles == 0)
        {
            return;
        }

        $arrErrorFiles = array();

        for ($i = 0; $i < $objZipArchive->numFiles; $i++)
        {
            $filename = $objZipArchive->getNameIndex($i);

            if (!$objZipArchive->extractTo("/", $filename))
            {
                $arrErrorFiles[] = $filename;
            }
        }

        $objZipArchive->close();

        if (count($arrErrorFiles) == 0)
        {
            return true;
        }
        else
        {
            return $arrErrorFiles;
        }
    }

    ////////////////////////////////////////////////////////////////////////////
    // Scan Functions
    ////////////////////////////////////////////////////////////////////////////   

    /**
     * Get all files from a list of folders
     * 
     * @param array $arrFolders
     * @return array A List with all files 
     */
    public function getFileListFromFolders($arrFolders = array())
    {
        $objFilesIterator = new AppendIterator();

        foreach ($arrFolders as $strFolder)
        {
            // Scann.
            $objDirectoryIt  = new RecursiveDirectoryIterator(TL_ROOT . '/' . $this->objSyncCtoHelper->standardizePath($strFolder), $this->strRDIFlags);
            $objFilterIt     = new SyncCtoFilterIteratorBase($objDirectoryIt);
            $objRecursiverIt = new RecursiveIteratorIterator($objFilterIt, RecursiveIteratorIterator::SELF_FIRST);

            $objFilesIterator->append($objRecursiverIt);
        }

        return $objFilesIterator;
    }

    /**
     * Get a list from all files into root and/or files
     * 
     * @param boolean $booRoot Start search from root
     * @param boolean $booFiles Start search from files
     * @return array A list with all files 
     */
    public function getFileList($booRoot = false, $booFiles = false)
    {       
        // Init appender.
        $objAppendIt = new AppendIterator();
        
        // Return if no data are requested.
        if ($booRoot == false && $booFiles == false)
        {
            return $objAppendIt;
        }

        // Run Root
        if ($booRoot == true)
        {
            // Scann root for files.
            $objDirectoryIt  = new RecursiveDirectoryIterator(TL_ROOT);
            $objFilterIt     = new SyncCtoFilterIteratorFiles($objDirectoryIt);
            $objRecursiverIt = new RecursiveIteratorIterator($objFilterIt, RecursiveIteratorIterator::SELF_FIRST);

            $objAppendIt->append($objRecursiverIt);

            // Scann allowed root folders.
            foreach ($this->arrRootFolderList as $value)
            {
                $strFullPath = TL_ROOT . '/' . $this->objSyncCtoHelper->standardizePath($value);

                // Check if the folder exists.
                if (!file_exists($strFullPath) || !is_dir($strFullPath))
                {
                    continue;
                }

                // Scann.
                $objDirectoryIt  = new RecursiveDirectoryIterator($strFullPath, $this->strRDIFlags);
                $objFilterIt     = new SyncCtoFilterIteratorBase($objDirectoryIt);
                $objRecursiverIt = new RecursiveIteratorIterator($objFilterIt, RecursiveIteratorIterator::SELF_FIRST);

                $objAppendIt->append($objRecursiverIt);
            }
        }

        // Run tl_files/files.
        if ($booFiles == true)
        {
            // Scann.
            $objDirectoryIt  = new RecursiveDirectoryIterator(TL_ROOT . '/' . $this->objSyncCtoHelper->standardizePath($GLOBALS['TL_CONFIG']['uploadPath']), $this->strRDIFlags);
            $objFilterIt     = new SyncCtoFilterIteratorBase($objDirectoryIt);
            $objRecursiverIt = new RecursiveIteratorIterator($objFilterIt, RecursiveIteratorIterator::SELF_FIRST);

            $objAppendIt->append($objRecursiverIt);
        }

        return $objAppendIt;
    }

    /**
     * Get a list with all folders
     * 
     * @param boolean $booRoot Start search from root
     * @param boolean $booFiles Start search from files
     * 
     * @return AppendIterator|Null A list with all folders or null when we have no data. 
     */
    public function getFolderList($booRoot = false, $booFiles = false)
    {
        // Return if no data are requested.
        if ($booRoot == false && $booFiles == false)
        {
            return null;
        }
        
        // Init appender.
        $objAppendIt = new AppendIterator();

        // Run Root
        if ($booRoot == true)
        {
            foreach ($this->arrRootFolderList as $value)
            {
                $strFullPath = TL_ROOT . '/' . $this->objSyncCtoHelper->standardizePath($value);
                
                // Check if the folder exists.
                if(!file_exists($strFullPath) || !is_dir($strFullPath))
                {
                    continue;
                }
                
                // Scann.
                $objDirectoryIt  = new RecursiveDirectoryIterator($strFullPath, $this->strRDIFlags);
                $objFilterIt     = new SyncCtoFilterIteratorFolder($objDirectoryIt);
                $objRecursiverIt = new RecursiveIteratorIterator($objFilterIt, RecursiveIteratorIterator::SELF_FIRST);  

                $objAppendIt->append($objRecursiverIt);
            }
        }
        
        // Run tl_files/files.
        if ($booFiles == true)
        {
            // Scann.
            $objDirectoryIt  = new RecursiveDirectoryIterator(TL_ROOT . '/' . $this->objSyncCtoHelper->standardizePath($GLOBALS['TL_CONFIG']['uploadPath']), $this->strRDIFlags);
            $objFilterIt     = new SyncCtoFilterIteratorFolder($objDirectoryIt);
            $objRecursiverIt = new RecursiveIteratorIterator($objFilterIt, RecursiveIteratorIterator::SELF_FIRST);  
            
            $objAppendIt->append($objRecursiverIt);
        }
        
        return $objAppendIt;
    }

    ////////////////////////////////////////////////////////////////////////////
    // Folder Operations 
    ////////////////////////////////////////////////////////////////////////////

    /**
     * Create syncCto folders if not exists
     */
    public function checkSyncCtoFolders()
    {
        $objFile = new Folder($this->objSyncCtoHelper->standardizePath($GLOBALS['SYC_PATH']['tmp']));

        $objFile = new Folder($this->objSyncCtoHelper->standardizePath($GLOBALS['SYC_PATH']['db']));
        $objFile->protect();

        $objFile = new Folder($this->objSyncCtoHelper->standardizePath($GLOBALS['SYC_PATH']['file']));
        $objFile->protect();
    }

    /**
     * Clear tempfolder or a folder inside of temp
     * 
     * @CtoCommunication Enable
     * @param string $strFolder
     */
    public function purgeTemp($strFolder = null)
    {
        if ($strFolder == null || $strFolder == "")
        {
            $strPath = $this->objSyncCtoHelper->standardizePath($GLOBALS['SYC_PATH']['tmp']);
        }
        else
        {
            $strPath = $this->objSyncCtoHelper->standardizePath($GLOBALS['SYC_PATH']['tmp'], $strFolder);
        }

        $objFolder = new Folder($strPath);
        $objFolder->clear();
    }

    /**
     * Use the contao maintenance
     *
     * @CtoCommunication Enable
     *
     * @param $arrSettings
     *
     * @return array
     */
    public function runMaintenance($arrSettings)
    {
        $arrReturn = array(
            "success"  => false,
            "info_msg" => array()
        );

        foreach ($arrSettings as $value)
        {
            try
            {
                switch ($value)
                {
                    // Tables
                    case "temp_tables":
                        foreach ($GLOBALS['TL_PURGE']['tables'] as $key => $config)
                        {
                            $arrCallback = $config['callback'];
                            if(is_array($arrCallback) && count($arrCallback) == 2)
                            {
                                $this->import($arrCallback[0]);
                                $this->$arrCallback[0]->$arrCallback[1]();
                            }
                        }
                        break;

                    // Folders
                    case "temp_folders":
                        foreach ($GLOBALS['TL_PURGE']['folders'] as $key => $config)
                        {
                            $arrCallback = $config['callback'];
                            if(is_array($arrCallback) && count($arrCallback) == 2)
                            {
                                $this->import($arrCallback[0]);
                                $this->$arrCallback[0]->$arrCallback[1]();
                            }
                        }
                        break;

                    // Custom
                    case "xml_create":
                        foreach ($GLOBALS['TL_PURGE']['custom'] as $key => $config)
                        {
                            $arrCallback = $config['callback'];
                            if(is_array($arrCallback) && count($arrCallback) == 2)
                            {
                                $this->import($arrCallback[0]);
                                $this->$arrCallback[0]->$arrCallback[1]();
                            }
                        }
                        break;
                }
            }
            catch (Exception $exc)
            {
                $arrReturn["info_msg"][] = "Error by: $value with Msg: " . $exc->getMessage();
            }
        }

        // HOOK: take additional maintenance
        if (isset($GLOBALS['TL_HOOKS']['syncAdditionalMaintenance']) && is_array($GLOBALS['TL_HOOKS']['syncAdditionalMaintenance']))
        {
            foreach ($GLOBALS['TL_HOOKS']['syncAdditionalMaintenance'] as $callback)
            {
                try
                {
                    $this->import($callback[0]);
                    $this->$callback[0]->$callback[1]($arrSettings);
                }
                catch (Exception $exc)
                {
                    $arrReturn["info_msg"][] = "Error by: TL_HOOK $callback[0] | $callback[1] with Msg: " . $exc->getMessage();
                }
            }
        }

        if (count($arrReturn["info_msg"]) != 0)
        {
            return $arrReturn;
        }
        else
        {
            return true;
        }
    }

    ////////////////////////////////////////////////////////////////////////////
    // File Operations 
    ////////////////////////////////////////////////////////////////////////////

    /**
     * Split files function
     * 
     * @CtoCommunication Enable
     * @param type $strSrcFile File start at TL_ROOT exp. system/foo/foo.php
     * @param type $strDesFolder Folder for split files, start at TL_ROOT , exp. system/temp/
     * @param type $strDesFile Name of file without extension. Example: Foo or MyFile
     * @param type $intSizeLimit Split Size in Bytes
     * @return int 
     */
    public function splitFiles($strSrcFile, $strDesFolder, $strDesFile, $intSizeLimit)
    {
        @set_time_limit(3600);

        if ($intSizeLimit < 500 * 1024)
        {
            throw new Exception(vsprintf($GLOBALS['TL_LANG']['ERR']['min_size_limit'], array("500KiB")));
        }

        if (!file_exists(TL_ROOT . "/" . $strSrcFile))
        {
            throw new Exception(vsprintf($GLOBALS['TL_LANG']['ERR']['unknown_file'], array($strSrcFile)));
        }

        $objFolder = new Folder($strDesFolder);
        $objFile   = new File($strSrcFile);

        if ($objFile->filesize < 0)
        {
            throw new Exception($GLOBALS['TL_LANG']['ERR']['64Bit_error']);
        }

        $booRun = true;
        $i      = 0;
        for ($i; $booRun; $i++)
        {
            $fp = fopen(TL_ROOT . "/" . $strSrcFile, "rb");

            if ($fp === FALSE)
            {
                throw new Exception(vsprintf($GLOBALS['TL_LANG']['ERR']['cant_open'], array($strSrcFile)));
            }

            if (fseek($fp, $i * $intSizeLimit, SEEK_SET) === -1)
            {
                throw new Exception(vsprintf($GLOBALS['TL_LANG']['ERR']['cant_open'], array($strSrcFile)));
            }

            if (feof($fp) === TRUE)
            {
                $i--;
                break;
            }

            $data = fread($fp, $intSizeLimit);
            fclose($fp);
            unset($fp);

            $objFileWrite = new File($this->objSyncCtoHelper->standardizePath($strDesFolder, $strDesFile . ".sync" . $i));
            $objFileWrite->write($data);
            $objFileWrite->close();

            unset($objFileWrite);
            unset($data);

            if (( ( $i + 1 ) * $intSizeLimit) > $objFile->filesize)
            {
                $booRun = false;
            }
        }

        return $i;
    }

    /**
     * Rebuild split files
     * 
     * @CtoCommunication Enable
     * @param type $strSplitname
     * @param type $intSplitcount
     * @param type $strMovepath
     * @param type $strMD5
     * @return type 
     */
    public function rebuildSplitFiles($strSplitname, $intSplitcount, $strMovepath, $strMD5)
    {
        // Build savepath
        $strSavePath = $this->objSyncCtoHelper->standardizePath($GLOBALS['SYC_PATH']['tmp'], "sync", $strMovepath);

        // Create Folder
        $objFolder = new Folder(dirname($strSavePath));

        // Run for each part file
        for ($i = 0; $i < $intSplitcount; $i++)
        {
            // Build path for part file
            $strReadFile = $this->objSyncCtoHelper->standardizePath($GLOBALS['SYC_PATH']['tmp'], $strSplitname, $strSplitname . ".sync" . $i);

            // Check if file exists
            if (!file_exists(TL_ROOT . "/" . $strReadFile))
            {
                throw new Exception(vsprintf($GLOBALS['TL_LANG']['ERR']['unknown_file'], array($strSplitname . ".sync" . $i)));
            }

            // Create new file objects
            $objFilePart  = new File($strReadFile);
            $hanFileWhole = fopen(TL_ROOT . "/" . $strSavePath, "a+");

            // Write part file to main file
            fwrite($hanFileWhole, $objFilePart->getContent());

            // Close objects
            $objFilePart->close();
            fclose($hanFileWhole);

            // Free up memory
            unset($objFilePart);
            unset($hanFileWhole);

            // wait
            sleep(1);
        }

        // Check MD5 Checksum
        if (md5_file(TL_ROOT . "/" . $strSavePath) != $strMD5)
        {
            throw new Exception($GLOBALS['TL_LANG']['ERR']['checksum_error']);
        }

        return true;
    }

    /**
     * Move temp files. If DBAFS support is enabled add entries to the dbafs.
     *
     * @CtoCommunication Enable
     *
     * @param  array   $arrFileList List with files for moving.
     *
     * @param  boolean $blnIsDbafs  Flag if we have to change the dbafs system.
     *
     * @return array The list with some more information about the moving of the file.
     */
    public function moveTempFile($arrFileList, $blnIsDbafs)
    {
        foreach ($arrFileList as $key => $value)
        {
            try
            {
                $blnMovedFile = false;
                $strTempFile  = $this->objSyncCtoHelper->standardizePath($GLOBALS['SYC_PATH']['tmp'], "sync", $value["path"]);

                // Check if the tmp file exists.
                if (!file_exists(TL_ROOT . "/" . $strTempFile))
                {
                    $arrFileList[$key]["saved"] = false;
                    $arrFileList[$key]["error"] = sprintf($GLOBALS['TL_LANG']['ERR']['unknown_file'], $strTempFile);
                    continue;
                }

                // Generate the folder if not already there.
                $strFolderPath = dirname($value["path"]);
                if ($strFolderPath != ".")
                {
                    $objFolder = new Folder($strFolderPath);
                    unset($objFolder);
                }

                // Build folders.
                $strFileSource      = $this->objSyncCtoHelper->standardizePath($GLOBALS['SYC_PATH']['tmp'], "sync", $value["path"]);
                $strFileDestination = $this->objSyncCtoHelper->standardizePath($value["path"]);

                // DBAFS support. Check if we have the file already in the locale dbafs system.
                if ($blnIsDbafs)
                {
                    // Get the information from the dbafs.
                    /**  @var \Model $objLocaleData */
                    $objLocaleData = \FilesModel::findByPath($strFileDestination);

                    // If we have no entry in the dbafs just overwrite the current file and add the entry to the dbafs.
                    if ($objLocaleData == null)
                    {
                        // Move file.
                        $blnMovedFile = $this->objFiles->copy($strFileSource, $strFileDestination);

                        // If success add file to the database.
                        if ($blnMovedFile)
                        {
                            // First add it to the dbafs.
                            $objLocaleData       = \Dbafs::addResource($strFileDestination);
                            $objLocaleData->uuid = $value['tl_files']['uuid'];
                            $objLocaleData->save();

                            // Add a status report for debugging and co.
                            $arrFileList[$key]['dbafs']['msg']   = 'Moved file and add to database.';
                            $arrFileList[$key]['dbafs']['state'] = SyncCtoEnum::DBAFS_CREATE;
                        }
                    }
                    else
                    {
                        // Get the readable UUID for the work.
                        $strLocaleUUID = \String::binToUuid($objLocaleData->uuid);

                        // Okay it seems we have already a file with this values.
                        if ($strLocaleUUID == $value['tl_files']['uuid'])
                        {
                            // Move file.
                            $blnMovedFile = $this->objFiles->copy($strFileSource, $strFileDestination);

                            // If success add file to the database.
                            if ($blnMovedFile)
                            {
                                $objLocaleData->hash = $value['checksum'];
                                $objLocaleData->save();

                                // Add a status report for debugging and co.
                                $arrFileList[$key]['dbafs']['msg']   = 'UUID same no problem found. Update database with new hash.';
                                $arrFileList[$key]['dbafs']['state'] = SyncCtoEnum::DBAFS_SAME;
                            }
                        }
                        // Not same so we have to rearrange the files.
                        elseif ($strLocaleUUID != $value['tl_files']['uuid'])
                        {
                            // Get information about the current file information.
                            $arrDestinationInformation = pathinfo($strFileDestination);

                            // Try to rename it to _1 or _2 and so on.
                            $strNewDestinationName = null;
                            $intFileNumber         = 1;
                            for ($i = 1; $i < 100; $i++)
                            {
                                $strNewDestinationName = sprintf('%s/%s_%s.%s',
                                    $arrDestinationInformation['dirname'],
                                    $arrDestinationInformation['filename'],
                                    $i,
                                    $arrDestinationInformation['extension']
                                );

                                if (!file_exists(TL_ROOT . '/' . $strNewDestinationName))
                                {
                                    $intFileNumber = $i;
                                    break;
                                }
                            }

                            // Move the current file to another name, that we have space for the new one.
                            $this->objFiles->copy($strFileDestination, $strNewDestinationName);
                            $objRenamedLocaleData = \Dbafs::moveResource($strFileDestination, $strNewDestinationName);

                            // Move the tmp file.
                            $blnMovedFile = $this->objFiles->copy($strFileSource, $strFileDestination);

                            // If success add file to the database.
                            if ($blnMovedFile)
                            {
                                // First add it to the dbafs.
                                $objLocaleData       = \Dbafs::addResource($strFileDestination);
                                $objLocaleData->uuid = $value['tl_files']['uuid'];
                                $objLocaleData->save();

                                // Add a status report for debugging and co.
                                $arrFileList[$key]['dbafs']['msg']    = 'UUID not same, move the locale file to _' . $intFileNumber . '. Add the new entry to the database.';
                                $arrFileList[$key]['dbafs']['rename'] = $strNewDestinationName;
                                $arrFileList[$key]['dbafs']['state']  = SyncCtoEnum::DBAFS_CONFLICT;
                            }
                        }
                    }
                }
                else
                {
                    $blnMovedFile = $this->objFiles->copy($strFileSource, $strFileDestination);
                }

                // Check the state at moving and add a msg to the return array.
                if ($blnMovedFile)
                {
                    $arrFileList[$key]["saved"] = true;
                }
                else
                {
                    $arrFileList[$key]["saved"]        = false;
                    $arrFileList[$key]["error"]        = sprintf($GLOBALS['TL_LANG']['ERR']['cant_move_file'], $strFileSource, $strFileDestination);
                    $arrFileList[$key]["transmission"] = SyncCtoEnum::FILETRANS_SKIPPED;
                    $arrFileList[$key]["skipreason"]   = $GLOBALS['TL_LANG']['ERR']['cant_move_file'];
                }
            }
            catch (Exception $e)
            {
                $arrFileList[$key]["saved"]        = false;
                $arrFileList[$key]["error"]        = sprintf('Can not move file - %s. Exception message: %s', $strFileSource, $e->getMessage());
                $arrFileList[$key]["transmission"] = SyncCtoEnum::FILETRANS_SKIPPED;
                $arrFileList[$key]["skipreason"]   = $e->getMessage();
            }
        }

        return $arrFileList;
    }

    /**
     * Delete files based on a file list.
     *
     * @CtoCommunication Enable
     *
     * @param  array   $arrFileList List with files for deleting.
     *
     * @param  boolean $blnIsDbafs  Flag if we have to change the dbafs system.
     *
     * @return array The list with some more information about the deleted file.
     */
    public function deleteFiles($arrFileList, $blnIsDbafs)
    {
        if (count($arrFileList) != 0)
        {
            // Run each entry in the list..
            foreach ($arrFileList as $key => $value)
            {
                try
                {
                    if (!file_exists(TL_ROOT . "/" . $value['path']))
                    {
                        $arrFileList[$key]['transmission'] = SyncCtoEnum::FILETRANS_SEND;

                        // Remove from dbafs.
                        if ($blnIsDbafs)
                        {
                            \Dbafs::deleteResource($value['path']);
                        }
                    }
                    // Check if we have a file.
                    elseif (is_file(TL_ROOT . "/" . $value['path']))
                    {
                        // Delete the file.
                        if ($this->objFiles->delete($value['path']))
                        {
                            $arrFileList[$key]['transmission'] = SyncCtoEnum::FILETRANS_SEND;

                            // Remove from dbafs.
                            if ($blnIsDbafs)
                            {
                                \Dbafs::deleteResource($value['path']);
                            }
                        }
                        // If not possible add a msg.
                        else
                        {
                            $arrFileList[$key]['transmission'] = SyncCtoEnum::FILETRANS_SKIPPED;
                            $arrFileList[$key]["skipreason"]   = $GLOBALS['TL_LANG']['ERR']['cant_delete_file'];
                        }

                    }
                    // .. else we have a folder and remove this with all files inside.
                    elseif (is_dir(TL_ROOT . "/" . $value['path']))
                    {
                        $this->objFiles->rrdir($value['path']);
                        $arrFileList[$key]['transmission'] = SyncCtoEnum::FILETRANS_SEND;

                        // Remove from dbafs.
                        if ($blnIsDbafs)
                        {
                            \Dbafs::deleteResource($value['path']);
                        }
                    }
                }
                catch (Exception $exc)
                {
                    $arrFileList[$key]['transmission'] = SyncCtoEnum::FILETRANS_SKIPPED;
                    $arrFileList[$key]["skipreason"]   = $exc->getMessage();
                }
            }
        }

        return $arrFileList;
    }

    /**
     * Receive a file and move it to the right folder.
     * 
     * @CtoCommunication Enable
     * @param type $arrMetafiles
     * @return string 
     */
    public function saveFiles($arrMetafiles)
    {
        if (!is_array($arrMetafiles) || count($_FILES) == 0)
        {
            throw new Exception($GLOBALS['TL_LANG']['ERR']['missing_file_information']);
        }

        $arrResponse = array();

        foreach ($_FILES as $key => $value)
        {
            if (!key_exists($key, $arrMetafiles))
            {
                throw new Exception($GLOBALS['TL_LANG']['ERR']['missing_file_information']);
            }

            $strFolder = $arrMetafiles[$key]["folder"];
            $strFile   = $arrMetafiles[$key]["file"];
            $strMD5    = $arrMetafiles[$key]["MD5"];

            switch ($arrMetafiles[$key]["typ"])
            {
                case SyncCtoEnum::UPLOAD_TEMP:
                    $strSaveFile = $this->objSyncCtoHelper->standardizePath($GLOBALS['SYC_PATH']['tmp'], $strFolder, $strFile);
                    break;

                case SyncCtoEnum::UPLOAD_SYNC_TEMP:
                    $strSaveFile = $this->objSyncCtoHelper->standardizePath($GLOBALS['SYC_PATH']['tmp'], "sync", $strFolder, $strFile);
                    break;

                case SyncCtoEnum::UPLOAD_SQL_TEMP:
                    $strSaveFile = $this->objSyncCtoHelper->standardizePath($GLOBALS['SYC_PATH']['tmp'], "sql", $strFile);
                    break;

                case SyncCtoEnum::UPLOAD_SYNC_SPLIT:
                    $strSaveFile = $this->objSyncCtoHelper->standardizePath($GLOBALS['SYC_PATH']['tmp'], $arrMetafiles[$key]["splitname"], $strFile);
                    break;

                default:
                    throw new Exception($GLOBALS['TL_LANG']['ERR']['unknown_path']);
                    break;
            }

            $objFolder = new Folder(dirname($strSaveFile));

            if ($this->objFiles->move_uploaded_file($value["tmp_name"], $strSaveFile) === FALSE)
            {
                throw new Exception(vsprintf($GLOBALS['TL_LANG']['ERR']['cant_move_file'], array($value["tmp_name"], $strSaveFile)));
            }
            else if ($key != md5_file(TL_ROOT . "/" . $strSaveFile))
            {
                throw new Exception($GLOBALS['TL_LANG']['ERR']['checksum_error']);
            }
            else
            {
                $arrResponse[$key] = "Saving " . $arrMetafiles[$key]["file"];
            }
        }

        return $arrResponse;
    }

    /**
     * Send a file as serelizard array
     * 
     * @CtoCommunication Enable
     * @param string $strPath
     * @return array
     */
    public function getFile($strPath)
    {
        if (!file_exists(TL_ROOT . "/" . $strPath))
        {
            throw new Exception(vsprintf($GLOBALS['TL_LANG']['ERR']['unknown_file'], array($strPath)));
        }

        $objFile    = new File($strPath);
        $strContent = base64_encode($objFile->getContent());
        $objFile->close();

        return array("md5"     => md5_file(TL_ROOT . "/" . $strPath), "content" => $strContent);
    }

}