<?php

namespace AppBundle\Controller;

use BlizzardApi\BlizzardClient;
use BlizzardApi\Service\WorldOfWarcraft;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Response;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\CacheItem;
use Symfony\Component\Config\Definition\Exception\Exception;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;

class DefaultController extends Controller
{
    const MAX_LEVEL = 110;
    private $_errors = [];
    const BASE_URL_LEADERBOARD = "https://worldofwarcraft.com/en-gb/game/pve/leaderboards/%s/%s"; //server, dungeon
    private $_dungeons = [
        'neltharions-lair',
        'black-rook-hold',
        'court-of-stars',
        'darkheart-thicket',
        'eye-of-azshara',
        'halls-of-valor',
        'maw-of-souls',
        'the-arcway',
        'vault-of-the-wardens',
    ];

    /** @var  CacheItem */
    private $_cachedErrors;

    /** @var  Request */
    private $_request;

    /** @var int - Holds the current unixtimestamp (set after indexAction is called) - For error log purpose */
    private $_currentTimestamp;

    /**
     * @Route("/", name="home")
     */
    public function indexAction(Request $request)
    {
        $this->_currentTimestamp = time();
        if (!$request->query->get('guild')) die('Keine Gilde genannt. Bitte GET Parameter "guild" setzen!');
        if (!$request->query->get('realm')) die('Keine Gilde genannt. Bitte GET Parameter "realm" setzen!');
        if (!$request->query->get('granks')) die('Keine Gildenranks genannt. Bitte GET Parameter "granks" setzen!');
        $this->_request = $request;
        /** @var CacheItem $cachedErrors */
        $cachedErrors = $this->get('cache.app')->getItem('app_errors');
        if (!$this->validateRequest()) die("Request invalid!");
        $members = $this->getMembers($request->query->get('guild'), $request->query->get('realm'));
        if (empty($members)) die('Keine Member gefunden. Parameter ueberpruefen!');
        $members = $this->getMemberRunKeysCurrently($members);
        if (empty($members)) die('Keine Member sind Keys gelaufen!');
        $cachedErrors->set($this->_errors);
        $this->get('cache.app')->save($cachedErrors);

        $membersWithKeysCount = 0;
        $membersWith15PlusKeysCount = 0;
        $membersWith15PlusKeys = [];
        foreach ($members as $member) {
            if (isset($member->m_plus_information)) {
                $membersWithKeysCount++;
                //FIXME members should be accounted for one 15+ key a week!
                foreach ($member->m_plus_information as $dungeonMythic) {
                    if (TRUE === $dungeonMythic['keystone_greaterOr15'] && !in_array($member->character->name, $membersWith15PlusKeys)) {
                        $membersWith15PlusKeysCount++;
                        $membersWith15PlusKeys[] = $member->character->name;
                    }
                }
            }
        }

        $membersWith15PlusKeys = array_unique($membersWith15PlusKeys);
        $data = [
            "membersWith15Plus" => $membersWith15PlusKeys,
            "membersWith15PlusKeysCount" => $membersWith15PlusKeysCount,
            "membersWithKeysCount" => $membersWithKeysCount,
        ];

        return $this->render('eqdkp/index.html.twig', [
            'base_dir' => realpath($this->getParameter('kernel.root_dir') . '/..') . DIRECTORY_SEPARATOR,
            'data' => $data
        ]);
    }

    /**
     * Returns a guild members, if some rules apply to each individual member
     * Current rules: level 110, Guildrank is GM, Officer or Raider
     *
     *
     * @param string $guildName - Guild name
     * @param string $realmName - Guilds home realm (if connected realm, enter realm-name where it was first created)
     * @return array - Array of members, 1D
     */
    protected function getMembers(string $guildName, string $realmName):array
    {
        /** @var CacheItem $cachedGuildMembers */
        $cachedGuildMembers = $this->get('cache.app')->getItem(sprintf('guild_members_%s-%s', $guildName, $realmName));
        $cachedGuildMembers->expiresAfter(\DateInterval::createFromDateString('1 week'));
        $members = [];
        if (!$cachedGuildMembers->isHit()) {
            try {
                /** @var BlizzardClient $client */
                $client = new \BlizzardApi\BlizzardClient('rfap5vgxshjmq62m6vmgck9htegnhswh', 'N73NpzUMTZ2tGJQyXettuzqyXbsrSaKJ', 'eu', 'en_gb');
                /** @var WorldOfWarcraft $wow */
                $wow = new \BlizzardApi\Service\WorldOfWarcraft($client);

                $response = $wow->getGuild($realmName, $guildName, [
                    'fields' => 'members',
                ]);
                $members = [];
                if (200 == $response->getStatusCode()) {
                    $arrayOfGuildInformation = (array)\GuzzleHttp\json_decode((string)$response->getBody());
                    if (isset($arrayOfGuildInformation['members']) && !empty($arrayOfGuildInformation['members'])) {
                        foreach ($arrayOfGuildInformation['members'] as $member) {
                            if ($member->character->level === self::MAX_LEVEL && in_array($member->rank, array_filter(explode(',', $this->_request->get('granks')), function ($k) {
                                    return is_numeric($k);
                                }, ARRAY_FILTER_USE_BOTH))
                            ) {
                                $members[] = $member;
                            }
                        }
                    }
                }
                $cachedGuildMembers->set($members);
                $this->get('cache.app')->save($cachedGuildMembers);
            } catch (RequestException $e) {
                if (404 !== $e->getCode()) {
                    $this->_errors['guild_fetch_error'][$this->_currentTimestamp][] = $e;
                }
            }
        } else {
            $members = $cachedGuildMembers->get();
        }
        return $members;
    }

    /**
     * Returns same $members Array but this time with key identification, if there are any (for this week only)
     *
     * @TODO this method looks ugly, go clean it up
     *
     * @param $members - Array of Members and some details (ie. ranks, realm-name, name, ..)
     * @return array - Array of Members with key identification (if any)
     */
    protected function getMemberRunKeysCurrently($members):array
    {
        /** @var CacheItem $cachedKeysMembers */
        $cachedKeysMembers = $this->get('cache.app')->getItem(sprintf('members_keys_%s-%s', $this->_request->query->get('guild'), $this->_request->query->get('realm')));
        $cachedKeysMembers->expiresAfter(\DateInterval::createFromDateString('1 week'));
        if (!$cachedKeysMembers->isHit()) {
            if (!empty($members)) {
                foreach ($members as $member) {
                    foreach ($this->_dungeons as $dungeon) {
                        $htmlDomLeaderboard = NULL;
                        try {
                            $uri = sprintf(self::BASE_URL_LEADERBOARD,
                                strtolower(str_replace(['\''], [''], $member->character->realm)),
                                $dungeon);
                            /** @var Client $client */
                            $client = new Client();
                            /** @var Response $request */
                            $response = $client->get($uri);
                            if ($response->getStatusCode() === 200) {
                                $htmlDomLeaderboard = (string)$response->getBody();
                            }
                        } catch (RequestException $e) {
                            if ($e->getCode() !== 404) { //Ignore 404 errors, some end on blizzard fucked up, not me!
                                $this->_errors['e_findingLeaderboards'][$this->_currentTimestamp][$member->character->name][] = [$dungeon, 'error' => $e];
                            }
                        }
                        if (NULL === $htmlDomLeaderboard) continue;
                        $urlArmory = sprintf('http://eu.battle.net/wow/en/character/%s/%s/simple',
                            strtolower(str_replace(['\''], [''], $member->character->realm)),
                            $member->character->name);
                        if (stripos($htmlDomLeaderboard, $urlArmory) !== FALSE) {
                            //Is found at least, now check the mythic difficulty

                            //I need to convert encoding to ensure I find the people inside DOM html string (somehow stripos overlooks this?!)
                            $htmlDomLeaderboard = mb_convert_encoding($htmlDomLeaderboard, 'HTML-ENTITIES', 'UTF-8');
                            $crawler = new Crawler($htmlDomLeaderboard);
                            $i = 0;
                            try {
                                if ($crawler->filter("a[href='" . $urlArmory . "']")->count()) {
                                    $nodes = $crawler->filter("a[href='" . $urlArmory . "']")->parents()->each(function (Crawler $node, $i) {
                                        if ($i == 3) { //This is so I only receive this current table-td
                                            return $node->text();
                                        }
                                        $i++;
                                    });
                                } else {
                                    //User couldnt be retrieved from DOM, so far I found no other solution. The info is lost, the user must report to DKP master themselfes.
                                }
                                if (empty($nodes)) continue;
                                $emptyRemoved = array_filter($nodes, 'strlen');

                                $rawStringInformation = end($emptyRemoved);
                                $ranking = mb_substr($rawStringInformation, 0, 2);
                                $mythicKey = mb_substr($rawStringInformation, 2, 2);
                                $date = mb_substr($rawStringInformation, -10); //date format: m/d/y

                                $member->m_plus_information[] = [
                                    'dungeon' => $dungeon,
                                    'leaderboard_rank' => $ranking,
                                    'keystone' => $mythicKey,
                                    'date' => $date,
                                    'keystone_greaterOr15' => (bool)(intval($mythicKey) >= 15),
                                ];

                            } catch (\InvalidArgumentException $e) {
                                $this->_errors['e_findingKey'][$this->_currentTimestamp][$member->character->name][] = [$dungeon, 'error' => $e];
                            }
                        } else {
                            //Member not found in current ranking for this current dungeon, possible didnt do a "high" M+ run or something else happend
                        }
                    }
                }
                $cachedKeysMembers->set($members);
                $this->get('cache.app')->save($cachedKeysMembers);
            }
        } else {
            $members = $cachedKeysMembers->get();
        }
        return $members;
    }


    /**
     * Returns whether or not current request is valid for this operation
     *
     * @TODO remove calculation of non empty array values to own method and use app wide (see near line 123)
     *
     * @return bool
     */
    private function validateRequest():bool
    {
        return is_string($this->_request->get('guild'))
        && is_string($this->_request->get('realm'))
        && count(array_filter(explode(',', $this->_request->get('granks')), function ($k) {
            return is_numeric($k);
        }, ARRAY_FILTER_USE_BOTH)) >= 1;
    }
}
