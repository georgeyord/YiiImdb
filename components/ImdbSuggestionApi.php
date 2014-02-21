<?php

class ImdbSuggestionApi extends BaseCurlApi
{
    /**
     * Logging path
     */
    const LOGPATH = 'app.components.movieinfo.imdbsuggestion';

    /**
     * @var string Url endpoint to request data
     */
    protected $url = 'http://sg.media-imdb.com/suggests/{title:first}/{title}.json';

    /**
     * @var array map MovieInfo attributes to custom fields returned from API
     */
    protected $responseMapping = array(
        'imdbId' => 'id',
        'title' => 'l',
        'year' => 'y',
        'actors' => 's',
    );

    /**
     * Search for title using the query string given or a part of the query string
     *
     * @param $query
     * @return array of imdbId/MovieInfo model pairs
     */
    public function instantSearch($query)
    {
        $url = $this->buildUrlFromPlaceholders($this->url, array('{title}' => $query));

        // Get cached response if exist
        if ($response = $this->getFromCache($this->getCacheKey($url)))
            return $response;

        $movies = array();
        $response = $this->makeRequest($url);

        if ($this->checkResponse($response)) {
            $response = $this->processResponse($response);

            // Normalize some variables
            if (isset($response->d) && !empty($response->d)) {
                foreach ($response->d as $data) {
                    array_push($movies, $this->mapToMovieInfo($data));
                }
            }
        } else if ($response === null && strlen($query) > 3) {
            $query = mb_substr($query, 0, -1);
            $movies = $this->instantSearch($query);
        } else if ($response === false)
            $this->setRequestErrors();

        if (!empty($movies))
            $this->storeToCache($this->getCacheKey($url), $movies);

        return $movies;
    }

    /**
     * Creates a MovieInfo object using mapping
     *
     * @param mixed $data
     * @return MovieInfo model
     */
    protected function mapToMovieInfo($data)
    {
        $data = (array)$data;
        if (!isset($data['id']) || empty($data['id'])) {
            Yii::log(sprintf('Imdb id is missing - data: ' . serialize($data)));
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

    /**
     * Decode and check response, override default
     * json behavior with JSONP behavior
     *
     * @param string $response
     * @return tdClass object
     */
    protected function processResponse($response)
    {
        $response = $response->data;
        // Decode and validate json
        if ($response[0] !== '[' && $response[0] !== '{') { // we have JSONP
            $response = substr($response, strpos($response, '('));
        }
        return json_decode(trim($response, '();'));
    }
}