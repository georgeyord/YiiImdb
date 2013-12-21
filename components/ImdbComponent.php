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

    /**
     * Array delimeter
     */
    CONST DELIMETER = ',';

    static public $apis = array(self::IMDB_ORG, self::OMDB_COM);
    static private $apiOptions = array(
        self::IMDB_ORG => array(
            'id' => self::IMDB_ORG,
            'endpoint' => 'http://imdbapi.org/',
            'searchMapping' => array(
                'id' => 'id',
                'title' => 'title',
            ),
            'responseMapping' => array(
                'imdbId' => 'imdb_id',
                'imdbUrl' => 'imdb_url',
                'title' => 'title',
                'aka' => 'also_known_as',
                'rating' => 'rating',
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
            'endpoint' => 'http://omdbapi.com/',
            'searchMapping' => array(
                'id' => 'i',
                'title' => 't',
                'year' => 'y',
            ),
            'responseMapping' => array(
                'imdbId' => 'imdbID',
                'imdbUrl' => false,
                'title' => 'Title',
                'aka' => false,
                'rating' => 'imdbRating',
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
        if ($api == null)
            $api = array_keys(self::$apiOptions);
        else if (is_string($api))
            $api = array($api);

        $movieId = null;
        // Request to the different APIs
        foreach ($api as $currentApi) {
            $this->api = (object) self::$apiOptions[$currentApi];
            $this->api->name = $currentApi;

            $params = array();
            if ($id && isset($this->api->searchMapping['imdbId']))
                $params[$this->api->searchMapping['imdbId']] = $id;
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
                $movieId = $this->updateMovies($movie);
            }
        }
        return $this->getMovie($movieId);
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
        $cacheKey = self::CACHE_KEY . md5(serialize(array($url)));

        // Get cached response if exist
        if ($response = $this->getFromCache($cacheKey))
            return $response;

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
            $this->storeToCache($cacheKey, $response);
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
     * Get cached data if exist
     *
     * @param string $key
     * @param mixed $data
     * @return boolean
     */
    private function getFromCache($key) {
        if ($this->cacheDuration && isset(Yii::app()->cache) && $cached = Yii::app()->cache->get($key)) {
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
    private function storeToCache($key, $data) {
        if ($this->cacheDuration && isset(Yii::app()->cache) && !empty($data)) {
            Yii::app()->cache->set($key, $data, $this->cacheDuration);
            YII_DEBUG && Yii::log("Data saved to cache (key: $key) for {$this->cacheDuration}secs", CLogger::LEVEL_INFO, self::LOGPATH);
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
     * @param json $response
     * @return ImdbMovie model on success, false otherwise
     */
    private function processResponse($response) {
        if (!isset($response['year']) && isset($response[0]) && isset($response[0]['year']))
            $response = $response[0];

        $model = new ImdbMovie();
        $model->apis = array($this->api->id);
        foreach ($this->api->responseMapping as $m => $i) {
            if ($i && isset($response[$i])) {
                if (ImdbMovie::$attributesType[$m] == ImdbMovie::TYPE_ARRAY && !is_array($response[$i]))
                    $response[$i] = array_map('trim', explode(self::DELIMETER, $response[$i]));
                else if (ImdbMovie::$attributesType[$m] == ImdbMovie::TYPE_TEXT && is_array($response[$i]))
                    $response[$i] = implode(self::DELIMETER, $response[$i]);
                else if (is_string($response[$i]))
                    $response[$i] = trim($response[$i]);
                $model->{$m} = $response[$i];
            }
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
                $query .= sprintf($format, $key) . "=" . urlencode($value) . "&";
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
        } else
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
                if (is_array($value) || is_array($newValue)) {
                    ydump($value);
                    ydump($newValue, 1, 1);
                }
                if (!empty($newValue) && strlen($value) < strlen($newValue))
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