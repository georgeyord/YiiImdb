<?php

class TmdbApi extends BaseApi
{
    /**
     * Logging path
     */
    const LOGPATH = 'app.components.movieinfo.tmdb';

    public $responseMapping = array(
            'genres' => 'genres',
            'imdbId' => 'imdb_id',
            'title' => 'title',
            'aka' => 'aka',
            'year' => 'release_date',
            'runtime' => 'runtime',
            'plot' => 'plot',
            'images' => 'images',
            'trailers' => 'trailers',
            'directors' => 'directors',
            'writers' => 'writers',
            'actors' => 'actors',
            'rating' => 'vote_average',
            'votes' => 'vote_count',
        );

    private $_api;
    private $imageUrl;
    public $language = 'en';

    /**
     * Fill name. Always call parent::init() in derived classes.
     */
    public function __construct($cacheDuration = null)
    {
        parent::__construct($cacheDuration);

        // Get Image url from cache or request to API
        if ($imageUrl = $this->getFromCache($this->getCacheKey('imageurl'), 3600))
            $this->imageUrl = $imageUrl;
        else {
            $this->imageUrl = $this->api->getImageURL();
            $this->storeToCache($this->getCacheKey('imageurl'), $this->imageUrl, 3600);
        }
    }

    // Instantiate TMDBv3 API when/if neede
    public function getApi()
    {
        Yii::import('application.lib.yii-imdb.lib.tmdb.tmdb_v3', true);
        if (!$this->_api)
            $this->_api = new TMDBv3("392d6748f862c709f9892da9e80ff8ed", $this->language);
        return $this->_api;
    }

    /**
     * Search for title using the query string given or a part of the query string
     *
     * @param string $query
     * @return array of imdbId/MovieInfo model pairs
     */
    public function instantSearch($query)
    {
        while (empty($intermediateResults) && strlen($query) >= 3) {
            $intermediateResults = $this->apiSearchMovies($query, false);
            $query = mb_substr($query, 0, -1);
        }
        $movies = array();
        foreach ($intermediateResults as $intermediateResult) {
            if ($movie = $this->instantSearchById($intermediateResult['id']))
                $movies[$movie->imdbId] = $movie;
        }

        return $movies;
    }

    /**
     * Search for title using the id given
     *
     * @param string $id
     * @return null|MovieInfo model
     */
    public function instantSearchById($id)
    {
        if ($movie = $this->apiMovieInfo($id, false)) {
            if(!empty($movie->aka))
                $movie->title = implode(' - ', array_merge(array($movie->title),$movie->aka));
            return $movie;
        }

        return null;
    }

    /**
     * Search for full movie info using the imdb id as input
     *
     * @param string $imdbId
     * @return MovieInfo model
     */
    public function search($imdbId)
    {
        if ($idMovie = $this->apiFindIdFromExternal($imdbId))
            $movie = $this->apiMovieInfo($idMovie, true);

        return $movie;
    }

    private function apiSearchMovies($query, $page = 0)
    {
        // Get cached response if exist
        $cacheKey = $this->getCacheKey(serialize(array(__METHOD__, $query, $page)));
        if ($response = $this->getFromCache($cacheKey))
            return $response;

        if ($page)
            $text = "&page=" . $page;
        else
            $text = '';

        $response = $this->api->searchMovie($query, $text);

        if ($this->checkResponse($response) === false || !isset($response["total_results"]) || $response["total_results"] == 0)
            return array();
        else if ($page === true && isset($response["total_pages"]) && $response["total_results"] > 1) {
            $results = $response["results"];
            for ($page = 2; $page++; $page <= $response["total_results"]) {
                $results = array_merge($results, $this->apiSearchMovies($query, $page));
            }
        }

        // Save response to cache
        if (!empty($response["results"]))
            $this->storeToCache($cacheKey, $response["results"]);

        return $response["results"];

    }

    private function apiMovieInfo($idMovie, $options = true)
    {
        $defaultOptions = array(
            'AlternativeTitle',
            'Credits',
            'Images',
            'Keywords',
            'Trailers',
        );
        if ($options === true)
            $options = $defaultOptions;
        else if ($options === false)
            $options = array();
        else if (is_array($options))
            $options = array_intersect($defaultOptions, $options);

        $response = $this->apiMovieDetails($idMovie);
        if (!$response)
            return null;

        foreach ($options as $option) {
            if (method_exists($this, 'apiMovie' . $option) && $partialResponse = $this->{'apiMovie' . $option}($idMovie))
                $response = array_merge_recursive($response, $partialResponse);
        }
        return $this->mapToMovieInfo($response);
    }

    private function apiFindIdFromExternal($id, $externalSource = 'imdb_id')
    {
        // Get cached response if exist
        $cacheKey = $this->getCacheKey(serialize(array(__METHOD__, $id, $externalSource)));
        if ($response = $this->getFromCache($cacheKey))
            return $response;

        $response = $this->api->find($id, $externalSource);

        if ($this->checkResponse($response) === false)
            return null;

        // Normalize some variables
        if (isset($response['movie_results']) && count($response['movie_results'] > 0)) {
            $response = $response['movie_results'][0]['id'];
            // Save response to cache
            $this->storeToCache($cacheKey, $response);
        } else
            $response = null;
        return $response;
    }

    private function apiMovieDetails($idMovie, $option = "")
    {
        // Get cached response if exist
        $cacheKey = $this->getCacheKey(serialize(array(__METHOD__, $idMovie, $option)));
        if ($response = $this->getFromCache($cacheKey))
            return $response;

        $response = $this->api->movieDetail($idMovie, $option);

        if ($this->checkResponse($response) === false)
            return null;

        // Normalize some variables
        if (isset($response['genres']) && !empty($response['genres'])) {
            $genres = array();
            foreach ($response['genres'] as $genre) {
                array_push($genres, $genre['name']);
            }
            $response['genres'] = $genres;
        }
        $response['images'] = array();
        if (isset($response['poster_path']) && !empty($response['poster_path']))
            array_push($response['images'], $this->imageUrl . $response['poster_path']);
        unset($response['poster_path']);
        if (isset($response['backdrop_path']) && !empty($response['backdrop_path']))
            array_push($response['images'], $this->imageUrl . $response['backdrop_path']);
        unset($response['backdrop_path']);
        if (isset($response['release_date']) && !empty($response['release_date'])) {
            $release_date = DateTime::createFromFormat("Y-m-d", $response['release_date']);
            $response['release_date'] = $release_date->format("Y");
        }
        if (isset($response['spoken_languages']) && !empty($response['spoken_languages'])) {
            $spoken_languages = array();
            foreach ($response['spoken_languages'] as $spoken_language) {
                array_push($spoken_languages, $spoken_language['iso_639_1']);
            }
            $response['spoken_languages'] = $spoken_languages;
        }
        if (isset($response['title']) && isset($response['original_title']) && $response['title'] != $response['original_title']) {
            $response['aka'] = array($response['original_title']);
        }
        unset($response['original_title']);
        $plot = array();
        if (isset($response['tagline']))
            array_push($plot, $response['tagline']);
        unset($response['tagline']);
        if (isset($response['overview']))
            array_push($plot, $response['overview']);
        unset($response['overview']);
        $response['plot'] = implode(' - ', $plot);

        unset($response['adult']);

        unset($response['belongs_to_collection']);
        unset($response['budget']);
        unset($response['homepage']);
        unset($response['id']);
        unset($response['popularity']);
        unset($response['production_companies']);
        unset($response['production_countries']);
        unset($response['revenue']);
        unset($response['spoken_languages']);
        unset($response['status']);

        // Save response to cache
        $this->storeToCache($cacheKey, $response);

        return $response;
    }

    /**
     * Movie alternative titles
     *
     * Response example
     * {
     * "id": 597,
     * "titles": [
     * {
     * "iso_3166_1": "TW",
     * "title": "鐵達尼號"
     * },
     * {
     * "iso_3166_1": "DE",
     * "title": "Titanic - 3D"
     * }
     * ]
     * }
     * @param $idMovie
     * @return array|null
     */
    private function apiMovieAlternativeTitle($idMovie)
    {
        // Get cached response if exist
        $cacheKey = $this->getCacheKey(serialize(array(__METHOD__, $idMovie)));
        if ($response = $this->getFromCache($cacheKey))
            return $response;

        $response = $this->api->movieInfo($idMovie, 'alternative_titles');

        if ($this->checkResponse($response) === false)
            return null;

        // Normalize some variables
        if (isset($response['titles']) && !empty($response['titles'])) {
            $data = array();
            foreach ($response['titles'] as $title) {
                if ($this->language === null || (isset($title['iso_3166_1']) && strtolower($title['iso_3166_1']) == $this->language))
                    array_push($data, $title['title']);
            }
            $response['aka'] = $data;
        }
        unset($response['titles']);

        unset($response['id']);

        // Save response to cache
        $this->storeToCache($cacheKey, $response);

        return $response;
    }

    /**
     * Movie image
     *
     * Response example
     * ???
     * @param $idMovie
     * @return array|null
     */
    private function apiMovieImages($idMovie)
    {
        // Get cached response if exist
        $cacheKey = $this->getCacheKey(serialize(array(__METHOD__, $idMovie)));
        if ($response = $this->getFromCache($cacheKey))
            return $response;

        $response = $this->api->moviePoster($idMovie);

        if ($this->checkResponse($response) === false)
            return null;

        // Normalize some variables
        $data = array();
        if (!empty($response)) {
            $data = array();
            foreach ($response as $image) {
                array_push($data, $this->imageUrl . $image['file_path']);
            }

        }
        $response = array();
        $response['images'] = $data;

        // Save response to cache
        $this->storeToCache($cacheKey, $response);

        return $response;
    }

    /**
     * Movie
     *
     * Response example
     * {
     * "id": 597,
     * "cast": [
     * {
     * "cast_id": 20,
     * "character": "Rose DeWitt Bukater",
     * "credit_id": "52fe425ac3a36847f80179cb",
     * "id": 204,
     * "name": "Kate Winslet",
     * "order": 0,
     * "profile_path": "/b8tIN5SNgqsXwdmMVCMZP84ACcr.jpg"
     * },
     * }
     * ],
     * "crew": [
     * {
     * "credit_id": "52fe425ac3a36847f801795b",
     * "department": "Directing",
     * "id": 2710,
     * "job": "Director",
     * "name": "James Cameron",
     * "profile_path": "/zy2foCd8PEtvCcsX48cROdQdDLB.jpg"
     * },
     * ]
     * }
     *
     * @param $idMovie
     * @return array|null
     */
    private function apiMovieCredits($idMovie)
    {
        // Get cached response if exist
        $cacheKey = $this->getCacheKey(serialize(array(__METHOD__, $idMovie)));
        if ($response = $this->getFromCache($cacheKey))
            return $response;

        $response = $this->api->movieInfo($idMovie, "credits", false);

        if ($this->checkResponse($response) === false)
            return null;

        // Normalize some variables
        if (isset($response['cast']) && !empty($response['cast'])) {
            $data = array();
            foreach ($response['cast'] as $cast) {
                array_push($data, $cast['name']);
            }
            $response['actors'] = $data;
        }
        unset($response['cast']);
        if (isset($response['crew']) && !empty($response['crew'])) {
            $directors = array();
            $writers = array();
            foreach ($response['crew'] as $cast) {
                switch ($cast["job"]) {
                    case "Director":
                        array_push($directors, $cast['name']);
                        break;
                    case "Screenplay":
                        array_push($writers, $cast['name']);
                        break;
                }
                $response['directors'] = $directors;
                $response['writers'] = $writers;
            }
            unset($response['crew']);
        }

        unset($response['id']);

        // Save response to cache
        $this->storeToCache($cacheKey, $response);

        return $response;
    }

    /**
     * Movie Trailer
     *
     * Response example
     * {
     * "id": 597,
     * "quicktime": [],
     * "youtube": [
     * {
     * "name": "Titanic 3 D Trailer Hd",
     * "size": "HD",
     * "source": "G-529EbytfU",
     * "type": "Trailer"
     * }
     * ]
     * }
     * @param $idMovie
     * @return null|string
     */
    private function apiMovieTrailers($idMovie)
    {
        // Get cached response if exist
        $cacheKey = $this->getCacheKey(serialize(array(__METHOD__, $idMovie)));
        if ($response = $this->getFromCache($cacheKey))
            return $response;

        $response = $this->api->movieTrailer($idMovie);

        if ($this->checkResponse($response) === false)
            return null;

        // Normalize some variables
        if (isset($response['youtube']) && !empty($response['youtube'])) {
            $data = array();
            foreach ($response['youtube'] as $youtube) {
                array_push($data, 'youtube:' . $youtube['source']);
            }
            $response['trailers'] = $data;
        }
        unset($response['youtube']);
        unset($response['quicktime']);

        unset($response['id']);

        // Save response to cache
        $this->storeToCache($cacheKey, $response);

        return $response;
    }

    /**
     * Movie
     *
     * Response example
     * @param $idMovie
     * @return array|null
     */
    private function apiMovieKeywords($idMovie)
    {
        // Get cached response if exist
        $cacheKey = $this->getCacheKey(serialize(array(__METHOD__, $idMovie)));
        if ($response = $this->getFromCache($cacheKey))
            return $response;

        $response = $this->api->movieInfo($idMovie, 'keywords');

        if ($this->checkResponse($response) === false)
            return null;

        // Normalize some variables
        if (isset($response['keywords']) && !empty($response['keywords'])) {
            $data = array();
            foreach ($response['keywords'] as $keyword) {
                array_push($data, $keyword['name']);
            }
            $response['tags'] = $data;
        }
        unset($response['keywords']);

        unset($response['id']);

        // Save response to cache
        $this->storeToCache($cacheKey, $response);

        return $response;
    }

    /**
     * Check response validity and log any findings
     * @param mixed $response
     * @return bool true if valid, false otherwise
     */
    protected function checkResponse($response)
    {
        if ((isset($response["status_code"]) && isset($response["status_message"]))) {
            Yii::log(sprintf('API method %s returned error %s - message: %s', __METHOD__, $response["status_code"], $response["status_message"]));
            return false;
        }

        return true;
    }

    /**
     * Creates a MovieInfo object using mapping
     *
     * @param mixed $data
     * @return MovieInfo model
     */
    protected function mapToMovieInfo($data)
    {
        if (!isset($data['imdb_id']) || empty($data['imdb_id'])) {
            Yii::log(sprintf('Imdb id is missing - data: ' . serialize($data)), CLogger::LEVEL_WARNING);
            return null;
        }

        $model = new MovieInfo();
        $model->apis = array($this->_name);

        foreach ($this->responseMapping as $m => $i) {
            if ($i && isset($data[$i]) && !in_array($data[$i], array(null, ''))) {
                if (MovieInfo::$attributesType[$m] == MovieInfo::TYPE_ARRAY && !is_array($data[$i]))
                    $data[$i] = array_map('trim', explode(BaseApi::DELIMETER, $data[$i]));
                else if (MovieInfo::$attributesType[$m] == MovieInfo::TYPE_TEXT && is_array($data[$i]))
                    $data[$i] = implode(BaseApi::DELIMETER, $data[$i]);
                else if (is_string($data[$i]))
                    $data[$i] = trim($data[$i]);
                $model->{$m} = $data[$i];
            }
        }

        return $model;
    }

}