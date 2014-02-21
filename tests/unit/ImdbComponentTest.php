<?php

YiiBase::import('ext.movieInfo.components.MovieInfoComponent');
YiiBase::import('ext.movieInfo.models.MovieInfo');

class MovieInfoComponentTest extends CTestCase {

    public function testSearchByTitle() {
        $movieInfoComponent = new MovieInfoComponent(false/* no caching */);
        $movie = $movieInfoComponent->searchByTitleYear('Titanic');
        $movieInfoComponent->clearMovies();
        $this->assertEquals($movie->id, 'tt0120338');
        $this->assertEquals(array_diff($movie->apis, MovieInfoComponent::$apis), array());
    }
    public function testSearchById() {
        $movieInfoComponent = new MovieInfoComponent(false/* no caching */);
        $movie = $movieInfoComponent->searchById('tt0120338');//tt1392214
        $movieInfoComponent->clearMovies();
        $this->assertEquals($movie->title, 'Titanic');
        $this->assertEquals(array_diff($movie->apis, MovieInfoComponent::$apis), array());
    }

}
