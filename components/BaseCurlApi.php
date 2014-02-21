<?php

abstract class BaseCurlApi extends BaseApi
{
    /**
     * Logging path
     */
    const LOGPATH = 'app.components.movieinfo.basejsonpapi';

    /**
     * @var string Url endpoint to request data
     */
    protected $url;

    /**
     * @var ACurl object request
     */
    protected $curl;

    /**
     * Fill name. Always call parent::init() in derived classes.
     */
    public function __construct($cacheDuration = null)
    {
        parent::__construct($cacheDuration);

        /**
         * Load Curl extension
         */
        Yii::import('ext.curl.*');
        $this->curl = new ACurl();
    }

    /**
     * Check response validity and log any findings
     * @param mixed $response
     * @return bool true if valid, false otherwise
     */
    protected function checkResponse($response)
    {
        return $response instanceof ACurlResponse;
    }

    /**
     * Build query string from array
     *
     * @param array of strings $params
     * @param string $format spintf first parameter
     * @return string
     */
    protected function buildQuery($params, $format = '%s')
    {
        $query = "";
        foreach ($params as $key => $value) {
            if (is_array($value)) {
                $query .= $this->buildQuery($value, sprintf($format, $key) . "[%s]");
            } else {
                $query .= sprintf($format, $key) . "=" . urlencode($value) . "&";
            }
        }
        return $query;
    }

    /**
     * Build query string from array
     *
     * @param string $url
     * @param array $params to merge into url
     * @return string
     */
    protected function buildUrlFromPlaceholders($url, $params)
    {
        foreach ($params as $search => $replace) {
            $replace = strtolower($replace);
            $url = str_replace(rtrim($search, "}") . ':first}', $replace[0], $url);
            $url = str_replace($search, $replace, $url);
        }
        return $url;
    }

    /**
     * Make a request and get the response
     *
     * @param String $url API url
     * @return ImdbMovie model on success, false otherwise
     */
    protected function makeRequest($url)
    {
        $request = $this->curl->get($url, false);
        $response = null;

        try {
            Yii::log(sprintf('Curl request to %s', $url), CLogger::LEVEL_TRACE, self::LOGPATH);
            $response = $request->exec();
        } catch (ACurlException $e) {
            switch ($e->statusCode) {
                case 6: // Couldn't resolve host
                    $this->error = '6 - Couldn\'t resolve host or name lookup timed out';
                    $response = false;
                    break;
                case 400:
                    $response = CJSON::decode($e->response->data, false);
                    if (isset($response->error)) {
                        $this->error = (ucfirst(str_replace(array('-', '_'), ' ', $response->error)));
                    } else {
                        $this->error = '400 - The request was invalid';
                    }
                    break;
                case 401: // Unauthorized
                    $this->error = '401 - The action requires a logged in user';
                    break;
                case 403: // Forbidden
                    $this->error = '403 - The current user is not allowed to perform this action';
                    break;
                case 404: // Not Found (e.g. record with given id does not exist)
                    $this->error = '404 - The requested resource was not found';
                    break;

                default:
                    break;
            }
        }

        return $response;
    }

    /**
     * Decode and check response
     *
     * @param string $response
     * @return stdClass object
     */
    protected function processResponse($response)
    {
        // Decode and validate json
        $response = $response->fromJSON();
        if (json_last_error() != JSON_ERROR_NONE) {
            $this->error = 'Response should be in json format';
            return false;
        }
        // Check response
        if (isset($response['error'])) {
            $this->error = $response['error'];
            return false;
        }
        return $response;
    }

    /**
     * UNUSED!
     * @param $response
     * @return bool
     */
    private function xmlToObject($response)
    {
        // Decode and validate json
        $response = $response->fromJSON();
        if (json_last_error() != JSON_ERROR_NONE) {
            $this->error = 'Response should be in json format';
            return false;
        }
        // Check response
        if (isset($response['error'])) {
            $this->error = $response['error'];
            return false;
        }
        if (isset($response['Error'])) {
            $this->error = $response['Error'];
            return false;
        }
        return $response;
    }

}