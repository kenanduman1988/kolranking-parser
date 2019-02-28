<?php

namespace kolranking;

/**
 * Class ParserRun
 * @package kolranking
 */
class ParserWorker
{
    public const TIMEOUT_AFTER_TOKEN = 500;
    public const TIMEOUT_AFTER_HOMEPAGE = 500;
    public const TIMEOUT_AFTER_PAGE = 150;
    public const TIMEOUT_AFTER_USER_PAGE = 100;
    public const CAPTCHA_TIMEOUT = 500;
    public const GUZZLE_CLIENT_TIMEOUT = 300;
    /** @var ParserClient */
    private $parserClient;

    /**
     * @return self
     */
    public function initParserClient(): self
    {
        $this->parserClient = new ParserClient();
        $this->parserClient->setClient();
        return $this;
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException|\Exception
     */
    public function perform()
    {
        $loginCount = 0;
        do {
            $loggedIn = false;
            try {
                $loginCount++;
                $loggedIn = $this->parserClient->loginToHomepage();
            } catch(\Exception $e) {
                Events::log('error', 'Homepage login tried: '. $loginCount);
            }
        } while (!$loggedIn && $loginCount <= 3);


        $lastPageId = trim(file_get_contents(ROOT . '/log/last_page.log'));
        if ($lastPageId) {
            $this->parserClient->setCurrentPageId((int)$lastPageId);
        } else {
            $pageData = $this->parserClient->parsePageContent();
            $this->parserClient->pageToCSV($pageData);
            $this->parserClient->setCurrentPageId(0);
        }
        $this->parserClient->incCurrentPageId();

        return $this;
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function startParsingProcess()
    {
        try {
            do {
                if ($this->parserClient->openCurrentPage()) {
                    $status = true;
                    $data = $this->parserClient->parsePageContent();
                    if ($data) {
                        $this->parserClient->pageToCSV($data);
                    }
                    $this->parserClient->incCurrentPageId();
                } else {
                    Events::log('error', "Page {$this->parserClient->currentPageId} can not opened.");
                    $status = false;
                }
            } while ($status);
        } catch (\Exception $e) {
            Events::log('error', sprintf(
                'Line: %s, File: %s, Message: %s, Trace: %s',
                $e->getLine(),
                $e->getFile(),
                $e->getMessage(),
                $e->getTraceAsString()

            ));
        }
    }

    /**
     * @return ParserClient
     */
    public function getParserClient(): ParserClient
    {
        return $this->parserClient;
    }
}
