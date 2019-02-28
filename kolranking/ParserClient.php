<?php

namespace kolranking;

/**
 * Class ParserClient
 */
class ParserClient
{

    /** @var \GuzzleHttp\Client */
    public $client;

    private $pageRootUrl = 'https://kolranking.com';

    private $loginEmail = 'peterlau7520＠gmail.com';

    private $loginPassword = 'douyinventure2018';

    public $sessionRequestCount = 0;

    /** @var int */
    public $currentPageId;

    /** @var string */
    private $content;

    private $columnPattern = [
        0 => 'user_id',
        1 => 'avatar',
        2 => 'nickname',
        3 => 'gender',
        4 => 'fan',
        5 => 'praise',
        6 => 'video'
    ];

    /**
     * ParserClient constructor.
     * @param string $loginEmail
     * @param string $loginPassword
     */
    public function __construct()
    {
        $username = getenv('USERNAME');
        $password = getenv('PASSWORD');
        if ($username && $password) {
            $this->loginEmail = $username;
            $this->loginPassword = $password;
        }
    }


    /**
     * @return null|string
     * @throws \GuzzleHttp\Exception\GuzzleException|\Exception
     */
    private function getTokenFromLogin(): ?string
    {
        Events::log('pages', "Get token from login page.");
        $loginUrl = $this->pageRootUrl . '/login';
        $response = $this->getRequest($loginUrl);
        usleep($this->getSleepSec(ParserWorker::TIMEOUT_AFTER_TOKEN));
        $content = $response->getBody()->getContents();
        $xpath = $this->getXpath($content);
        $token = $xpath->query('//input[@name=\'_token\']/@value')->item(0)->nodeValue;

        if (!$token) {
            throw new \Exception('token not found');
        }

        return $token ?? null;
    }

    /**
     * @param int $end
     * @return int
     * @throws \Exception
     */
    public function getSleepSec($end)
    {
        return 0 === $end ? 0 : random_int(0, $end);
    }

    /**
     * @param $url
     * @return mixed|\Psr\Http\Message\ResponseInterface
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function getRequest($url)
    {
        $options = [
            'timeout' => ParserWorker::GUZZLE_CLIENT_TIMEOUT
        ];
        $this->ipCheck($options);
        try {
            $this->sessionRequestCount++;
            $request = $this->client->request('GET', $url, $options);
            if (429 === $request->getStatusCode()) {
                $this->captchaByPass($request->getBody()->getContents());
                $request = $this->getRequest($url);
            }
        } catch (\Exception $e) {
            Events::log('error', $e->getMessage());
        }


        return $request;
    }

    public function ipCheck(&$options)
    {
        if ($ipAddress = getenv('IP_ADDRESS')) {
            $options['curl'] = [
                CURLOPT_INTERFACE => $ipAddress
            ];
        }
    }

    /**
     * @param $content
     * @return bool
     * @throws \GuzzleHttp\Exception\GuzzleException|\Exception
     */
    public function captchaByPass($content)
    {
        Events::log('captcha', 'CAPTCHA detected - Request count: ' . $this->sessionRequestCount);
        usleep($this->getSleepSec(ParserWorker::CAPTCHA_TIMEOUT));
        $captchaHtml = ROOT . '/tmp/captcha.html';
        $localCaptchaFile = ROOT . '/tmp/captcha.png';
        file_put_contents($captchaHtml, $content);
        $xpath = $this->getXpath($content);
        $tokenQuery = $xpath->query("//div[@class='jumbotron']/form/input[@name='_token']");

        $tokenValue = $tokenQuery->item(0)->getAttribute('value');

        $formQuery = $xpath->query("//div[@class='jumbotron']/form");
        $formAction = $formQuery->item(0)->getAttribute('action');

        $imgQuery = $xpath->query("//div[@class='jumbotron']/form/div/img");
        $imgSrc = $imgQuery->item(0)->getAttribute('src');


        $remoteCaptcha = $this->client->request('GET', $imgSrc, ['timeout' => ParserWorker::GUZZLE_CLIENT_TIMEOUT,]);
        $remoteCaptchaImg = $remoteCaptcha->getBody()->getContents();

        file_put_contents($localCaptchaFile, $remoteCaptchaImg);

        $antiCaptcha = new AntiCaptcha();
        $captchaCheck = false;
        $captchaText = $antiCaptcha->getText($localCaptchaFile);

        $captchaFullAction = $this->pageRootUrl . $formAction;
        if ($captchaText) {
            $captchaCheck = true;
            $captchaRequest = $this->postRequest($captchaFullAction, [
                '_token' => $tokenValue,
                'captcha' => $captchaText
            ]);
            if ($captchaRequest->getStatusCode() === 200) {
                Events::log('captcha', 'CAPTCHA bypass success!');
                $this->sessionRequestCount = 0;
            }
        }
        @unlink($captchaHtml);
        @unlink($localCaptchaFile);

        return $captchaCheck;
    }

    /**
     * @param $url
     * @param $formParams
     * @return mixed|\Psr\Http\Message\ResponseInterface
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function postRequest($url, $formParams)
    {
        $options = [
            'timeout' => ParserWorker::GUZZLE_CLIENT_TIMEOUT,
            'form_params' => $formParams
        ];
        $this->ipCheck($options);

        try {
            $this->sessionRequestCount++;
            $request = $this->client->request('POST', $url, $options);
            if (429 === $request->getStatusCode()) {
                $this->captchaByPass($request->getBody()->getContents());
                $request = $this->postRequest($url, $formParams);
            }
        } catch (\Exception $e) {
            Events::log('error', $e->getMessage());
        }

        return $request;
    }

    /**
     * @return bool
     * @throws \GuzzleHttp\Exception\GuzzleException|\Exception
     */
    public function loginToHomepage(): bool
    {
        $token = $this->getTokenFromLogin();

        $loginUrl = $this->pageRootUrl . '/login';
        $response = $this->postRequest($loginUrl, [
            '_token' => $token,
            'email' => $this->loginEmail,
            'password' => $this->loginPassword,
        ]);
        Events::log('pages', "Logged in to home page.");
        usleep($this->getSleepSec(ParserWorker::TIMEOUT_AFTER_HOMEPAGE));

        $this->content = $response->getBody()->getContents();
        return true;
    }

    /**
     * @return bool
     * @throws \GuzzleHttp\Exception\GuzzleException|\Exception
     */
    public function openCurrentPage(): bool
    {
        $fileName = ROOT . '/data/pages/' . $this->currentPageId . '.html';
        if (file_exists($fileName)) {
            Events::log('users', "Page {$this->currentPageId} - html file already exists.");
            $this->content = file_get_contents($fileName);
        } else {
            $response = $this->getRequest($this->getCurrentPageUrl());
            Events::log('pages', 'Page ' . $this->getCurrentPageId() . ' opened');
            usleep($this->getSleepSec(ParserWorker::TIMEOUT_AFTER_PAGE));

            $this->content = $response->getBody()->getContents();
            file_put_contents($fileName, $this->content);
        }

        return true;
    }

    /**
     * @return array
     * @throws \GuzzleHttp\Exception\GuzzleException|\Exception
     */
    public function parsePageContent()
    {
        $xpath = $this->getXpath($this->content);
        $userList = $xpath->query('//table[@class=\'table user-list\']/tbody/tr');
        if (0 === $userList->length) {
            unlink(ROOT . "/data/pages/{$this->getCurrentPageId()}.html");
            throw new \Exception('user not found in page');
        }
        $nextPage = $xpath->query('//ul[@class=\'pagination\']//li');
        $nextPageA = $nextPage->item(1)->getElementsByTagName('a');
        $nextPageUrl = $nextPageA->item(0)->getAttribute('href');
        if (!$nextPageUrl) {
            exit;
        }


        $userListOut = [];
        /** @var \DOMElement $user */
        foreach ($userList as $user) {
            $columns = $user->getElementsByTagName('td');
            $row = $this->getUserData($columns);
            $userListOut[] = $row;
        }

        return $userListOut;
    }

    /**
     * @param $pageData
     * @return bool
     * @throws \Exception
     */
    public function pageToCSV($pageData, $force = false): bool
    {
        $csvPath = ROOT . '/csv/pages/' . $this->currentPageId . '.csv';
        if (file_exists($csvPath) && !$force) {
            file_put_contents(ROOT . '/log/last_page.log', $this->currentPageId);
            Events::log('pages', "Page {$this->currentPageId} - csv file already exists.");
            return false;
        }

        $csvFile = fopen($csvPath, 'w');
        fputcsv($csvFile, array_keys($pageData[0])); // add headers
        foreach ($pageData as $user) {
            fputcsv($csvFile, array_values($user));
        }
        fclose($csvFile);
        file_put_contents(ROOT . '/log/last_page.log', $this->currentPageId);
        Events::log('pages', "Page {$this->currentPageId} - csv file generated.");

        return true;
    }

    /**
     * @param $columns
     * @return array
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    private function getUserData($columns)
    {
        $row = [];
        /** @var \DOMElement $column */
        foreach ($columns as $key => $column) {
            $slug = $this->columnPattern[$key];
            switch ($slug) {
                case 'avatar':
                    {
                        $row[$slug] = $this->getImageUrl($column);
                        break;
                    }
                case 'fan':
                    {
                        $row[$slug] = $this->getImageUrl($column);
                        break;
                    }
                case 'praise':
                    {
                        $row[$slug] = $this->getImageUrl($column);
                        break;
                    }
                case 'video':
                    {
                        $row[$slug] = $this->getImageUrl($column);
                        break;
                    }
                case 'nickname':
                    {
                        $nickname = $this->getNickName($column);
                        $row['user_url'] = $nickname['user_url'];
                        $row['backend_nickname'] = $nickname['backend_nickname'];
                        $row['backend_user_id'] = $nickname['backend_user_id'];
                        $row['nickname'] = $nickname['nickname'];

                        $userPage = $this->openUserPage($nickname['user_url']);
                        if (!$userPage) {
                            exit;
                        }
                        $row['userpage_id'] = $userPage['userpage_id'];
                        $row['user_note1'] = $userPage['user_note1'];
                        $row['user_note2'] = $userPage['user_note2'];

                        /** @var array $userInfoList */
                        $userInfoList = !empty($userPage['user_info_list']) ? $userPage['user_info_list'] : [];
                        if ($userInfoList) {
                            foreach ($userInfoList as $userInfoKey => $userInfo) {
                                $row[$userInfoKey] = $userInfo;
                            }
                        }

                        break;
                    }
                default:
                    {
                        $row[$slug] = trim($column->nodeValue);
                        break;
                    }
            }
        }
        return $row;
    }

    /**
     * @param \DOMElement $data
     * @return array
     */
    private function getNickName(\DOMElement $data): array
    {
        $a = $data->getElementsByTagName('a');
        $href = $a->item(0)->getAttribute('href');
        $hrefSplit = explode('/', $href);
        return [
            'user_url' => $href,
            'backend_nickname' => $hrefSplit[1],
            'backend_user_id' => $hrefSplit[3],
            'nickname' => $data->nodeValue
        ];
    }

    /**
     * @param string $url
     * @return array
     * @throws \GuzzleHttp\Exception\GuzzleException|\Exception
     */
    public function openUserPage(string $url)
    {
        $urlSplit = explode('/', $url);
        $backendUserId = $urlSplit[3];
        $fileName = ROOT . '/data/users/' . $backendUserId . '.html';
        if (file_exists($fileName)) {
            Events::log('users', "User {$backendUserId} - html file already exists.");
            $content = file_get_contents($fileName);
        } else {

            $fullUrl = $this->pageRootUrl . $url;
            $response = $this->getRequest($fullUrl);
            Events::log('users', "User {$backendUserId} - opened user page.");
            usleep($this->getSleepSec(ParserWorker::TIMEOUT_AFTER_USER_PAGE));

            $content = $response->getBody()->getContents();
            file_put_contents($fileName, $content);
        }
        $xpath = $this->getXpath($content);

        $xpathUserId = $xpath->query('/html[1]/body[1]/div[1]/div[1]/div[1]/div[1]/div[3]/div[1]/p[3]');
        $userIdValue = $xpathUserId->length === 0 ? null : $xpathUserId->item(0)->nodeValue;

        $xpathUserNotes = $xpath->query('//p[@class=\'dyuser-note\']');
        $userNote1 = $xpathUserNotes->length > 0 ? $xpathUserNotes->item(0)->nodeValue : null;
        $userNote2 = $xpathUserNotes->length > 1 ? $xpathUserNotes->item(1)->nodeValue : null;



        $userInfoListOut = [];
        $userAccountInformationList = $xpath->query("//div[@class='col-md-6 col-sm-12']//p");
        if ($userAccountInformationList->length > 0) {
            /** @var \DOMElement $info */
            foreach ($userAccountInformationList as $info) {
                $value = $info->nodeValue;
                if (false !== strpos($value, '=')) {
                    $valueExp = explode('=', $value);
                    $userInfoListOut[trim($valueExp[0])] = trim($valueExp[1]);
                }
            }
        }

        return [
            'userpage_id' => trim($userIdValue),
            'user_note1' => trim($userNote1),
            'user_note2' => trim($userNote2),
            'user_info_list' => $userInfoListOut
        ];
    }

    /**
     * @param \DOMElement $data
     * @return null|string
     */
    private function getImageUrl(\DOMElement $data): ?string
    {
        $img = $data->getElementsByTagName('img');

        return $img->length === 0 ? null : $img->item(0)->getAttribute('src');
    }

    /**
     * @return ParserClient
     */
    public function setClient(): self
    {

        $cookieJar = new \GuzzleHttp\Cookie\CookieJar();
        $this->client = new \GuzzleHttp\Client([
            'cookies' => $cookieJar,
            'verify' => false,
            'allow_redirects' => true,
            'http_errors' => false,
            'headers' => [
                'Referer' => $this->pageRootUrl,
                'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_13_6) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/69.0.3497.100 Safari/537.36'
            ],
        ]);

        return $this;
    }

    /**
     * @param string $content
     * @return \DOMXPath
     */
    private function getXpath(string $content): \DOMXPath
    {
        $doc = new \DOMDocument();
        $internalErrors = libxml_use_internal_errors(true);
        $doc->loadHTML($content);
        libxml_use_internal_errors($internalErrors);
        return new \DOMXPath($doc);
    }

    /**
     * @return int
     */
    public function getCurrentPageId(): int
    {
        return $this->currentPageId;
    }

    /**
     * @return $this
     */
    public function incCurrentPageId()
    {
        $this->currentPageId++;

        return $this;
    }

    /**
     * @param mixed $currentPageId
     * @return ParserClient
     */
    public function setCurrentPageId($currentPageId)
    {
        $this->currentPageId = $currentPageId;
        return $this;
    }

    /**
     * @return string
     */
    public function getCurrentPageUrl(): string
    {
        return "https://kolranking.com/home?ot=DESC&order=follower_count&page={$this->getCurrentPageId()}";
    }
}