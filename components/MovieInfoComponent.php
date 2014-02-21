<?php

/**
 * Yii component is used to get information for a movie from various APIs.
 *
 * On success it returns an array of MovieInfo models
 * On failure it returns null
 *
 * Usage examles:
 * Yii::app()->movieInfoComponent->instantSearch('Titanic');
 * Yii::app()->movieInfoComponent->instantSearchById('tt0814314');
 * Yii::app()->movieInfoComponent->search('tt0814314');
 * */
class MovieInfoComponent extends CApplicationComponent
{

    /**
     * Logging path
     */
    CONST LOGPATH = 'app.components.movieinfo';

    CONST CACHE_KEY = 'movieinfo-component-';

    /**
     * Array of movies
     * @var Array of MovieInfo models
     */
    private $_movies = array();

    /**
     * Array of instant results
     * @var Array of MovieInfo models
     */
    private $_instantResults = array();

    /**
     * Set to the number of seconds needed to cache each request, false to deactivate it
     * @var mixed $cacheDuration Integer means seconds, false to deactivate cache
     */
    public $cacheDuration = 86400000; // 1 day

    /**
     * @var string Error reason
     */
    public $error = null;

    /**
     * APIs
     */
    // The Movie Data Base version 3 - themoviedb.org
    CONST API_TMDB_ORG = 'TmdbApi';

    // The IMDB instant suggestion response used in imdb's search bar
    CONST API_IMDB_SUGGESTION = 'ImdbSuggestionApi';

    // Select which APIs are active on every method
    static public $instantSearchApis = array(
        self::API_IMDB_SUGGESTION,
    );
    static public $instantSearchByIdApis = array(
        self::API_TMDB_ORG,
    );
    static public $searchApis = array(
        self::API_TMDB_ORG,
    );

    public function __construct($cacheDuration = null)
    {
        if ($cacheDuration !== null)
            $this->cacheDuration = $cacheDuration;

        // Get cached movies if exist
        if ($movies = $this->getFromCache(self::CACHE_KEY . 'movies'))
            $this->_movies = $movies;

        // Get cached instantResults if exist
        if ($instantResults = $this->getFromCache(self::CACHE_KEY . 'instantResults'))
            $this->_instantResults = $instantResults;
    }

    /** PUBLIC FUNCTIONS */

    /**
     * Search by title for movie ids and partial info
     *
     * @param string $title
     * @param null|string|array $apis
     * @return array of imdbId/MovieInfo pairs
     */
    public function instantSearch($title, $apis = null)
    {
        $title = trim($title);
        if (empty($title))
            return array();

        // Activate all APIs if none selected
        if ($apis == null)
            $apis = self::$instantSearchApis;
        else if (is_string($apis))
            $apis = array($apis);

        // Request to the different APIs
        $ids = array();
        foreach ($apis as $apiClass) {
            /** @var $api BaseMovieApi */
            $api = new $apiClass($this->cacheDuration);

            // Check api for request errors in near past
            if ($api->hasRequestErrors())
                continue;

            foreach ($api->instantSearch($title) as $movie) {
                /** @var $movie MovieInfo */
                array_push($ids, $this->updateInstantResults($movie, $apiClass));
            }
        }

        return $this->getInstantResults($ids);
    }

    /**
     * Search by imdb id for movie title and partial info
     *
     * @param string $id
     * @param null|string|array $apis
     * @return array of imdbId/MovieInfo pairs
     */
    public function instantSearchById($id, $apis = null)
    {
        $id = trim($id);
        if (empty($id))
            return array();

        // Activate all APIs if none selected
        if ($apis == null)
            $apis = self::$instantSearchByIdApis;
        else if (is_string($apis))
            $apis = array($apis);


        if (!$this->getInstantResult($id)) {
            if ($movie = $this->getMovie($id))
                $this->updateInstantResults($movie, 'Movies');
            else {
                // Request to the different APIs
                foreach ($apis as $apiClass) {
                    /** @var $api BaseMovieApi */
                    $api = new $apiClass($this->cacheDuration);

                    // Check api for request errors in near past
                    if ($api->hasRequestErrors())
                        continue;

                    if ($movie = $api->instantSearchById($id)) {
                        /** @var $movie MovieInfo */
                        $this->updateInstantResults($movie, $apiClass);
                    }
                }
            }
        }

        return $this->getInstantResult($id);
    }

    /**
     * Search by imdb id for movie ids and movie info
     *
     * @param string $id
     * @param string $apis
     * @return MovieInfo model on success, false otherwise (use 'error' attribute to get the reason of failure)
     */
    public function search($id = null, $apis = null)
    {
        $id = trim($id);
        if (empty($id))
            return null;

        // Activate all APIs if none selected
        if ($apis == null)
            $apis = self::$searchApis;
        else if (is_string($apis))
            $apis = array($apis);

        // Request to the different APIs
        foreach ($apis as $apiClass) {
            /** @var $currentApi BaseMovieApi */
            $api = new $apiClass($this->cacheDuration);

            // Check api for cached error
            if ($api->hasRequestErrors())
                continue;

            if ($movie = $api->search($id)) {
                $this->updateMovies($movie, $apiClass);
            }
        }

        return $this->getMovie($id);
    }

    /**
     * Movie handling
     */

    /**
     * Returns a bool value indicating whether there are movies found.
     * @return boolean whether there are any movies.
     */
    public function hasMovies()
    {
        return $this->_movies !== array();
    }

    /**
     * Returns an array with all the Movies found
     * @param null|array $ids
     * @return array of MovieInfo models
     */
    public function getMovies($ids = null)
    {
        if ($ids)
            return array_intersect_key($this->_movies, array_flip($ids));
        else
            return $this->_movies;
    }

    /**
     * Returns the MovieInfo found or null
     * @param int $id
     * @return MovieInfo model or null
     */
    public function getMovie($id)
    {
        return (isset($this->_movies[$id]) ? $this->_movies[$id] : null);
    }

    /**
     * Adds/Merges a new MovieInfo model.
     * @param MovieInfo $movie model
     * @param $api
     * @return string ImdbId
     */
    public function updateMovies($movie, $api)
    {
        if (!isset($this->_movies[$movie->imdbId])) {
            Yii::log("Movie '$movie->title' ($movie->imdbId) added from {$api}", CLogger::LEVEL_WARNING, self::LOGPATH);
            $this->_movies[$movie->imdbId] = $movie;
        } else
            $this->_movies[$movie->imdbId] = $this->mergeMovies($this->_movies[$movie->imdbId], $movie);

        // Save movies to cache if needed
        $this->storeToCache(self::CACHE_KEY . 'movies', $this->_movies);

        return $movie->imdbId;
    }

    /**
     * Removes all Movies
     */
    public function clearMovies()
    {
        $this->_movies = array();
        $this->deleteCache($this->moviesCacheKey);
    }

    /**
     * Returns a bool value indicating whether there are movies found.
     * @return boolean whether there are any movies.
     */
    public function hasInstantResults()
    {
        return $this->_instantResults !== array();
    }

    /**
     * Returns an array with all the Movies found
     * @param null|array $ids
     * @return array of MovieInfo models
     */
    public function getInstantResults($ids = null)
    {
        if ($ids)
            return array_intersect_key($this->_instantResults, array_flip($ids));
        else
            return $this->_instantResults;
    }

    /**
     * Returns the MovieInfo found or null
     * @param int $id
     * @return MovieInfo model or null
     */
    public function getInstantResult($id)
    {
        return (isset($this->_instantResults[$id]) ? $this->_instantResults[$id] : null);
    }

    /**
     * Adds/Merges a new MovieInfo model.
     * @param MovieInfo $movie model
     * @param $api
     * @return string ImdbId
     */
    public function updateInstantResults($movie, $api)
    {
        if (!isset($this->_instantResults[$movie->imdbId])) {
            Yii::log("Movie '$movie->title' ($movie->imdbId) added from {$api}", CLogger::LEVEL_WARNING, self::LOGPATH);
            $this->_instantResults[$movie->imdbId] = $movie;
        } else
            $this->_instantResults[$movie->imdbId] = $this->mergeMovies($this->_instantResults[$movie->imdbId], $movie);

        // Save movies to cache if needed
        $this->storeToCache(self::CACHE_KEY . 'movies', $this->_instantResults);

        return $movie->imdbId;
    }

    /**
     * Removes all Movies
     */
    public function clearInstantResults()
    {
        $this->_instantResults = array();
        $this->deleteCache(self::CACHE_KEY . 'instantResults');
    }

    /**
     * Merge 2 Movie models
     * @param array $movies of MovieInfo models
     */
    public function mergeMovies($movie, $newMovie)
    {
        if (!get_class($movie) == 'MovieInfo')
            throw new Exception("Movie attribute should a MovieInfo model");
        if (!get_class($newMovie) == 'MovieInfo')
            throw new Exception("NewMovie attribute should a MovieInfo model");

        foreach (get_object_vars($movie) as $attr => $value) {
            $newValue = $newMovie->{$attr};
            if (MovieInfo::$attributesType[$attr] == MovieInfo::TYPE_ARRAY) {
                if (is_array($value) && is_array($newValue) && $value != $newValue)
                    $movie->{$attr} = array_unique(array_merge($value, $newValue));
                else if (!is_array($value) && is_array($newValue))
                    $movie->{$attr} = $newValue;
            } else {
                if (!empty($newValue) && (empty($value) || strlen($value) < strlen($newValue)))
                    $movie->{$attr} = $newValue;
            }
        }

        Yii::log("Merge movie '$movie->title' ($movie->imdbId) model", CLogger::LEVEL_TRACE, self::LOGPATH);
        return $movie;
    }


    /** PRIVATE FUNCTIONS */

    /**
     * Get cached data if exist
     *
     * @param string $key
     * @param null $cacheDuration
     * @internal param mixed $data
     * @return boolean
     */
    private function getFromCache($key, $cacheDuration = null)
    {
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
     * @param null $cacheDuration
     */
    private function storeToCache($key, $data, $cacheDuration = null)
    {
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
     * @internal param mixed $data
     */
    private function deleteCache($key)
    {
        if (isset(Yii::app()->cache))
            Yii::app()->cache->delete($key);
    }

    /**
     * @param Array $movies
     */
    public function setMovies($movies)
    {
        $this->_movies = $movies;
    }

}
