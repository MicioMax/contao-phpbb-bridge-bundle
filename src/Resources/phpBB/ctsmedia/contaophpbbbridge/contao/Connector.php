<?php
/*
 * This file is part of contao-phpbbBridge
 *
 * Copyright (c) CTS GmbH
 * 
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 * 
 */

namespace ctsmedia\contaophpbbbridge\contao;

use Buzz\Browser;
use Buzz\Client\Curl;
use Buzz\Listener\CookieListener;
use Buzz\Message\RequestInterface;
use Buzz\Message\Response;
use Buzz\Util\Cookie;
use Buzz\Util\CookieJar;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use phpbb\auth\auth;
use phpbb\db\driver\mysql;
use phpbb\request\request;
use phpbb\request\request_interface;
use phpbb\user;

require_once __DIR__ . "/../vendor/autoload.php";

/**
 *
 * @package ctsmedia\contaophpbbbridge\contao
 * @author Daniel Schwiperich <d.schwiperich@cts-media.eu>
 */
class Connector
{
    protected $isBridgeInstalled;
    protected $forum_pageId;
    protected $contao_url;
    protected $contaoDb;
    protected $contaoDbConfig;

    protected $debug = false;

    protected $user;

    protected $auth;

    protected $request;

    protected $cookieAppendix = '';

    public function __construct(
        $isBridgeInstalled,
        $forum_pageId,
        $contao_url,
        $contaoDbConfig,
        user $user,
        auth $auth,
        request $request
    ) {
        $this->isBridgeInstalled = (bool)$isBridgeInstalled;
        $this->forum_pageId = $forum_pageId;
        $this->contao_url = $contao_url;
        $this->user = $user;
        $this->auth = $auth;
        $this->request = $request;

        $this->contaoDbConfig = $contaoDbConfig;
        $this->contaoDb = null;

        $this->logger = new Logger('bridge_connector');
        $this->logger->pushHandler(new StreamHandler(__DIR__.'/../bridge_error.log'), Logger::ERROR);
        if($this->debug === true) {
            $this->logger->pushHandler(new StreamHandler(__DIR__.'/../bridge.log'), Logger::DEBUG);
        }
    }

    /**
     * Checks if the bridge is mark as installed on phpbb side
     *
     * @return bool
     */
    public function isInstalled()
    {
        return $this->isBridgeInstalled;
    }

    /**
     * Tests if the session user is logged in
     *
     * @return bool
     */
    public function isLoggedIn(){
        return ($this->user->data['user_id'] != ANONYMOUS) ? true : false;
    }

    public function test()
    {
        $browser = $this->initContaoRequest();
        $headers = $this->initContaoRequestHeaders();
        $response = $browser->get($this->contao_url . '/phpbb_bridge/test', $headers);

        //dump($response->getContent());
        echo $response->getContent();
        exit;

    }

    /**
     * Ask Contao if it can autologin the current user / session
     * and return the authenticated phpbb user id if found
     *
     * THIS IS NOT ONLY for autologin session also for normal sessions
     * where the user has already logged in to contao but not to phpbb
     * 
     * @throws \InvalidArgumentException
     * @return int phpBB User ID
     */
    public function autologin(){
        $userId = ANONYMOUS;

        if($this->debug) $this->logger->debug(__METHOD__);

        // First tests if we found a contao autologin OR a contao auth cookie.
        // otherwise we can stop here
        $cookies = $this->request->variable_names(request_interface::COOKIE);
        $autoLoginCookieFound = false;
        foreach ($cookies as $cookieName) {
            if(strpos($cookieName, 'FE_AUTO_LOGIN') === 0 || strpos($cookieName, 'FE_USER_AUTH') === 0){
                $autoLoginCookieFound = true;
                continue;
            }
        }
        
        if($autoLoginCookieFound !== true) {
            throw new \InvalidArgumentException('No Autologin Cookie found');
        }
        
        // Init request
        $browser = $this->initContaoRequest();
        $headers = $this->initContaoRequestHeaders();

        // Check contao for autologin
        /* @var $response Response */
        $response = $browser->get($this->contao_url . '/phpbb_bridge/autologin', $headers);

        // Contao usually triggers a reload on autologin. We've to catch that here
        // Wo do ONE retry. the http lib already merges the Cookies like FE_AUTH from the last response
        if($response->getStatusCode() === 303) {
            $response = $browser->get($this->contao_url . '/phpbb_bridge/autologin', $headers);
        }

        if ($this->isJsonResponse($response)) {
            $jsonData = json_decode($response->getContent());
            // We found a logged in user. yay
            if($jsonData->is_logged_in && $jsonData->user_id > ANONYMOUS){
                $userId = $jsonData->user_id;

                // Append the FE_USER_AUTH Cookie to the current request, so followed request like loadlayout don't have
                // to run through the autologin / 303 process
                foreach($browser->getListener()->getCookies() as $cookie) {
                    if($cookie->getName() == 'FE_USER_AUTH' && !$cookie->isExpired()){
                        $this->cookieAppendix = '; FE_USER_AUTH='.$cookie->getValue();
                        continue;
                    }
                }
            }
        // Still no json response. nay :/
        } else {
            $this->logger->error("Json Response expected. Got: ", array('status' => $response->getStatusCode(), 'content' => $response->getContent()));
        }

        return $userId;
    }

    /**
     * Send a login request to contao
     *
     * @param $username
     * @param $password
     * @return bool true if the login was successful
     */
    public function login($username, $password, $autologin = false)
    {
        $result = [
            'status' => false,
            'code'   => ''
        ];

        // The request comes from contao. Maybe from a hook like credentialCheck, importUser so we skip
        if ($this->request->header('X-Requested-With') == 'ContaoPhpbbBridge') {
            return $result;
        };

        // Init request
        $browser = $this->initContaoRequest();
        $headers = $this->initContaoRequestHeaders();
        $formFields = array(
            'username' => $username,
            'password' => $password,
            'autologin' => (bool)$autologin,
        );

        // Send request as form data
        /* @var $response Response */
        $response = $browser->submit($this->contao_url . "/phpbb_bridge/login", $formFields,
            RequestInterface::METHOD_POST, $headers);

        if ($this->isJsonResponse($response)) {
            $jsonData = json_decode($response->getContent());

            if ($jsonData->status == true) {
                $this->sendCookiesFromResponse($response);
            }
            $result['status'] = $jsonData->status;
            $result['code'] = $jsonData->code;
        }
        return $result;
    }


    /**
     * Send a logout request to contao
     *
     * @return bool if the logout was successful
     */
    public function logout()
    {
        // The request comes from contao. We can skip here
        if ($this->request->header('X-Requested-With') == 'ContaoPhpbbBridge') {
            return false;
        };

        $browser = $this->initContaoRequest();
        $headers = $this->initContaoRequestHeaders();

        // This is usually a fire and forget request
        // but on contao side we return a Json response for debugging purposes
        $response = $browser->get($this->contao_url . '/phpbb_bridge/logout', $headers);

        if ($this->isJsonResponse($response)) {
            $jsonData = json_decode($response->getContent());
            if (isset($jsonData->logout_status)) {
                $this->sendCookiesFromResponse($response);
                return $jsonData->logout_status;
            }
        }
        return false;
    }

    /**
     * Returns the layout sections and refreshes User Session
     *
     * @return array
     */
    public function loadLayout()
    {
        $sections = array();
        // The request comes from contao. Maybe from a hook like credentialCheck, importUser so we skip
        if ($this->request->header('X-Requested-With') == 'ContaoPhpbbBridge') {
            return $sections;
        };

        if($this->debug) $this->logger->debug(__METHOD__);

        $browser = $this->initContaoRequest();
        $headers = $this->initContaoRequestHeaders(true);

        /* @var $response Response */
        $response = $browser->get($this->contao_url . '/phpbb_bridge/layout', $headers);

        // Maybe we get asked to refresh the current site. This can happen if an session is expired and autologin is triggered
        // Wo do this one time
        // @see Contao/FrontendUser::authenticate() => Controller::reaload()
        if ($response->getStatusCode() == 303) {
            // we expect a FE_AUTH cookie which will get set for new requests automatically via the cookie listener
            $response = $browser->get($this->contao_url . '/phpbb_bridge/layout', $headers);
        }

        // Refresh user session data from contao
        $this->sendCookiesFromResponse($response);

        if ($this->isJsonResponse($response)) {
            $sections = $jsonData = json_decode($response->getContent());
        }

        return $sections;
    }

    /**
     * Sends a ping to contao to keep the session alive
     * Expects a Json Response and the updated cookies within
     *
     * @return bool
     */
    public function syncContaoSession() {

        // The request comes from contao. We can skip here
        if ($this->request->header('X-Requested-With') == 'ContaoPhpbbBridge') {
            return false;
        };

        $browser = $this->initContaoRequest();
        $headers = $this->initContaoRequestHeaders();

        /* @var $response Response */
        $response = $browser->get($this->contao_url . '/phpbb_bridge/ping', $headers);

        if ($this->isJsonResponse($response) && $response->getHeader('set-cookie')) {
            $this->sendCookiesFromResponse($response);
            return true;
        }

        return false;
    }


    /**
     * Returns a Contao User by username
     *
     * [
     *  id
     *  firstname
     *  lastname
     *  dateOfBirth
     *  email
     *  groups
     *  login
     *  username
     *  autologin
     *  ...
     * ]
     *
     * @param $username string
     * @return array|bool
     */
    public function getContaoUser($username) {
        $row = false;

        if($this->getContaoDbConnection()) {
            $sql = 'SELECT * FROM tl_member WHERE username = '.
                $this->getContaoDbConnection()->_sql_validate_value($username).
                ' LIMIT 1';
            $result = $this->getContaoDbConnection()->sql_query($sql);
            $row = $this->getContaoDbConnection()->sql_fetchrow($result);
        }

        return $row;
    }

    /**
     * Contao DB Connection
     *
     * @return null|mysql
     */
    public function getContaoDbConnection(){
        if($this->contaoDb === null) {
            $this->contaoDb = new mysql();
            $this->contaoDb->sql_connect(
                $this->contaoDbConfig['host'],
                $this->contaoDbConfig['user'],
                $this->contaoDbConfig['password'],
                $this->contaoDbConfig['dbname'],
                $this->contaoDbConfig['port']
            );
        }

        return $this->contaoDb;
    }

    /**
     *
     * This code is copied from Ctsmedia\Phpbb\BridgeBundle\PhpBB\Connector
     * @return Browser
     */
    protected function initContaoRequest()
    {
        // Init Request
        $client = new Curl();
        $client->setMaxRedirects(0);
        $browser = new Browser();
        $browser->setClient($client);
        $cookieListener = new CookieListener();
        $browser->addListener($cookieListener);

        return $browser;
    }

    /**
     * Parse current request and build forwarding headers
     * @return array
     */
    protected function initContaoRequestHeaders($allowCookieAppendix = false)
    {
        $headers = array();
        if ($this->request->header('User-Agent')) {
            $headers[] = 'User-Agent: ' . $this->request->header('User-Agent');
        }
        // Set the forward header. Our own IP gets added automatically (by the client?)
        if ($this->request->header('X-Forwarded-For')) {
            $headers[] = 'X-Forwarded-For: ' . $this->request->header('X-Forwarded-For');
        } else {
            $headers[] = 'X-Forwarded-For: ' . $this->request->server('REMOTE_ADDR');
        }
        if ($this->request->header('Cookie')) {
            $headers[] = 'Cookie: ' . $this->request->header('Cookie') . ( ($allowCookieAppendix) ? $this->cookieAppendix : '');
        }
        if ($this->request->header('Referer')) {
            $headers[] = 'Referer: ' . $this->request->header('Referer');
        }

        // Add a special header (usually used for ajax but context is correct here)
        // so we can set a flag to not trigger an endless request loop
        // because contao hooks trigger requests to phpbb on contao login for example
        $headers[] = 'X-Requested-With: ContaoPhpbbBridge';

        return $headers;
    }

    /**
     * Checks if we had a successfull response with Json content in it
     *
     * @param Response $response
     * @return bool
     */
    protected function isJsonResponse(Response $response)
    {
        return $response->getStatusCode() == 200 && $response->getHeader('content-type') == 'application/json';
    }

    /**
     * Send cookies to client from a contao response object
     *
     * @param Response $response
     * @return bool
     */
    protected function sendCookiesFromResponse(Response $response) {
        // Set cookies from the contao response
        if ($response->getHeader('set-cookie')) {

            $delimiter = ' || ';
            $cookies = explode($delimiter, $response->getHeader('set-cookie', $delimiter));

            foreach ($cookies as $cookie) {
                header('Set-Cookie: ' . $cookie, false);
            }

            // The following won't work because the expire value is not an int and conversion something like
            // 16-Jan-2016 18:07:35 GMT to an int is really unnecessary overhead
            // although it's looks cleaner at first like above solution
            // $cookieJar = new CookieJar();
            // $cookieJar->processSetCookieHeaders($browser->getLastRequest(), $response);
            // foreach($cookieJar->getCookies() as $cookie) {
            //      setcookie($cookie->getName(), $cookie->getValue(), $cookie->getAttribute('expires'), $cookie->getAttribute('path'), $cookie->getAttribute('domain'), $cookie->getAttribute('secure'),$cookie->getAttribute('httponly'));
            // }                  }
        }
    }

}