<?php

/**
 * ImdbMovie
 *
 * This class is a container for Movie data from IMDB.
 *
 * NOTE: Not all properties are available from all APIs
 */
class ImdbMovie extends CFormModel {

    public $id;
    public $url;
    public $title;
    public $aka;
    public $rating;
    public $votes;
    public $genre;
    public $plot;
    public $language;
    public $country;
    public $image;
    public $year;
    public $runtime;
    public $director;
    public $writer;
    public $actor;
    public $rated;

}

?>
