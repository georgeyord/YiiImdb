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

    /**
     * Set to the number of seconds needed to cache each request, false to deactivate it
     * @var mixed $cacheDuration Integer means seconds, false to deactivate cache
     */
    public $cacheDuration = 600;

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
     * APIs
     */
    CONST IMDB_ORG = 'imdbapi.org';
    CONST OMDB_COM = 'omdbapi.com';

    static private $apiOptions = array(
        self::IMDB_ORG => array(
            'endpoint' => 'http://imdbapi.org/',
            'searchMapping' => array(
                'id' => 'id',
                'title' => 'title',
            ),
            'responseMapping' => array(
                'id' => 'imdb_id',
                'url' => 'imdb_url',
                'title' => 'title',
                'aka' => 'also_known_as',
                'rating' => 'rating',
                'votes' => 'rating_count',
                'genre' => 'genres',
                'plot' => 'plot_simple',
                'language' => 'rated',
                'country' => 'country',
                'image' => 'poster',
                'year' => 'year',
                'runtime' => 'runtime',
                'director' => 'directors',
                'writer' => 'writers',
                'actor' => 'actors',
                'rated' => 'rated',
            ),
        ),
        self::OMDB_COM => array(
            'endpoint' => 'http://omdbapi.com/',
            'searchMapping' => array(
                'id' => 'i',
                'title' => 't',
                'year' => 'y',
            ),
            'responseMapping' => array(
                'id' => 'imdbID',
                'url' => false,
                'title' => 'Title',
                'aka' => false,
                'rating' => 'imdbRating',
                'votes' => 'imdbVotes',
                'genre' => 'Genre',
                'plot' => 'Plot',
                'language' => false,
                'country' => false,
                'image' => 'Poster',
                'year' => 'Year',
                'runtime' => 'Runtime',
                'director' => 'Director',
                'writer' => 'Writer',
                'actor' => 'Actors',
                'rated' => 'Rated',
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

    public function __construct() {
        /**
         * Load Curl extension
         */
        Yii::setPathOfAlias('imdbComponent', __DIR__ . DIRECTORY_SEPARATOR . '..');
        Yii::import('imdbComponent.lib.yii-curl.*');
        $this->curl = new ACurl();
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
        if ($api == null)
            $api = array_keys(self::$apiOptions);

        // Request to the different APIs
        foreach ($api as $currentApi) {
            $this->api = (object) self::$apiOptions[$currentApi];
            $this->api->name = $currentApi;

            $params = array();
            if ($id && isset($this->api->searchMapping['id']))
                $params[$this->api->searchMapping['id']] = $id;
            if ($title && isset($this->api->searchMapping['title']))
                $params[$this->api->searchMapping['title']] = $title;
            if ($year && isset($this->api->searchMapping['year']))
                $params[$this->api->searchMapping['year']] = $year;
            if (empty($params))
                throw new CException("At least one valid search param should be set");

            // Create $url and $params used in request
            $url = $this->buildUrl($this->api->endpoint, $params);

            if ($response = $this->makeRequest($url)) {
                $movie = $this->processResponse($response);
                $this->addMovie($movie);
            }
        }
        return $this->getMovies();
    }

    /** PRIVATE FUNCTIONS */

    /**
     * Make a request and get the response
     *
     * @param String $url API url
     * @param String $cacheKey to load from cache
     * @return ImdbMovie model on success, false otherwise
     */
    protected function makeRequest($url) {
        $cacheKey = $this->cacheDuration ? self::CACHE_KEY . md5(serialize(array($url))) : '';

        // Get cached response if exist
        if ($this->cacheDuration && isset(Yii::app()->cache) && $response = Yii::app()->cache->get($cacheKey)) {
            YII_DEBUG && Yii::log("API response to '$url' loaded from cache", CLogger::LEVEL_INFO, self::LOGPATH);
            return $response;
        }

        $request = $this->curl->get($url, false);
        try {
            Yii::log(sprintf('Curl request to %s', $url), CLogger::LEVEL_INFO, self::LOGPATH);
            $response = $request->exec();

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

            // Save response to cache if needed
            if ($this->cacheDuration && isset(Yii::app()->cache) && !empty($response)) {
                Yii::app()->cache->set($cacheKey, $response, $this->cacheDuration);
                YII_DEBUG && Yii::log("API response to saved in cache", CLogger::LEVEL_INFO, self::LOGPATH);
            }
        } catch (ACurlException $e) {
            switch ($e->statusCode) {
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
            $response = false;
        }

        return $response;
    }

    /**
     * Decode and check response
     *
     * @param json $response
     * @return ImdbMovie model on success, false otherwise
     */
    private function processResponse($response) {
        if (!isset($response['year']) && isset($response[0]) && isset($response[0]['year']))
            $response = $response[0];

        $model = new ImdbMovie();
        foreach ($this->api->responseMapping as $m => $a) {
            if ($a && $response[$a])
                $model->{$m} = $response[$a];
        }

        return $model;
    }

    /**
     * Build an array out of the url and the options needed for a request to API
     *
     * @param string $url
     * @param array $params
     * @return string the final url
     */
    private function buildUrl($url, $params) {
        return $url . '?' . $this->buildQuery($params);
    }

    /**
     * Build query string from array
     *
     * @param array of strings $params
     * @param string $format spintf first parameter
     * @return string
     */
    private function buildQuery($params, $format = '%s') {
        $query = "";
        foreach ($params as $key => $value) {
            if (is_array($value)) {
                $query .= $this->buildQuery($value, sprintf($format, $key) . "[%s]");
            } else {
                $query .= sprintf($format, $key) . "=".urlencode($value)."&";
            }
        }
        return $query;
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
     * Adds a new movie to the specified attribute.
     * @param ImdbMovie $movie model
     */
    public function addMovie($movie) {
        Yii::log("Movie '$movie->title' ($movie->id) added from {$this->api->name}", CLogger::LEVEL_WARNING, self::LOGPATH);
        $this->_movies[] = $movie;
    }

    /**
     * Removes all Movies
     */
    public function clearMovies() {
        $this->_movies = array();
    }

}