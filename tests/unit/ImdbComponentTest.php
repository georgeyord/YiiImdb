<?php

YiiBase::import('ext.imdb.components.ImdbComponent');
YiiBase::import('ext.imdb.models.ImdbMovie');

class ImdbComponentTest extends CTestCase {

    public function testGenericUse() {
        $imdbComponent = new ImdbComponent(false/* no caching */);
        $movie = $imdbComponent->searchByTitleYear('Titanic');
        $imdbComponent->clearMovies();
        $this->assertEquals($movie->id, 'tt0120338');
        $this->assertEquals(array_diff($movie->apis, ImdbComponent::$apis), array());
    }

}
