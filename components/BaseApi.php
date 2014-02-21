<?php

abstract class BaseApi extends CComponent
{
    /**
     * Logging path
     */
    const LOGPATH = 'app.components.movieinfo.basejsonpapi';

    const DELIMETER = ',';

    /**
     * @var unique API identifier
     */
    protected $_name;

    /**
     * @var int duration in secons
     */
    public $cacheDuration = 600;

    /**
     * @var string Error reason
     */
    public $error = null;

    /**
     * @var array map MovieInfo attributes to custom fields returned from API
     */
    protected $responseMapping = array();

    /**
     * Search for title using the query string given or a part of the query string
     *
     * @param string $query
     * @return array of imdbId/MovieInfo model pairs
     */
    public function instantSearch($query){
        return null;
    }

    /**
     * Search for title using the id given
     *
     * @param string $id
     * @return array of imdbId/MovieInfo model pairs
     */
    public function instantSearchById($id){
        return null;
    }

    /**
     * Search for full movie info using the imdb id as input
     *
     * @param string $imdbId
     * @return MovieInfo model
     */
    public function search($imdbId){
        return null;
    }

    /**
     * Check response validity and log any findings
     * @param mixed $response
     * @return bool true if valid, false otherwise
     */
    abstract protected function checkResponse($response);

    /**
     * Creates a MovieInfo object using mapping
     *
     * @param mixed $data
     * @return MovieInfo model
     */
    abstract protected function mapToMovieInfo($data);

    /**
     * Fill name. Always call parent::__construct() in derived classes.
     */
    public function __construct($cacheDuration = null)
    {
        $this->_name = strtolower(get_class($this));
        if ($cacheDuration !== null)
            $this->cacheDuration = $cacheDuration;
    }

    /**
     * CACHE HELPERS
     */

    /**
     * Use unique name to create the cache key3
     * @param null|string $key
     * @return string
     */
    final protected function getCacheKey($key = null)
    {
        return MovieInfoComponent::CACHE_KEY . $this->_name . ($key === null ? '' : '-' . $key);
    }

    /**
     * If API had request errors in near past
     * @return bool
     */
    final public function hasRequestErrors()
    {
        return $this->getFromCache($this->getCacheKey('error:'.$this->_name), 600);
    }

    final protected function setRequestErrors()
    {
        $this->storeToCache($this->getCacheKey('error:'.$this->_name), true, 600);
    }

    /**
     * Get cached data if exist
     *
     * @param string $key
     * @param null|int $cacheDuration
     * @internal param mixed $data
     * @return boolean
     */
    final protected function getFromCache($key, $cacheDuration = null)
    {
        if (!$cacheDuration)
            $cacheDuration = $this->cacheDuration;
        if ($cacheDuration && isset(Yii::app()->cache) && $cached = Yii::app()->cache->get($key)) {
            YII_DEBUG && Yii::log("Data loaded from cache (key: $key)", CLogger::LEVEL_TRACE, self::LOGPATH);
            return $cached;
        }
        return false;
    }

    /**
     * Save items to cache if possible
     *
     * @param string $key
     * @param mixed $data
     * @param null|int $cacheDuration
     */
    final protected function storeToCache($key, $data, $cacheDuration = null)
    {
        if (!$cacheDuration)
            $cacheDuration = $this->cacheDuration;
        if ($cacheDuration && isset(Yii::app()->cache) && !empty($data)) {
            Yii::app()->cache->set($key, $data, $cacheDuration);
            YII_DEBUG && Yii::log("Data saved to cache (key: $key) for {$cacheDuration}secs", CLogger::LEVEL_TRACE, self::LOGPATH);
        }
    }

    /**
     * Delete cache key if possible
     *
     * @param string $key
     * @internal param mixed $data
     */
    final protected function deleteCache($key)
    {
        if (isset(Yii::app()->cache))
            Yii::app()->cache->delete($key);
    }

}