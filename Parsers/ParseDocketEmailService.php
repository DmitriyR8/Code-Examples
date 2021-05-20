<?php

namespace App\Service\Docket;

use App\Lib\StringHelper;
use App\Repository\DocketServiceRepository;
use App\Service\GoogleService;
use Cake\Log\Log;
use Google_Service_Gmail;
use Google_Service_Gmail_BatchModifyMessagesRequest;

/**
 * Class ParseDocketEmailService
 * @package App\Service
 */
class ParseDocketEmailService extends BaseDocketService
{

    private $gService;
    private $docketRepository;

    public function __construct()
    {
        parent::__construct();

        $this->gService = new Google_Service_Gmail(GoogleService::getClient('docket'));
        $this->docketRepository = new DocketServiceRepository();
    }


    /**
     * @param $request
     * @return bool|int|string
     *
     * Point of entry when email come to.
     */

    public function requestHandler($request)
    {
        $arrayData = json_decode(json_encode($request), true);
        $data      = json_decode(base64_decode($arrayData['message']['data']), true);

//        $data['historyId'] = 76535;

        try {

            if (isset($data['historyId'])) {

               $cookie = $this->docketConnector->login();
               $this->setPacerCookie($cookie);

               return $this->parseMessage();

            }

        } catch (\Exception $exception) {
            return Log::debug($exception->getMessage());
        }

        return 'done';
    }


    /**
     * @param $messageData
     *
     * Handler for each email existing in box.
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

                $mods = new Google_Service_Gmail_BatchModifyMessagesRequest();
                $mods->setAddLabelIds(array("Label_9106103313607392360"));
                $mods->setRemoveLabelIds(array("UNREAD", "INBOX"));
                $mods->setIds($messageData['messageID']);

                $this->gService->users_messages->batchModify(getenv('GOOGLE_DOCKET_EMAIL'), $mods);

            }
        }
    }


    /**
     * @return int
     *
     * Parser email. Get all unread email.
     */

    public function parseMessage()
    {
        $optParams               = [];
        $optParams['maxResults'] = 60;
        $optParams['labelIds']   = ['INBOX', 'UNREAD'];

        $messages     = $this->gService->users_messages->listUsersMessages(getenv('GOOGLE_DOCKET_EMAIL'), $optParams);
        $countMessage = 0;
        $ret = [];
        foreach ($messages->getMessages() as $item) {

            $messageId      = $item->getId();
            $message        = $this->gService->users_messages->get(getenv('GOOGLE_DOCKET_EMAIL'), $messageId);
            $messagePayload = $message->getPayload();
            $fromEmail      = $this->getFromEmailAddress($messagePayload->getHeaders());
            $emailSubject   = $this->getEmailSubject($messagePayload->getHeaders());
            $fedAbbr        = $this->getFedAbbrFromEmailAddress($fromEmail);

            if ($fedAbbr && $this->checkEmailSubject($emailSubject)) {
                $result = $this->getDataEmailBody($messagePayload->getBody()->getData());
                $result['fedAbbr'] = $fedAbbr;
                $result['docUrlData'] = $this->getEmailDocUrlData($messagePayload->getBody()->getData());
                $result['messageID'] = $messageId;
                //$ret[] = $result;
                $this->run($result);
                $countMessage++;
            }

        }
        //return $ret;
        return $countMessage;

    }


    /**
     * @param $messageBody
     * @return array
     *
     * Get data from email. Data from docket, docket_parties, docket_entries, docket_attachments.
     */

    public function getDataEmailBody($messageBody)
    {
        $body = strip_tags(base64_decode(strtr($messageBody, '-_', '+/')), '<p><b><br><a><tr><td>');
        $desc = null;
        $this->getParseHtmlDom()->load($body);
        $seqID = null;
        $caseNumber = $this->getParseHtmlDom()->find('tr', 1)->find('td', 1)->find('a', 0)->text;

        $attorneyText = StringHelper::getSubString($body, 'Notice has been electronically mailed to:', $caseNumber);

        foreach ($this->getParseHtmlDom()->find('tr') as $index => $tr) {
            if (strpos($tr->innerHtml, 'Document Number:') !== false) {
                $seqID = $this->getParseHtmlDom()->find('tr', $index)->find('td', 1)->find('a', 0)->text;
            }

            if (!is_numeric($seqID)) {
                $seqID = StringHelper::getSubString($this->getParseHtmlDom()->find('tr', $index)->find('td', 1)->text, ' ', '(');
            }
        }

        foreach ($this->getParseHtmlDom()->find('p') as $index => $p) {
            if (strpos($p->innerHtml, 'Docket Text:') !== false) {
                $desc = trim(strip_tags($this->getParseHtmlDom()->find('p', $index)->find('b', 0)->innerHtml));
            }
        }

        return [
            'caseNumber'  => $caseNumber,
            'caseName'    => $this->getParseHtmlDom()->find('tr', 0)->find('td', 1)->text,
            'dateEntered' => StringHelper::getSubString($this->getParseHtmlDom()->outerHtml, 'entered on ', 'at'),
            'dateFiled'   => StringHelper::getSubString($this->getParseHtmlDom()->outerHtml, 'filed on ', " "),
            'sequenceID'  => $seqID,
            'restricted'  => $this->isRestricted($desc),
            'sealed'      => $this->isSealed($desc),
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

            $string = trim(preg_replace('/&nbsp;|&nbsp|&amp;|nbsp/', '', $item));

            $newString = strstr($string, ',', true);

            if (!$newString) {
                $newString = $string;
            }

            $strrip = strripos($newString, ' ');

            return ['name' => trim(preg_replace('/\s+/', ' ', substr($newString, 0, $strrip))), 'email' => trim(substr($newString, $strrip))];

        }, $attorneyTextFiltered);

        return $attorneyData;
    }


    /**
     * @param $message
     * @return null
     *
     * Get email doc url data for fetching attachment.
     */

    public function getEmailDocUrlData($message)
    {
        $body = base64_decode(strtr($message, '-_', '+/'));

        $this->getParseHtmlDom()->load($body);

        $a = $this->getParseHtmlDom()->find('table', 0)->find('tr td a', 1);

        if (is_null($a)) {
            return null;
        }
        $query = parse_url($a->getAttribute('href'), PHP_URL_QUERY);
        parse_str($query, $params);
        $params['url']     = strstr($a->getAttribute('href'), '?', true);

        return $params;
    }


    public function getFedAbbrFromEmailAddress($email)
    {
        if (strpos($email, '.uscourts.gov') !== false) {
            return StringHelper::getSubString($email, '@', '.');
        }
        return false;
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


    public function getEmailSubject($messageHeaders)
    {
        $subject = null;

        foreach ($messageHeaders as $header) {
            if ($header->name != 'Subject') {
                continue;
            }
            $subject = $header->value;
        }
        return $subject;
    }


    public function getFromEmailAddress($messageHeaders)
    {
        $emailFrom = null;

        foreach ($messageHeaders as $header) {
            if ($header->name != 'From') {
                continue;
            }

            $emailFrom = $header->value;

        }
        return StringHelper::getSubString($emailFrom, '<', '>') ?: $emailFrom;
    }

}
