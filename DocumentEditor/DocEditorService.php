<?php
/**
 * Created by PhpStorm.
 * User: developer
 * Date: 21.08.20
 * Time: 11:27
 */

namespace App\Service\DocEditor;

/**
 * Class DocEditorService
 * @package App\Service\DocEditor
 */
class DocEditorService
{
    const STORAGE = "";
    const ALONE = true;

    public $docServEdited = [".docx", ".txt"];
    private $docTypes = [
        ".doc", ".docx", ".docm",
        ".dot", ".dotx", ".dotm", ".rtf",
        ".odt", ".fodt", ".ott", ".txt",
        ".html", ".htm", ".mht", ".epub",
        ".pdf", ".djvu", ".fb2", ".xps"
    ];

    public $storagePath;

    /**
     * DocEditorService constructor.
     */
    public function __construct()
    {
        $this->storagePath = $_SERVER['DOCUMENT_ROOT'] . DIRECTORY_SEPARATOR . "documents";
    }

    /**
     * @param $fileName
     * @param $fileUri
     * @param $docKey
     * @param $fileType
     * @param $callbackUrl
     * @return array
     */
    public function editRun($fileName, $fileUri, $docKey, $fileType, $callbackUrl)
    {
        $config = [
            "type" => "desktop",
            "documentType" => $this->getDocumentType($fileName),
            "document" => [
                "title" => $fileName,
                "url" => $fileUri,
                "fileType" => $fileType,
                "key" => $docKey,
                "info" => [
                    "author" => "Me",
                    "created" => date('d.m.y')
                ],
            ],
            "editorConfig" => [
                "callbackUrl" => $callbackUrl,
                "customization" => [
                    "about" => true,
                    "feedback" => true,
                    "goback" => [
                        "url" => $this->serverPath() . '/sidebar-extensions',
                    ]
                ]
            ]
        ];

        return $config;
    }

    /**
     * @return array
     */
    public function getStoredFiles()
    {
        $directory = $_SERVER['DOCUMENT_ROOT'] . DIRECTORY_SEPARATOR . "documents";

        $result = array();
        if (file_exists($directory) && is_dir($directory)) {
            $cDir = scandir($directory);

            foreach($cDir as $key => $fileName) {
                if (!in_array($fileName, array(".", "..")) && !is_dir($directory . DIRECTORY_SEPARATOR . $fileName)) {
                    $dat = filemtime($directory . DIRECTORY_SEPARATOR . $fileName);
                    $result[$dat] = (object) array(
                        "name" => $fileName,
                        "documentType" => $this->getDocumentType($fileName)
                    );
                }
            }
            ksort($result);
            return array_reverse($result);
        }

        return $result;
    }

    /**
     * @param $filename
     * @return string
     */
    public function getDocumentType($filename)
    {
        $ext = strtolower('.' . pathinfo($filename, PATHINFO_EXTENSION));

        if (in_array($ext, $this->docTypes, true)) {
            return "text";
        }
        return "";
    }

    /**
     * @param $storagePath
     * @return string
     */
    public function getHistoryDir($storagePath)
    {
        $directory = $storagePath . "-hist";
        if (!file_exists($directory) && !is_dir($directory)) {
            mkdir($directory, 0777);
        }

        return $directory;
    }

    /**
     * @param $histDir
     * @param $version
     * @return string
     */
    public function getVersionDir($histDir, $version)
    {
        return $histDir . DIRECTORY_SEPARATOR . $version;
    }

    /**
     * @param $histDir
     * @return int
     */
    public function getFileVersion($histDir)
    {
        if (!file_exists($histDir) || !is_dir($histDir)) return 0;

        $cDir = scandir($histDir);
        $ver = 0;
        foreach($cDir as $key => $fileName) {
            if (!in_array($fileName, array(".", "..")) && is_dir($histDir . DIRECTORY_SEPARATOR . $fileName)) {
                $ver++;
            }
        }
        return $ver;
    }

    /**
     * @param $fileName
     * @return string
     */
    public function fileUri($fileName)
    {
        return $this->getVirtualPath() . 'documents/' . rawurlencode($fileName);
    }

    /**
     * @return string
     */
    public function getVirtualPath()
    {
        $storagePath = trim(str_replace(array('/','\\'), '/', self::STORAGE), '/');
        $storagePath = !empty($storagePath) ? $storagePath . '/' : "";


        $virtPath = $this->serverPath() . '/' . $storagePath . $this->getCurUserHostAddress();
        return $virtPath;
    }

    /**
     * @return string
     */
    public function serverPath()
    {
        return ($this->getScheme() . '://' . $_SERVER['HTTP_HOST']);
    }

    /**
     *
     */
    public function getCurUserHostAddress()
    {
        if (self::ALONE) {
            $this->storagePath;
        }
    }

    /**
     * @return string
     */
    public function getScheme()
    {
        return (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    }

    /**
     * @param $fileName
     * @return bool|string|string[]|null
     */
    public function getDocEditorKey($fileName)
    {
        $key = $this->getCurUserHostAddress() . $this->fileUri($fileName);
        $stat = filemtime($this->storagePath . '/' . $fileName);
        $key .= $stat;
        return $this->generateRevisionId($key);
    }

    /**
     * @param $expectedKey
     * @return bool|string|string[]|null
     */
    public function generateRevisionId($expectedKey)
    {
        if (strlen($expectedKey) > 20) {
            $expectedKey = crc32($expectedKey);
        }
        $key = preg_replace("[^0-9-.a-zA-Z_=]", "_", $expectedKey);
        $key = substr($key, 0, min(array(strlen($key), 20)));
        return $key;
    }

    /**
     * @param $filename
     * @param $filetype
     * @param $docKey
     * @param $fileuri
     * @return array
     */
    public function getHistory($fileName, $fileType, $docKey, $fileUri)
    {
        $histDir = $this->getHistoryDir($this->storagePath . '/' . $fileName);

        if ($this->getFileVersion($histDir) > 0) {
            $curVer = $this->getFileVersion($histDir);

            $hist = [];
            $histData = [];

            for ($i = 0; $i <= $curVer; $i++) {
                $obj = [];
                $dataObj = [];
                $verDir = $this->getVersionDir($histDir, $i + 1);

                $key = $i == $curVer ? $docKey : file_get_contents($verDir . DIRECTORY_SEPARATOR . "key.txt");
                $obj["key"] = $key;
                $obj["version"] = $i;

                if ($i == 0) {
                    $createdInfo = file_get_contents($histDir . DIRECTORY_SEPARATOR . "createdInfo.json");
                    $json = json_decode($createdInfo, true);

                    $obj["created"] = $json["created"];
                    $obj["user"] = [
                        "id" => $json["uid"],
                        "name" => $json["name"]
                    ];
                }

                $prevFileName = $verDir . DIRECTORY_SEPARATOR . "prev." . $fileType;
                $prevFileName = substr($prevFileName, strlen(self::STORAGE));
                $dataObj["key"] = $key;
                $dataObj["url"] = $i == $curVer ? $fileUri : $this->getVirtualPath() . str_replace("%5C", "/", rawurlencode($prevFileName));
                $dataObj["version"] = $i;

                if ($i > 0) {
                    $changes = json_decode(file_get_contents($this->getVersionDir($histDir, $i) . DIRECTORY_SEPARATOR . "changes.json"), true);
                    $change = $changes["changes"][0];

                    $obj["changes"] = $changes["changes"];
                    $obj["serverVersion"] = $changes["serverVersion"];
                    $obj["created"] = $change["created"];
                    $obj["user"] = $change["user"];

                    $prev = $histData[$i -1];
                    $dataObj["previous"] = [
                        "key" => $prev["key"],
                        "url" => $prev["url"]
                    ];
                    $changesUrl = $this->getVersionDir($histDir, $i) . DIRECTORY_SEPARATOR . "diff.zip";
                    $changesUrl = substr($changesUrl, strlen(self::STORAGE));

                    $dataObj["changesUrl"] = $this->getVirtualPath() . str_replace("%5C", "/", rawurlencode($changesUrl));
                }

                array_push($hist, $obj);
                $histData[$i] = $dataObj;
            }

            $out = [];
            array_push($out, [
                "currentVersion" => $curVer,
                "history" => $hist
            ],
                $histData);
            return $out;
        }
    }

    /**
     * @param $fileName
     * @return mixed
     */
    public function getCorrectName($fileName)
    {
        $pathParts = pathinfo($fileName);
        return $pathParts['basename'];
    }

    /**
     * @param $fileName
     * @param string $uid
     */
    public function createMeta($fileName, $uid = "0")
    {
        $histDir = $this->getHistoryDir($this->storagePath . '/' . $fileName);

        $json = [
            "created" => date("Y-m-d H:i:s"),
            "uid" => $uid,
            "name" => "",
        ];

        file_put_contents($histDir . DIRECTORY_SEPARATOR . "createdInfo.json", json_encode($json, JSON_PRETTY_PRINT));
    }
}
