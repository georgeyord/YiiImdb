<?php

/**
 * ImdbMovie
 *
 * This class is a container for Movie data from IMDB.
 *
 * NOTE: Not all properties are available from all APIs
 */
class ImdbMovie extends CFormModel {

    public $imdbId;
    public $imdbUrl;
    public $title;
    public $aka;
    public $imdbRating;
    public $votes;
    public $genres;
    public $plot;
    public $languages;
    public $countries;
    public $images;
    public $year;
    public $runtime;
    public $directors;
    public $writers;
    public $actors;
    public $rated;
    // API used to retrive this data
    public $apis;

    CONST TYPE_TEXT = 'text';
    CONST TYPE_ARRAY = 'array';

    public static $attributesType = array(
        'imdbId' => self::TYPE_TEXT,
        'imdbUrl' => self::TYPE_TEXT,
        'title' => self::TYPE_TEXT,
        'aka' => self::TYPE_ARRAY,
        'imdbRating' => self::TYPE_TEXT,
        'votes' => self::TYPE_TEXT,
        'genres' => self::TYPE_ARRAY,
        'plot' => self::TYPE_TEXT,
        'languages' => self::TYPE_ARRAY,
        'countries' => self::TYPE_ARRAY,
        'images' => self::TYPE_ARRAY,
        'year' => self::TYPE_TEXT,
        'runtime' => self::TYPE_TEXT,
        'directors' => self::TYPE_ARRAY,
        'writers' => self::TYPE_ARRAY,
        'actors' => self::TYPE_ARRAY,
        'rated' => self::TYPE_TEXT,
        'apis' => self::TYPE_ARRAY,
    );
}
?>
