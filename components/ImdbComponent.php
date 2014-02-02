<?php

/**
 * Yii component is used to get information for a movie from IMDB.
 * It uses both or any of the free APIs existed on November 2013:
 * - imdbapi.org (preferred)
 * - omdbapi.com
 *
 * On success it returns an array of ImdbMovie models
 * On failure it returns false and error attribute has the reason of failure
 *
 * Usage examles:
 * Yii::app()->imdbComponent->searchByTitleYear('Titanic');
 * Yii::app()->imdbComponent->searchByTitleYear('Titanic', 1997);
 * Yii::app()->imdbComponent->searchById('tt0814314');
 * */
class ImdbComponent extends CApplicationComponent {

    /**
     * Array of movies
     * @var Array of strings
     */
    private $_movies = array();
    private $moviesCacheKey;

    /**
     * Set to the number of seconds needed to cache each request, false to deactivate it
     * @var mixed $cacheDuration Integer means seconds, false to deactivate cache
     */
    public $cacheDuration = 86400000; // 1 day

    CONST CACHE_KEY = 'imdb-component-';

    /**
     * @var string Error reason
     */
    public $error = null;

    /**
     * Logging path
     */

    CONST LOGPATH = 'app.components.imdb';

    /**
     * REQUEST TYPES
     */
    CONST REQUEST_TYPE_QUERYSTRING = 'query';
    CONST REQUEST_TYPE_PLACEHOLDERS = 'placeholders';

    /**
     * RESPONSE TYPES
     */
    CONST RESPONSE_TYPE_JSON = 'json';
    CONST RESPONSE_TYPE_JSONP = 'jsonp';
    CONST RESPONSE_TYPE_XML = 'xml';

    /**
     * Array delimeter
     */
    CONST DELIMETER = ',';

    /**
     * APIs
     */
    CONST MYMOVIEAPI_COM = 'mymovieapi.com';
    CONST OMDB_COM = 'omdbapi.com';
    CONST IMDB_COM_SUGGESTION = 'imdb.com-suggestion';

    static public $apis = array(
        self::MYMOVIEAPI_COM,
        self::OMDB_COM,
        self::IMDB_COM_SUGGESTION,
    );
    static private $apiOptions = array(
        self::MYMOVIEAPI_COM => array(
            'id' => self::MYMOVIEAPI_COM,
            'requestUrl' => 'http://mymovieapi.com/',
            'requestType' => self::REQUEST_TYPE_QUERYSTRING,
            'requestMapping' => array(
                'id' => 'id',
                'title' => 'title',
            ),
            'responseType' => self::RESPONSE_TYPE_JSON,
            'responseMapping' => array(
                'imdbId' => 'imdb_id',
                'imdbUrl' => 'imdb_url',
                'title' => 'title',
                'aka' => 'also_known_as',
                'imdbRating' => 'rating',
                'votes' => 'rating_count',
                'genres' => 'genres',
                'plot' => 'plot_simple',
                'languages' => 'language',
                'countries' => 'country',
                'images' => 'poster',
                'year' => 'year',
                'runtime' => 'runtime',
                'directors' => 'directors',
                'writers' => 'writers',
                'actors' => 'actors',
                'rated' => 'rated',
            ),
        ),
        self::OMDB_COM => array(
            'id' => self::OMDB_COM,
            'requestUrl' => 'http://omdbapi.com/',
            'requestType' => self::REQUEST_TYPE_QUERYSTRING,
            'requestMapping' => array(
                'id' => 'i',
                'title' => 't',
                'year' => 'y',
            ),
            'responseType' => self::RESPONSE_TYPE_JSON,
            'responseMapping' => array(
                'imdbId' => 'imdbID',
                'imdbUrl' => false,
                'title' => 'Title',
                'aka' => false,
                'imdbRating' => 'imdbRating',
                'votes' => 'imdbVotes',
                'genres' => 'Genre',
                'plot' => 'Plot',
                'languages' => false,
                'countries' => false,
                'images' => 'Poster',
                'year' => 'Year',
                'runtime' => 'Runtime',
                'directors' => 'Director',
                'writers' => 'Writer',
                'actors' => 'Actors',
                'rated' => 'Rated',
            ),
        ),
        self::IMDB_COM_SUGGESTION => array(
            'id' => self::IMDB_COM_SUGGESTION,
            'requestUrl' => 'http://sg.media-imdb.com/suggests/{title:first}/{title}.json',
            'requestType' => self::REQUEST_TYPE_PLACEHOLDERS,
            'requestMapping' => array(
                'title' => '{title}',
            ),
            'responseType' => self::RESPONSE_TYPE_JSONP,
            'responseMapping' => array(
                'imdbId' => 'id',
                'title' => 'l',
                'images' => 'i',
                'year' => 'y',
                'actors' => 's',
            ),
        ),
    );

    /**
     * @var String active api options
     */
    private $api;

    /**
     * @var ACurl object request
     */
    private $curl;

    public function __construct($cacheDuration = null) {
        /**
         * Load Curl extension
         */
        Yii::import('ext.curl.*');
        $this->curl = new ACurl();

        if ($cacheDuration !== null)
            $this->cacheDuration = $cacheDuration;

        // Get cached response if exist
        $this->moviesCacheKey = self::CACHE_KEY . 'movies';
        if ($movies = $this->getFromCache($this->moviesCacheKey))
            $this->_movies = $movies;
    }

    /** PUBLIC FUNCTIONS */

    /**
     * Search by title and year (optional) for movie data
     *
     * @param string $title
     * @return ImdbMovie model on success, false otherwise
     */
    public function searchByTitleYear($title, $year = null, $api = null) {
        return $this->search($title, $year, null, $api);
    }

    /**
     * Search by IMDB ID (optional) for movie data
     *
     * @param string $title
     * @return ImdbMovie model on success, false otherwise
     */
    public function searchById($id, $api = null) {
        return $this->search(null, null, $id, $api);
    }

    /**
     * Search by title, year or id for movie data
     *
     * @param string $title
     * @param string $year
     * @param string $id
     * @param string $api
     * @return ImdbMovie model on success, false otherwise (use 'error' attribute to get the reason of failure)
     * @throws CException if none of the search params were set
     */
    public function search($title = null, $year = null, $id = null, $api = null) {
        // Activate all APIs if none selected
        if ($api == null) {
            if ($year && isset(self::$apiOptions[self::OMDB_COM]))
                $api = self::OMDB_COM;
            else
                $api = array_keys(self::$apiOptions);
        }

        if (is_string($api))
            $api = array($api);

        $movieId = null;
        // Request to the different APIs
        foreach ($api as $currentApi) {
            // Check api for cached error
            if ($this->getFromCache(self::CACHE_KEY . $currentApi . 'error', 600))
                continue;

            $this->api = (object) self::$apiOptions[$currentApi];
            $this->api->name = $currentApi;

            $params = array();
            if ($id && isset($this->api->requestMapping['id']) && $this->api->requestMapping['id'])
                $params[$this->api->requestMapping['id']] = $id;
            if ($title && isset($this->api->requestMapping['title']) && $this->api->requestMapping['title'])
                $params[$this->api->requestMapping['title']] = $title;
            if ($year && isset($this->api->requestMapping['year']) && $this->api->requestMapping['year'])
                $params[$this->api->requestMapping['year']] = $year;
            if (empty($params)) {
                Yii::log("At least one valid search param should be set", CLogger::LEVEL_ERROR, 'app.components.' . __CLASS__);
                continue;
            }

            // Create $url and $params used in request
            $url = $this->buildUrl($this->api->requestUrl, $this->api->requestType, $params);

            // Get cached response if exist
            $cacheKey = self::CACHE_KEY . md5(serialize($url));
            if (!($response = $this->getFromCache($cacheKey))) {
                $response = $this->makeRequest($url);
                // Save response to cache if needed
                if ($response)
                    $this->storeToCache($cacheKey, $response);
            }
            if ($response instanceof ACurlResponse) {
                $ids = $this->processResponseObject($response);
            } else if($response === false){
                // Cache api error
                $this->storeToCache(self::CACHE_KEY . $currentApi . 'error', true, 600);
            }
        }
        return isset($ids) ? $this->getMovies($ids) : array();
    }

    /** PRIVATE FUNCTIONS */

    /**
     * Make a request and get the response
     *
     * @param String $url API url
     * @return ImdbMovie model on success, false otherwise
     */
    protected function makeRequest($url) {
        $request = $this->curl->get($url, false);
        $response = null;

        try {
            Yii::log(sprintf('Curl request to %s', $url), CLogger::LEVEL_INFO, self::LOGPATH);
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
     * Get cached data if exist
     *
     * @param string $key
     * @param mixed $data
     * @return boolean
     */
    private function getFromCache($key, $cacheDuration = null) {
        if (!$cacheDuration)
            $cacheDuration = $this->cacheDuration;
        if ($cacheDuration && isset(Yii::app()->cache) && $cached = Yii::app()->cache->get($key)) {
            YII_DEBUG && Yii::log("Data loaded from cache (key: $key)", CLogger::LEVEL_INFO, self::LOGPATH);
            return $cached;
        }
        return false;
    }

    /**
     * Save items to cache if possible
     *
     * @param string $key
     * @param mixed $data
     */
    private function storeToCache($key, $data, $cacheDuration = null) {
        if (!$cacheDuration)
            $cacheDuration = $this->cacheDuration;
        if ($cacheDuration && isset(Yii::app()->cache) && !empty($data)) {
            Yii::app()->cache->set($key, $data, $cacheDuration);
            YII_DEBUG && Yii::log("Data saved to cache (key: $key) for {$cacheDuration}secs", CLogger::LEVEL_INFO, self::LOGPATH);
        }
    }

    /**
     * Delete cache key if possible
     *
     * @param string $key
     * @param mixed $data
     */
    private function deleteCache($key) {
        if (isset(Yii::app()->cache))
            Yii::app()->cache->delete($key);
    }

    /**
     * Decode and check response
     *
     * @param string $response
     * @return tdClass object
     */
    private function responseToObject($response, $responseType) {
        switch ($responseType) {
            case self::RESPONSE_TYPE_JSON:
                return $this->jsonToObject($response);
                break;
            case self::RESPONSE_TYPE_JSONP:
                return $this->jsonpToObject($response);
                break;
            case self::RESPONSE_TYPE_XML:
                return $this->xmlToObject($response);
                break;

            default:
                break;
        }
        throw new Exception("Response type '$responseType' is not valid");
    }

    private function jsonToObject($response) {
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

    private function jsonpToObject($response) {
        $response = $response->data;
        // Decode and validate json
        if ($response[0] !== '[' && $response[0] !== '{') { // we have JSONP
            $response = substr($response, strpos($response, '('));
        }
        return json_decode(trim($response, '();'));
    }

    private function xmlToObject($response) {
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

    /**
     * Map response to a Movie object
     *
     * @param json $response
     * @return ImdbMovie model on success, false otherwise
     */
    private function processResponseObject($response) {
        // Get array of movie data per specific responseType and api
        $responseObject = $this->responseToObject($response, $this->api->responseType);

        if($this->api->id == self::IMDB_COM_SUGGESTION){
            $movieData = $responseObject->d;
            foreach ($movieData as $key => $movie) {
                if(isset($movie->i))
                    $movieData[$key]->i = $movie->i[0];
            }
        } else
            $movieData = $responseObject;

        $ids = array();
        foreach ($movieData as $movie) {
            $movie = (array) $movie;
            $model = new ImdbMovie();
            $model->apis = array($this->api->id);
            foreach ($this->api->responseMapping as $m => $i) {
                if ($i && isset($movie[$i]) && !in_array($movie[$i], array('N/A'))) {
                    if (ImdbMovie::$attributesType[$m] == ImdbMovie::TYPE_ARRAY && !is_array($movie[$i]))
                        $movie[$i] = array_map('trim', explode(self::DELIMETER, $movie[$i]));
                    else if (ImdbMovie::$attributesType[$m] == ImdbMovie::TYPE_TEXT && is_array($movie[$i]))
                        $movie[$i] = implode(self::DELIMETER, $movie[$i]);
                    else if (is_string($movie[$i]))
                        $movie[$i] = trim($movie[$i]);
                    $model->{$m} = $movie[$i];
                }
            }
            $movieId = $this->updateMovies($model);
            array_push($ids, $movieId);
        }

        return $ids;
    }

    /**
     * Build an array out of the url and the options needed for a request to API
     *
     * @param string $url
     * @param array $params
     * @return string the final url
     */
    private function buildUrl($url, $type, $params) {
        switch ($type) {
            case self::REQUEST_TYPE_QUERYSTRING:
                return $url . '?' . $this->urlWithQuery($params);
                break;
            case self::REQUEST_TYPE_PLACEHOLDERS:
                return $this->urlFromPlaceholders($url, $params);
                break;
        }
        throw new Exception("Request type '$type' is not valid");
    }

    /**
     * Build query string from array
     *
     * @param array of strings $params
     * @param string $format spintf first parameter
     * @return string
     */
    private function urlWithQuery($params, $format = '%s') {
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
     * @param array of strings $params
     * @param string $format spintf first parameter
     * @return string
     */
    private function urlFromPlaceholders($url, $params) {
        foreach ($params as $search => $replace) {
            $replace = strtolower($replace);
            $url = str_replace(rtrim($search, "}") . ':first}', $replace[0], $url);
            $url = str_replace($search, $replace, $url);
        }
        return $url;
    }

    /**
     * Movie handling
     */

    /**
     * Returns a bool value indicating whether there are movies found.
     * @return boolean whether there are any movies.
     */
    public function hasMovies() {
        return $this->_movies !== array();
    }

    /**
     * Returns an array with all the Movies found
     * @return array of ImdbMovie models
     */
    public function getMovies() {
        return $this->_movies;
    }

    /**
     * Returns the ImdbMovie found or null
     * @return ImdbMovie model or null
     */
    public function getMovie($id) {
        return (isset($this->_movies[$id]) ? $this->_movies[$id] : null);
    }

    /**
     * Adds/Merges a new ImdbMovie model.
     * @param ImdbMovie $movie model
     */
    public function updateMovies($movie) {
        if (!isset($this->_movies[$movie->imdbId])) {
            Yii::log("Movie '$movie->title' ($movie->imdbId) added from {$this->api->name}", CLogger::LEVEL_WARNING, self::LOGPATH);
            $this->_movies[$movie->imdbId] = $movie;
        }
        else
            $this->_movies[$movie->imdbId] = $this->mergeMovies($this->_movies[$movie->imdbId], $movie);

        // Save movies to cache if needed
        $this->storeToCache($this->moviesCacheKey, $this->_movies);

        return $movie->imdbId;
    }

    /**
     * Merge 2 Movie models
     * @param array $movies of ImdbMovie models
     */
    public function mergeMovies($movie, $newMovie) {
        if (!get_class($movie) == 'ImdbMovie')
            throw new Exception("Movie attribute should a ImdbMovie model");
        if (!get_class($newMovie) == 'ImdbMovie')
            throw new Exception("NewMovie attribute should a ImdbMovie model");

        foreach (get_object_vars($movie) as $attr => $value) {
            $newValue = $newMovie->{$attr};
            if (ImdbMovie::$attributesType[$attr] == ImdbMovie::TYPE_ARRAY) {
                if (is_array($value) && is_array($newValue) && $value != $newValue) {
                    $movie->{$attr} = array_unique(array_merge($value, $newValue));
                }
            } else {
                if (!empty($newValue) && (empty($value) || strlen($value) < strlen($newValue)))
                    $movie->{$attr} = $newValue;
            }
        }
        Yii::log("Merge movie '$movie->title' ($movie->imdbId) model", CLogger::LEVEL_WARNING, self::LOGPATH);
        return $movie;
    }

    /**
     * Removes all Movies
     */
    public function clearMovies() {
        $this->_movies = array();
        $this->deleteCache($this->moviesCacheKey);
    }

}