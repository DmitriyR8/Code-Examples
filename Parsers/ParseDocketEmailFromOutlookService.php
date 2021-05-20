<?php

/**
 * Created by PhpStorm.
 * User: developer
 * Date: 02.09.20
 * Time: 15:04
 */

namespace App\Service\Docket;

use App\Lib\StringHelper;
use App\Repository\DocketServiceRepository;
use App\Service\MicrosoftService;
use Cake\Http\Client;
use Cake\Log\Log;

/**
 * Class ParseDocketEmailFromOutlookService
 * @package App\Service\Docket
 */
class ParseDocketEmailFromOutlookService extends BaseDocketService

{
    public $microsoft;
    public $docketRepository;
    public $http;

    /**
     * ParseDocketEmailFromOutlookService constructor.
     * @throws \Exception
     */
    public function __construct()
    {
        parent::__construct();
        $this->http = new Client();
        $this->microsoft = new MicrosoftService();
        $this->docketRepository = new DocketServiceRepository();
    }


    /**
     * @param $requestData
     * @return bool|int|string
     *
     * Point of entry when email come to.
     */
    public function requestHandler($requestData)
    {
        try {
            if (isset($requestData['value'])) {
                $this->parseMessage();
            }
        } catch (\Exception $exception) {
            Log::error($exception->getMessage());
            Log::debug($exception->getTraceAsString());
        }
    }

    /**
     * @param $messageData
     *
     * Handler for each email existing in box.
     * @throws \Exception
     */
    public function run($messageData)
    {
        if (!empty($messageData)) {
            $docket = $this->docketRepository->insertDocketRow($messageData);

            if (!empty($docket)) {
                $this->docketRepository->insertDocketParties($docket, $messageData['attorneyInfo']);

                $pageHandlerResult = null;

                $docketEntry = $this->docketRepository->insertDocketEntry($messageData, $docket);

                if (!is_null($messageData['docUrlData'])) {
                    $content = $this->getDocumentPage($messageData['docUrlData'], $this->getPacerCookie());

                    $pageHandlerResult = $this->documentPageHandler($content, $messageData, $docket, $docketEntry);
                }

                if (!empty($pageHandlerResult['docData']['attachments'])) {
                    $this->docketRepository->insertDocketAttachments($docket, $messageData['sequenceID'], $pageHandlerResult['docData']);
                }

                if (getenv('ENVIRONMENT') === 'production') {
                    $this->http->patch("https://graph.microsoft.com/v1.0/users/" . env('GOOGLE_DOCKET_EMAIL') . "/mailFolders/inbox/messages/{$messageData['messageID']}",
                        json_encode([
                            'isRead' => true
                        ]),
                        [
                            'headers' => [
                                'Content-Type' => 'application/json',
                                'Authorization' => 'Bearer ' . $this->microsoft->getClient('outlook')['access_token'],
                            ],
                        ]
                    );

                    $this->http->post("https://graph.microsoft.com/v1.0/users/" . env('GOOGLE_DOCKET_EMAIL') . "/mailFolders/inbox/messages/{$messageData['messageID']}/move",
                        json_encode([
                            'destinationId' => getenv('PROCESSED_EMAIL_FOLDER_ID')
                        ]),
                        [
                            'headers' => [
                                'Content-Type' => 'application/json',
                                'Authorization' => 'Bearer ' . $this->microsoft->getClient('outlook')['access_token'],
                            ],
                        ]
                    );
                }
            }
        }
    }

    /**
     * @return int
     *
     * Parser email. Get all unread email.
     * @throws \Exception
     */
    public function parseMessage()
    {
        $limit = 10;
        $page = 0;
        $countMessage = 0;

        do {
            $messages =  $this->paginateMessages($limit, $page);
            $page += 1;

            foreach ($messages['value'] as $item) {
                $messageId = $item['id'];
                $fromEmail = $item['from']['emailAddress']['address'];
                $emailSubject = $item['subject'];
                $fedAbbr = $this->getFedAbbrFromBody($fromEmail, $item['body']['content']);
                if ($fedAbbr && $this->checkEmailSubject($emailSubject)) {
                    $result = $this->getDataEmailBody($item['body']['content']);
                    $result['fedAbbr'] = $fedAbbr;
                    $result['docUrlData'] = $this->getEmailDocUrlData($item['body']['content']);
                    $result['messageID'] = $messageId;
                    $this->run($result);
                    $countMessage++;
                }
            }
        } while ($page < 10 && $messages && count($messages['value']) > 0);

        return $countMessage;
    }


    private function paginateMessages($limit = 10, $page = 0) {
        $skip = $page * $limit;

        $queryParams = "\$top=$limit&\$skip=$skip";
        $messages = $this->http->get('https://graph.microsoft.com/v1.0/users/' . env('GOOGLE_DOCKET_EMAIL') . '/mailFolders/inbox/messages?'. $queryParams, [], [
            'headers' => ['Authorization' => 'Bearer ' . $this->microsoft->getClient('outlook')['access_token']]
        ])->getBody()->getContents();

        return json_decode($messages, true);
    }


    /**
     * @param $messageBody
     * @return array
     *
     * Get data from email. Data from docket, docket_parties, docket_entries, docket_attachments.
     * @throws \PHPHtmlParser\Exceptions\ChildNotFoundException
     * @throws \PHPHtmlParser\Exceptions\CircularException
     * @throws \PHPHtmlParser\Exceptions\CurlException
     * @throws \PHPHtmlParser\Exceptions\NotLoadedException
     * @throws \PHPHtmlParser\Exceptions\StrictException
     */
    public function getDataEmailBody($messageBody)
    {
        $body = strip_tags($messageBody, '<p><b><br><a><tr><td>');
        $desc = null;
        $this->getParseHtmlDom()->load($body);
        $seqID = null;
        $caseNumber = $this->getParseHtmlDom()->find('tr', 1)->find('td', 1)->find('a', 0)->text;
        $attorneyText = StringHelper::getSubString($body, 'Notice has been electronically mailed to:', $caseNumber);

        foreach ($this->getParseHtmlDom()->find('tr') as $index => $tr) {
            if (strpos($tr->innerHtml, 'Document Number:') !== false) {
                $block = $this->getParseHtmlDom()->find('tr', $index)->find('td', 1)->find('a', 0);
                if(isset($block)) {
                    $seqID = $block->text;
                }
            }

            if (!is_numeric($seqID)) {
                // TODO: review this questionable part - reason is that random tr is taken as doc #
                try {
                    $block = $this->getParseHtmlDom()->find('tr', $index)->find('td', 1);
                    if ($block) {
                        $seqID = StringHelper::getSubString($block->text, ' ', '(');
                    }
                } catch (\Throwable $e){}
            }
        }

        foreach ($this->getParseHtmlDom()->find('p') as $index => $p) {
            if (strpos($p->innerHtml, 'Docket Text:') !== false) {
                $desc = trim(strip_tags($this->getParseHtmlDom()->find('p', $index)->find('b', 0)->innerHtml));
            }
        }

        return [
            'caseNumber' => $caseNumber,
            'caseName' => $this->getParseHtmlDom()->find('tr', 0)->find('td', 1)->text,
            'dateEntered' => StringHelper::getSubString($this->getParseHtmlDom()->outerHtml, 'entered on ', 'at'),
            'dateFiled' => strip_tags(StringHelper::getSubString($this->getParseHtmlDom()->outerHtml, 'filed on ', " "), '<br>'),
            'sequenceID' => $seqID,
            'restricted' => $this->isRestricted($desc),
            'sealed' => $this->isSealed($desc),
            'description' => $desc,
            'attorneyInfo' => $this->getAttorneyInfo($attorneyText)
        ];
    }

    /**
     * @param $text
     * @return array[]
     *
     * Get attorney info for docket_parties.
     */
    public function getAttorneyInfo($text)
    {
        $attorneyTextFiltered = array_filter(preg_split('/<br>|<BR>/', $text), function ($item) {
            return strpos($item, '@') !== false;
        });

        $attorneyData = array_map(function ($item) {

            $string = trim(preg_replace('/&nbsp;|&nbsp|&amp;|nbsp/', '', html_entity_decode(strip_tags($item))));
            preg_match_all("/[\._a-zA-Z0-9-]+@[\._a-zA-Z0-9-]+/i", $string, $matches);

            $newString = strstr($string, ',', true);

            if (!$newString) {
                $newString = $string;
            }

            return ['name' => trim(str_replace($matches[0][0], '', $newString)), 'email' => trim($matches[0][0])];
        }, $attorneyTextFiltered);

        return $attorneyData;
    }

    /**
     * @param $message
     * @return null
     *
     * Get email doc url data for fetching attachment.
     * @throws \PHPHtmlParser\Exceptions\ChildNotFoundException
     * @throws \PHPHtmlParser\Exceptions\CircularException
     * @throws \PHPHtmlParser\Exceptions\CurlException
     * @throws \PHPHtmlParser\Exceptions\NotLoadedException
     * @throws \PHPHtmlParser\Exceptions\StrictException
     */
    public function getEmailDocUrlData($message)
    {

        $this->getParseHtmlDom()->load($message);

        $a = $this->getParseHtmlDom()->find('table', 0)->find('tr td a', 1);

        if (is_null($a)) {
            return null;
        }

        $query = str_replace('amp;', '', parse_url($a->getAttribute('href'), PHP_URL_QUERY));
        parse_str($query, $params);
        $params['url'] = strstr($a->getAttribute('href'), '?', true);

        return $params;
    }

    /**
     * @param $fromEmail
     * @param $messageBody
     * @return bool|string|null
     * @throws \PHPHtmlParser\Exceptions\ChildNotFoundException
     * @throws \PHPHtmlParser\Exceptions\CircularException
     * @throws \PHPHtmlParser\Exceptions\CurlException
     * @throws \PHPHtmlParser\Exceptions\StrictException
     */
    public function getFedAbbrFromBody($fromEmail, $messageBody)
    {
        $body = strip_tags($messageBody, '<p><b><br><a><tr><td>');
        $desc = null;
        $this->getParseHtmlDom()->load($body);

        $abbreviation = false;
        if (strpos($fromEmail, '.uscourts.gov') !== false) {
            try {
                $href = $this->getParseHtmlDom()->find('tr', 1)->find('td', 1)->find('a', 0)->href;
                $matches = null;
                preg_match('@http?s:\/\/\w*.?(\w*).uscourts.gov.*@', $href, $matches);
                if(isset($matches[1])) {
                    $abbreviation = $matches[1];
                }
            } catch (\Throwable $e) {
                Log::error('Unable to parse court abbreviation from hyperlink: ' . ($href ?? '-'));
                Log::error($e->getMessage());
            }

            if(!$abbreviation) {
                $abbreviation = StringHelper::getSubString($fromEmail, '@', '.');
            }
        }

        return $abbreviation;
    }

    /**
     * @param $subject
     * @return bool
     *
     * Check email for parsing. If email doesn't contain "Activity in Case" ignore then.
     */
    public function checkEmailSubject($subject)
    {
        if (strpos($subject, 'Activity in Case') !== false) {
            return true;

        }

        return false;
    }

}
