<?php

namespace Tests\AppBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class DefaultControllerTest extends WebTestCase
{
    //@TODO write functional test for this controller, as its the only one relevant in this app
    /**
     *
      Test urls:
     * http://eqdkp/?guild=Limit&realm=illidan&granks=8,4,9,0&region=us
     * http://eqdkp/?guild=uprising&realm=zuluhed&granks=8,4,9,0&region=eu
     *
     * Same with granks "any"
     */
    public function testIndex()
    {
        $client = static::createClient();

        $crawler = $client->request('GET', '/');

        $this->assertEquals(200, $client->getResponse()->getStatusCode());
        $this->assertContains('Welcome to Symfony', $crawler->filter('#container h1')->text());
    }
}
