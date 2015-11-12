<?php
/**
 * Vtiger Web Services PHP Client Library (vtwsphpclib)
 *
 * Inspired by vtwsclib – vtiger CRM Web Services Client Library version 1.4
 * Build with Guzzle. Thanks!
 *
 * The MIT License (MIT)
 *
 * Copyright (c) 2014, Valentin Despa <info@vdespa.de>. All rights reserved.
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 *
 * @author    Valentin Despa <info@vdespa.de>
 * @copyright 2014 Valentin Despa <info@vdespa.de>
 * @license   The MIT License (MIT)
 */

namespace Vdespa\Vtiger;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Vtiger Web Services Client.
 *
 * Class WSClient
 *
 * @package Vdespa\Vtiger
 */
class WSClient
{
    /**
     * Default PHP script for web services on Vtiger installation
     *
     * @var string
     */
    protected $wsFileName = 'webservice.php';

    /**
     * Complete URL
     *
     * @var string
     */
    protected $vtigerWebServiceURL;

    /**
     * HTTP Client
     *
     * @var \GuzzleHttp\Client
     */
    protected $httpClient;

    /**
     * Session name
     *
     * @var string
     */
    protected $sessionName;

    /**
     * User Id
     *
     * @var string
     */
    protected $userId;

    /**
     * Web Service Version
     *
     * @var string
     */
    protected $apiVersion;

    /**
     * Vtiger version
     *
     * @var string
     */
    protected $vtigerVersion;

    /**
     * Last error
     *
     * @var WSClientError
     */
    protected $lastError;

    /**
     * Constructor.
     *
     * todo $url should be packed in the config.
     */
    public function __construct($url, $config = array())
    {
        // Build the URL
        $this->vtigerWebServiceURL = $this->buildWebServiceURL($url, $config);

        // Create HTTP client
        if (array_key_exists('testing', $config) && array_key_exists('client', $config['testing']) && $config['testing']['client'] instanceof Client) {
            $this->httpClient = $config['testing']['client'];
        } else {
            $this->httpClient = new Client();
        }

        // Login
        if (array_key_exists('username', $config['auth']) && array_key_exists('accesskey', $config['auth'])) {
            $this->login($config['auth']['username'], $config['auth']['accesskey']);
        } else {
            $this->lastError = new WSClientError(
                'NO_CREDENTIALS_FOUND',
                'Username or accesskey not provided.',
                var_export($config)
            );
        }
    }

    /**
     * @param ResponseInterface $response
     * @return mixed
     */
    private function asArray(ResponseInterface $response)
    {
        return json_decode($response->getBody(), true);
    }

    /**
     * Build the URL based on the base url and the config.
     *
     * @param string $url
     * @param array $config
     * @return string
     */
    protected function buildWebServiceURL($url, array $config)
    {
        // Check if URL already contains 'webservice.php'
        if (stripos($url, $this->wsFileName) === false) {
            // If the URL does not end with a slash, add one.
            if (strripos($url, '/') != (strlen($url) - 1)) {
                $url .= '/';
            }
            $url .= $this->wsFileName;
        }

        // Add additional parameters to the request
        if (array_key_exists('query-params', $config) && !empty($config['query-params'])) {
            $url .= "?" . http_build_query($config['query-params']);
        }

        return $url;
    }

    /**
     * Perform Login Operation.
     *
     * @param string $username
     * @param string $accessKey
     * @return bool
     */
    protected function login($username, $accessKey)
    {
        $challenge = $this->getChallenge($username);

        if ($challenge !== false) {
            try {
                $response = $this->httpClient->request('POST', $this->vtigerWebServiceURL, [
                    RequestOptions::FORM_PARAMS => [
                        'operation' => 'login',
                        'username'  => $username,
                        'accessKey' => md5($challenge . $accessKey)
                    ]
                ]);

                $json = $this->asArray($response);

                if ($json['success'] === true) {
                    $this->sessionName   = $json['result']['sessionName'];
                    $this->userId        = $json['result']['userId'];
                    $this->apiVersion    = $json['result']['version'];
                    $this->vtigerVersion = $json['result']['vtigerVersion'];

                    return true;
                }

                $this->setLastError($json);
            } catch (GuzzleException $ex) {
                // TODO: handle exception properly
            }
        }

        return false;
    }

    /**
     * Close current session
     *
     * @return bool
     */
    public function logout()
    {
        try {
            $response = $this->httpClient->request('GET', $this->vtigerWebServiceURL, [
                RequestOptions::QUERY => [
                    'operation'   => 'logout',
                    'sessionName' => $this->sessionName
                ]
            ]);

            $json = $this->asArray($response);

            if ($json['success'] === true) {
                return true;
            }

            $this->setLastError($json);
        } catch (GuzzleException $ex) {
            // TODO: handle exception properly
        }

        return false;
    }

    /**
     * Get a challenge token from the server
     *
     * @param string $username
     * @return string | bool
     */
    protected function getChallenge($username)
    {
        try {
            $response = $this->httpClient->request('GET', $this->vtigerWebServiceURL, [
                RequestOptions::QUERY => [
                    'operation' => 'getchallenge',
                    'username'  => $username
                ]
            ]);

            $json = $this->asArray($response);

            if ($json['success'] === true) {
                return $json['result']['token'];
            }

            $this->setLastError($json);
        } catch (GuzzleException $e) {
            $this->lastError = new WSClientError(
                $e->getCode(),
                $e->getMessage(),
                null
            );
        }

        return false;
    }

    /**
     * Provides a list of available modules.
     *
     * This list only contains modules the logged in user has access to.
     *
     * @return array | bool
     */
    public function getAvailableModules()
    {
        try {
            $response = $this->httpClient->request('GET', $this->vtigerWebServiceURL, [
                RequestOptions::QUERY => [
                    'operation'   => 'listtypes',
                    'sessionName' => $this->sessionName
                ]
            ]);

            $json = $this->asArray($response);

            if ($json['success'] === true) {
                return $json['result']['types'];
            }

            $this->setLastError($json);
        } catch (GuzzleException $ex) {
            // TODO: handle exception properly
        }

        return false;
    }

    /**
     * Describes a Vtiger module
     *
     * @param $moduleName
     * @return array | bool
     */
    public function getModuleDescription($moduleName)
    {
        try {
            $response = $this->httpClient->request('GET', $this->vtigerWebServiceURL, [
                RequestOptions::QUERY => [
                    'operation'   => 'describe',
                    'sessionName' => $this->sessionName,
                    'elementType' => $moduleName
                ]
            ]);

            $json = $this->asArray($response);

            if ($json['success'] === true) {
                return $json['result'];
            }

            $this->setLastError($json);
        } catch (GuzzleException $ex) {
            // TODO: handle exception properly
        }

        return false;
    }

    /**
     * Create a new object
     *
     * @param $moduleName
     * @param array $data
     * @return bool
     */
    public function createObject($moduleName, array $data)
    {
        // Use current user if no user is specified
        if (!array_key_exists('assigned_user_id', $data)) {
            $data['assigned_user_id'] = $this->userId;
        }

        // Encode as JSON
        $element = json_encode($data);

        try {
            $response = $this->httpClient->request('POST', $this->vtigerWebServiceURL, [
                RequestOptions::FORM_PARAMS => [
                    'operation'   => 'create',
                    'sessionName' => $this->sessionName,
                    'elementType' => $moduleName,
                    'element'     => $element
                ]
            ]);

            $json = $this->asArray($response);

            if ($json['success'] === true) {
                return $json['result'];
            }

            $this->setLastError($json);
        } catch (GuzzleException $ex) {
            // TODO: handle exception properly
        }

        return false;
    }

    /**
     * Retrieve existing object
     *
     * @param $recordId
     * @return bool
     */
    public function retrieveObject($recordId)
    {
        try {
            $response = $this->httpClient->request('GET', $this->vtigerWebServiceURL, [
                RequestOptions::QUERY => [
                    'operation'   => 'retrieve',
                    'sessionName' => $this->sessionName,
                    'id'          => $recordId
                ]
            ]);

            $json = $this->asArray($response);

            if ($json['success'] === true) {
                return $json['result'];
            }

            $this->setLastError($json);
        } catch (GuzzleException $ex) {
            // TODO: handle exception properly
        }

        return false;
    }

    /**
     * Update object
     *
     * It will first retrieve the object from the database and merge the new information
     *
     * @param array $data
     * @return bool
     */
    public function updateObject(array $data)
    {
        // Retrieve data
        $initialObject = $this->retrieveObject($data['id']);

        if ($initialObject !== false) {
            // Merge old and new data
            $updatedObject = array_merge($initialObject, $data);

            // Encode as JSON
            $element = json_encode($updatedObject);

            try {
                $response = $this->httpClient->request('POST', $this->vtigerWebServiceURL, [
                    RequestOptions::FORM_PARAMS => [
                        'operation'   => 'update',
                        'sessionName' => $this->sessionName,
                        'element'     => $element
                    ]
                ]);

                $json = $this->asArray($response);

                if ($json['success'] === true) {
                    return $json['result'];
                }

                $this->setLastError($json);
            } catch (GuzzleException $ex) {
                // TODO: handle exception properly
            }
        }

        return false;
    }

    /**
     * Delete object
     *
     * @param $recordId
     * @return bool
     */
    public function deleteObject($recordId)
    {
        try {
            $response = $this->httpClient->request('POST', $this->vtigerWebServiceURL, [
                RequestOptions::FORM_PARAMS => [
                    'operation'   => 'delete',
                    'sessionName' => $this->sessionName,
                    'id'          => $recordId
                ]
            ]);

            $json = $this->asArray($response);

            if ($json['success'] === true) {
                return $json['result'];
            }

            $this->setLastError($json);
        } catch (GuzzleException $ex) {
            // TODO: handle exception properly
        }

        return false;
    }

    /**
     * Simple query mechanism
     *
     * @param $query
     * @return bool
     */
    public function query($query)
    {
        try {
            $response = $this->httpClient->request('GET', $this->vtigerWebServiceURL, [
                RequestOptions::QUERY => [
                    'operation'   => 'query',
                    'sessionName' => $this->sessionName,
                    'query'       => $query
                ]
            ]);

            $json = $this->asArray($response);

            if ($json['success'] === true) {
                return $json['result'];
            }

            $this->setLastError($json);
        } catch (GuzzleException $ex) {
            // TODO: handle exception properly
        }

        return false;
    }

    /**
     * Sync will return a SyncResult object containing details of changes after modifiedTime.
     *
     * @param string $moduleName
     * @param int $modifiedTime
     * @return array | bool
     */
    public function sync($moduleName, $modifiedTime)
    {
        try {
            $response = $this->httpClient->request('GET', $this->vtigerWebServiceURL, [
                RequestOptions::QUERY => [
                    'operation'    => 'sync',
                    'sessionName'  => $this->sessionName,
                    'modifiedTime' => (int)$modifiedTime,
                    'elementType'  => $moduleName
                ]
            ]);

            $json = $this->asArray($response);

            if ($json['success'] === true) {
                return $json['result'];
            }

            $this->setLastError($json);
        } catch (GuzzleException $ex) {
            // TODO: handle exception properly
        }

        return false;
    }

    /**
     * Invoke custom operation
     *
     * @param string $operation Name of the operation to invoke
     * @param array $params
     * @param string $httpMethod HTTP method to use
     * @return bool
     */
    function callOperation($operation, array $params, $httpMethod = 'POST')
    {
        $data = array(
            'operation'   => $operation,
            'sessionName' => $this->sessionName
        );

        $data = array_merge($data, $params);

        try {
            $response = null;

            if ($httpMethod === 'GET') {
                $response = $this->httpClient->request('GET', $this->vtigerWebServiceURL, [
                    RequestOptions::QUERY => $data
                ]);
            }

            if ($httpMethod === 'POST') {
                $response = $this->httpClient->request('POST', $this->vtigerWebServiceURL, [
                    RequestOptions::FORM_PARAMS => $data
                ]);
            }

            $json = $this->asArray($response);

            if ($json['success'] === true) {
                return $json['result'];
            }

            $this->setLastError($json);
        } catch (GuzzleException $e) {
            $this->lastError = new WSClientError(
                $e->getCode(),
                $e->getMessage(),
                null
            );
        }

        return false;
    }

    /**
     * Get last error
     *
     * @return WSClientError
     */
    public function getLastError()
    {
        return $this->lastError;
    }

    /**
     * Set last error
     *
     * @param array $json Server response
     */
    public function setLastError($json)
    {
        $code           = isset($json['error']['code']) ? $json['error']['code'] : null;
        $message        = isset($json['error']['message']) ? $json['error']['message'] : null;
        $xdebug_message = isset($json['error']['xdebug_message']) ? $json['error']['xdebug_message'] : null;

        $this->lastError = new WSClientError(
            $code,
            $message,
            $xdebug_message
        );
    }

    /**
     * Return an instance of the http client
     *
     * @return \GuzzleHttp\Client
     */
    public function getHttpClient()
    {
        return $this->httpClient;
    }
}
