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
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;

class DefaultController extends Controller
{
    /** @var int - Rule: only count in members which are this level
     * @TODO remove rule to own class or something
     */
    const MAX_LEVEL = 120;
    /** @var array - Holds the error messages which might happen during runtime. Will be cached on filesystem later on. */
    private $_errors = [];
    /** @var string - Holds Predefined string for WoW dungeon leaderboards. Will be later on manipulated via sprintf */
    const BASE_URL_LEADERBOARD = "https://worldofwarcraft.com/%s/game/pve/leaderboards/%s/%s"; //region, server, dungeon
    /** @var int - Defines to check for this key as highest. */
    const CHECK_HIGHEST_KEY = 10;
    /** @var array - Current available dungeons with leaderboards */
    private $_dungeons = [
        'ataldazar',
        'Freehold',
        'kings-rest',
        'shrine-of-the-storm',
        'siege-of-boralus',
        'temple-of-sethraliss',
        'the-motherlode',
        'the-underrot',
        'tol-dagor',
        'waycrest-manor',
    ];

    /** @var  CacheItem */
    private $_cachedErrors;

    /** @var  Request */
    private $_request;

    /** @var int - Holds the current unixtimestamp (set after indexAction is called) - For error log purpose */
    private $_currentTimestamp;

    /**
     * Default route.
     *
     * @param $request - Current Request Object
     *
     * url guild=myth&realm=mal%27ganis&granks=any&region=eu
     * @Route("/", name="home")
     *
     * @return string - Compiled html or simple messages by die()
     */
    public function indexAction(Request $request)
    {
        ini_set('max_execution_time', -1);
        $this->_currentTimestamp = time();
        if (!$request->query->get('guild')) die('Unset GET Parameter "guild".');
        if (!$request->query->get('realm')) die('Unser GET Parameter "realm".');
        if (!$request->query->get('granks')) die('Unset GET Parameters "granks".');
        if (!$request->query->get('region')) die('Unset GET Parameters "region".');
        $this->_request = $request;
        /** @var CacheItem $cachedErrors */
        $cachedErrors = $this->get('cache.app')->getItem('app_errors');
        if (!$this->validateRequest()) die("Request invalid!");
        if ($request->query->get('granks') === 'any')
            $request->query->set('granks', implode(',', range(0, 9)));
        $members = $this->getMembers($request->query->get('guild'), $request->query->get('realm'), $request->query->get('region'));
        if (empty($members)) die('No Guild members found!');
        $members = $this->getMemberRunKeysCurrently($members);
        if (empty($members)) die('No Members did run any keys I"m afraid.');
        $cachedErrors->set($this->_errors);
        $this->get('cache.app')->save($cachedErrors);

        //@TODO move to own method
        //Filter members with + keys, count
        $membersWithKeysCount    = 0;
        $membersWithPlusKeyCount = 0;
        $membersWithPlusKeys     = [];
        $memberChecked           = [];
        foreach ($members as $member) {
            if (isset($member->m_plus_information)) {
                $membersWithKeysCount++;
                //FIXME members should be accounted for one + key a week! (@TODO I dont know why I meant with this fixme .. lol..)
                foreach ($member->m_plus_information as $dungeonMythic) {
                    $membersWithPlusKeys[$member->character->name]['all'][] = $dungeonMythic;
                    if (TRUE === $dungeonMythic[sprintf('keystone_greaterOr%s', self::CHECK_HIGHEST_KEY)]
                        && !isset($memberChecked[$member->character->name])
                    ) {
                        $memberChecked[$member->character->name] = TRUE;
                        $membersWithPlusKeyCount++;
                    }
                }
                $bestKey                                               = (function ($arr): array {
                    $last = 0;
                    $best = NULL;
                    foreach ($arr as $item) {
                        if ((int)$item['keyStone'] > $last) {
                            $best = $item;
                            $last = (int)$item['keyStone'];
                        }
                    }
                    return $best;
                })($membersWithPlusKeys[$member->character->name]['all']);
                $membersWithPlusKeys[$member->character->name]['best'] = $bestKey;
            }
        }
        $data = [
            "membersWithPlus"         => $membersWithPlusKeys,
            "minHigh"                 => self::CHECK_HIGHEST_KEY . '+',
            "membersWithPlusKeyCount" => $membersWithPlusKeyCount,
            "membersWithKeysCount"    => $membersWithKeysCount,
        ];

        return $this->render('mythicplus/index.html.twig', [
            'base_dir' => realpath($this->getParameter('kernel.root_dir') . '/..') . DIRECTORY_SEPARATOR,
            'data'     => $data
        ]);
    }

    /**
     * Returns a guild members, if some rules apply to each individual member
     * Current rules: level 120, Guildrank is GM, Officer or Raider
     *
     *
     * @param string $guildName - Guild name
     * @param string $realmName - Guilds home realm (if connected realm, enter realm-name where it was first created)
     * @param string $region    - Region the guild is on. Available options = "eu", "us"
     *
     * @return array - Array of members, 1D
     */
    protected function getMembers(string $guildName, string $realmName, string $region): array
    {
        /** @var CacheItem $cachedGuildMembers */
        $cachedGuildMembers = $this->get('cache.app')->getItem(sprintf('guild_members_%s-%s-%s', $guildName, $realmName, $region));
        $cachedGuildMembers->expiresAfter(\DateInterval::createFromDateString('2 days'));
        $members = [];
        if (!$cachedGuildMembers->isHit()) {
            try {
                /** @var string $locale - Local information, needed for API call */
                //@TODO outsource this :|
                $locale = "en_gb";
                switch ($region) {
                    case 'us':
                        $locale = "en_us";
                        break;
                }
                /** @var BlizzardClient $client */
                $client = new BlizzardClient($this->getParameter('blizzard_apikey'), $this->getParameter('blizzard_apisecret'), $region, $locale);
                /** @var WorldOfWarcraft $wow */
                $wow = new WorldOfWarcraft($client);

                $response = $wow->getGuild($realmName, $guildName, [
                    'fields' => 'members',
                ]);
                $members  = [];
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
                if (404 !== $e->getCode()) { //404 errors are fine, they happen when blizzard fucked up somewhere?!
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
     *
     * @return array - Array of Members with key identification (if any)
     */
    protected function getMemberRunKeysCurrently($members): array
    {
        /** @var CacheItem $cachedKeysMembers */
        $cachedKeysMembers = $this->get('cache.app')->getItem(sprintf('members_keys_%s-%s-%s',
            $this->_request->query->get('guild'),
            $this->_request->query->get('realm'),
            $this->_request->query->get('region')));
        $cachedKeysMembers->expiresAfter(\DateInterval::createFromDateString('2 days'));
        if (!$cachedKeysMembers->isHit()) {
            if (!empty($members)) {
                foreach ($members as $member) {
                    if (!isset($member->character->realm)) continue; //Sometimes this fails, so better check than be sorry.
                    foreach ($this->_dungeons as $dungeon) {
                        $htmlDomLeaderboard = NULL;
                        try {
                            //@TODO outsource this
                            $region = "en_gb";
                            $url    = self::BASE_URL_LEADERBOARD;
                            switch ($this->_request->query->get('region')) {
                                case 'us':
                                    $region = "en_us";
                                    break;
                            }
                            $uri = sprintf($url,
                                $region,
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
                        $urlArmory = sprintf('/%s/character/%s/%s',
                            str_replace(['_'], ['-'], $region),
                            strtolower(str_replace(['\''], [''], $member->character->realm)),
                            $member->character->name);
                        if (stripos($htmlDomLeaderboard, $urlArmory) !== FALSE) {
                            //Is found at least, now check the mythic difficulty

                            //I need to convert encoding to ensure I find the people inside DOM html string (somehow stripos overlooks this?!)
                            $htmlDomLeaderboard = mb_convert_encoding($htmlDomLeaderboard, 'HTML-ENTITIES', 'UTF-8');
                            $crawler            = new Crawler($htmlDomLeaderboard);
                            try {
                                if ($crawler->filter("a[href='" . $urlArmory . "']")->count()) {
                                    $nodes = $crawler->filter("a[href='" . $urlArmory . "']")->parents()->each(function (Crawler $node, $i) {
                                        if ($i == 3) { //This is so I only receive this current table-td
                                            return $node->children();
                                        }
                                    });
                                } else {
                                    //User couldnt be retrieved from DOM, so far I found no other solution. The info is lost, the user must report to DKP master themselfes.
                                }
                                if (empty($nodes)) continue;
                                $nodes = array_filter($nodes);
                                /** @var Crawler $node */
                                $node          = current($nodes);
                                $ranking       = $node->getNode(0)->textContent;
                                $mythicKey     = $node->getNode(1)->textContent;
                                $dateCompleted = $node->getNode(4)->textContent; //date format: m/d/y
                                $completedIn   = $node->getNode(2)->textContent;

                                $member->m_plus_information[] = [
                                    'dungeon'                                                => $dungeon,
                                    'leaderboardRank'                                        => $ranking,
                                    'keyStone'                                               => $mythicKey,
                                    'dateCompleted'                                          => $dateCompleted,
                                    'completedIn'                                            => $completedIn,
                                    sprintf('keystone_greaterOr%d', self::CHECK_HIGHEST_KEY) => (bool)(intval($mythicKey) >= self::CHECK_HIGHEST_KEY),
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
    private function validateRequest(): bool
    {
        return is_string($this->_request->get('guild'))
               && is_string($this->_request->get('realm'))
               && is_string($this->_request->get('region'))
               && in_array($this->_request->get('region'), ['us', 'eu'])
               && (count(array_filter(explode(',', $this->_request->get('granks')), function ($k) {
                    return is_numeric($k);
                }, ARRAY_FILTER_USE_BOTH)) >= 1
                   || $this->_request->get('granks') === 'any');
    }
}
