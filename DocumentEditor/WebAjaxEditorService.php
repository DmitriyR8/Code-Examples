<?php
/**
 * Created by PhpStorm.
 * User: developer
 * Date: 25.08.20
 * Time: 15:19
 */

namespace App\Service\DocEditor;

/**
 * Class WebAjaxEditorService
 * @package App\Service\DocEditor
 */
class WebAjaxEditorService
{
    const FILE_SIZE_MAX = 5242880;

    private $docEditorService;
    private $trackerStatus = [
        0 => 'NotFound',
        1 => 'Editing',
        2 => 'MustSave',
        3 => 'Corrupted',
        4 => 'Closed'
    ];

    /**
     * WebAjaxEditorService constructor.
     */
    public function __construct()
    {
        $this->docEditorService = new DocEditorService();
    }

    /**
     * @param $type
     * @param null $fileName
     */
    public function filter($type, $fileName)
    {
        @header( 'Content-Type: application/json; charset==utf-8');
        @header( 'X-Robots-Tag: noindex' );
        @header( 'X-Content-Type-Options: nosniff' );

        $this->noCacheHeaders();

        switch($type) {
            case "upload":
                $response = $this->upload();
                $response['status'] = isset($response['error']) ? 'error' : 'success';
                die (json_encode($response));
            case "track":
                $response = $this->track($fileName);
                $response['status'] = 'success';
                die (json_encode($response));
            case "delete":
                $response_array = $this->delete($fileName);
                $response_array['status'] = 'success';
                die (json_encode($response_array));
            default:
                $response['status'] = 'error';
                $response['error'] = '404 Method not found';
                die(json_encode($response));
        }
    }

    /**
     */
    private function noCacheHeaders()
    {
        $headers = array(
            'Expires' => 'Wed, 11 Jan 1984 05:00:00 GMT',
            'Cache-Control' => 'no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        );
        $headers['Last-Modified'] = false;


        unset($headers['Last-Modified']);

        if (function_exists( 'header_remove')) {
            @header_remove('Last-Modified');
        } else {
            foreach (headers_list() as $header) {
                if ( 0 === stripos($header, 'Last-Modified')) {
                    $headers['Last-Modified'] = '';
                    break;
                }
            }
        }
        foreach($headers as $name => $fieldValue) {
            @header("{$name}: {$fieldValue}");
        }
    }

    /**
     * @return mixed
     */
    public function upload()
    {
        if ($_FILES['files']['error'] > 0) {
            $result["error"] = 'Error ' . json_encode($_FILES['files']['error']);
            return $result;
        }

        $tmp = $_FILES['files']['tmp_name'];

        if (empty($tmp)) {
            $result["error"] = 'No file sent';
            return $result;
        }

        if (is_uploaded_file($tmp))
        {
            $fileSize = $_FILES['files']['size'];
            $ext = strtolower('.' . pathinfo($_FILES['files']['name'], PATHINFO_EXTENSION));

            if ($fileSize <= 0 || $fileSize > self::FILE_SIZE_MAX) {
                $result["error"] = 'File size is incorrect';
                return $result;
            }

            if (!in_array($ext, $this->docEditorService->docServEdited, true)) {
                $result["error"] = 'File type is not supported';
                return $result;
            }

            $filename = $this->docEditorService->getCorrectName($_FILES['files']['name']);
            if (!move_uploaded_file($tmp,  $this->docEditorService->storagePath . '/' . $filename) ) {
                $result["error"] = 'Upload failed';
                return $result;
            }
            $this->docEditorService->createMeta($filename);

        } else {
            $result["error"] = 'Upload failed';
            return $result;
        }

        $result["filename"] = $filename;
        return $result;
    }

    /**
     * @param $fileName
     * @return mixed
     */
    public function track($fileName)
    {
        $result["error"] = 0;
        if (($bodyStream = file_get_contents('php://input'))===FALSE) {
            $result["error"] = "Bad Request";
            return $result;
        }

        $data = json_decode($bodyStream, TRUE);

        if ($data === NULL) {
            $result["error"] = "Bad Response";
            return $result;
        }

        $status = $this->trackerStatus[$data["status"]];

        switch ($status) {
            case "MustSave":
            case "Corrupted":

                $downloadUri = $data["url"];
                $downloadExt = strtolower('.' . pathinfo($downloadUri, PATHINFO_EXTENSION));

                $saved = 1;

                if (($newData = file_get_contents($downloadUri)) === FALSE) {
                    $saved = 0;
                } else {
                    $storagePath = $this->docEditorService->storagePath . '/' . $fileName;
                    $histDir = $this->docEditorService->getHistoryDir($storagePath);
                    $verDir = $this->docEditorService->getVersionDir($histDir, $this->docEditorService->getFileVersion($histDir) + 1);

                    if (!mkdir($verDir) && !is_dir($verDir)) {
                        throw new \RuntimeException(sprintf('Directory "%s" was not created', $verDir));
                    }

                    copy($storagePath, $verDir . DIRECTORY_SEPARATOR . "prev" . $downloadExt);
                    file_put_contents($storagePath, $newData, LOCK_EX);

                    if ($changesData = file_get_contents($data["changesurl"])) {
                        file_put_contents($verDir . DIRECTORY_SEPARATOR . "diff.zip", $changesData, LOCK_EX);
                    }

                    $histData = $data["changeshistory"];
                    if (empty($histData)) {
                        $histData = json_encode($data["history"], JSON_PRETTY_PRINT);
                    }
                    if (!empty($histData)) {
                        file_put_contents($verDir . DIRECTORY_SEPARATOR . "changes.json", $histData, LOCK_EX);
                    }
                    file_put_contents($verDir . DIRECTORY_SEPARATOR . "key.txt", $data["key"], LOCK_EX);
                }

                $result["c"] = "saved";
                $result["status"] = $saved;
                break;
        }

        return $result;
    }

    /**
     * @param $fileName
     * @return mixed
     */
    public function delete($fileName)
    {
        try {
            $filePath = $this->docEditorService->storagePath . '/' . $fileName;

            unlink($filePath);
            $this->delTree($this->docEditorService->getHistoryDir($filePath));

            header('Location: /sidebar-extensions');
        }
        catch (\Exception $e) {
            $result["error"] = "error: " . $e->getMessage();
            return $result;
        }
    }

    /**
     * @param $dir
     * @return bool|void
     */
    public function delTree($dir)
    {
        if (!file_exists($dir) || !is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), array('.','..'));
        foreach ($files as $file) {
            (is_dir("$dir/$file")) ? $this->delTree("$dir/$file") : unlink("$dir/$file");
        }

        rmdir($dir);
    }
}
