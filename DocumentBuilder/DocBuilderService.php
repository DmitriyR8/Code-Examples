<?php
/**
 * Created by PhpStorm.
 * User: developer
 * Date: 13.08.20
 * Time: 10:17
 */

namespace App\Service\DocBuilder;


use Cake\Log\Log;

/**
 * Class DocBuilderService
 * @package App\Service\DocBuilder
 */
class DocBuilderService
{
    /**
     * @param $request
     * @param $format
     * @return string
     */
    public function createDocument($request, $format)
    {
        $hash = mt_rand();
        $inputFilePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'input.' . $hash . ".docbuilder";
        $outputFilePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'output.' . $hash . "." . $format;

        if (empty($request['attorney_info']) && empty($request['court_name']) && empty($request['body'])) {
            $format = 'template';
            $templatePath = $_SERVER["DOCUMENT_ROOT"] . DIRECTORY_SEPARATOR . 'docbuilderscripts' . DIRECTORY_SEPARATOR . $format . '.docbuilder';
            $template = $this->readWordDocs();
            $templateText = file_get_contents($templatePath);
            $templateText = str_replace(
                array('${Attorney Info}', '${Court Name}', '${Body}', '${OutputFilePath}'),
                array($template[0], $template[2], $template[1], $outputFilePath), $templateText);
        } else {
            $templatePath = $_SERVER["DOCUMENT_ROOT"] . DIRECTORY_SEPARATOR . 'docbuilderscripts' . DIRECTORY_SEPARATOR . $format . '.docbuilder';
            $templateText = file_get_contents($templatePath);
            $templateText = str_replace(
                array('${Attorney Info}', '${Court Name}', '${Body}', '${OutputFilePath}'),
                array($request['attorney_info'], $request['court_name'], $request['body'], $outputFilePath), $templateText);
        }


        $inputFile = fopen($inputFilePath, 'wb+');
        fwrite($inputFile, $templateText);
        fclose($inputFile);

        try {
            $this->buildFile($inputFilePath, $outputFilePath);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
        }

        return $outputFilePath;
    }

    /**
     * @return array
     */
    public function readWordDocs()
    {
        $currentDir = $_SERVER['DOCUMENT_ROOT'] . "/doctemplates";
        $scan = scandir($currentDir);
        unset($scan[0], $scan[1]);

        foreach ($scan as $file) {
            $phpWord = \PhpOffice\PhpWord\IOFactory::load($currentDir. '/' . $file);
            $sections = $phpWord->getSections();
            $attInfo = $this->getContent($sections);

            $result[] = implode(",", $attInfo);
        }

        return $result;
    }

    /**
     * @param $sections
     * @return array
     */
    public function getContent($sections)
    {
        $result = [];

        foreach ($sections as $key => $value) {
            $sectionElement = $value->getElements();
            foreach ($sectionElement as $elementKey => $elementValue) {
                if ($elementValue instanceof \PhpOffice\PhpWord\Element\TextRun) {
                    $secondSectionElement = $elementValue->getElements();
                    foreach ($secondSectionElement as $secondSectionElementKey => $secondSectionElementValue) {
                        if ($secondSectionElementValue instanceof \PhpOffice\PhpWord\Element\Text) {
                            $result[] = $secondSectionElementValue->getText();
                        }
                    }
                }
            }
        }

        return $result;
    }

    /**
     * @param $inputFilePath
     * @param $outputFilePath
     * @throws \Exception
     */
    public function buildFile($inputFilePath, $outputFilePath)
    {
        if (!isset($inputFilePath) || !file_exists($inputFilePath)) {
            throw new \Exception ("An error has occurred. Source File not found");
        }

        exec(getenv('DOCBUILDER_PATH') . " " . $inputFilePath . " 2>&1", $output);

        if (count($output) !== 0) {
            throw new \Exception (json_encode($output));
        }

        if (!file_exists($outputFilePath)) {
            throw new \Exception ("An error has occurred. Result File not found :" . $outputFilePath);
        }
    }

    /**
     * @param $filePath
     * @param $fileName
     */
    public function returnFile($filePath, $fileName)
    {
        $time = time();
        $currentDir = $_SERVER['DOCUMENT_ROOT'] . "/documents";

        if (!file_exists($currentDir) && !is_dir($currentDir)) {
            mkdir($currentDir, 0777);
        }
        $path = $currentDir . "/{$time}_{$fileName}";
        copy($filePath, $path);
    }
}
