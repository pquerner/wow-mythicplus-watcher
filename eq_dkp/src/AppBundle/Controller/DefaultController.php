<?php

namespace AppBundle\Controller;

use BlizzardApi\BlizzardClient;
use BlizzardApi\Service\WorldOfWarcraft;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\CacheItem;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;

class DefaultController extends Controller
{
    const MAX_LEVEL = 110;
    private $_guildRanks = [
        3, //Raider
        1, //Offi
        0, //GM
    ];
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

    /**
     * @Route("/", name="home")
     */
    public function indexAction(Request $request)
    {
        $members = $this->getMembers("equilibria", "anub'arak");
        $members = $this->getMemberRunKeysCurrently($members);

        $membersWithKeysCount = 0;
        $membersWith15PlusKeysCount = 0;
        $membersWith15PlusKeys = [];
        foreach ($members as $member) {
            if (isset($member->m_plus_information)) {
                $membersWithKeysCount++;
                //FIXME members should be accounted for one 15+ key a week!
                foreach ($member->m_plus_information as $dungeonMythic) {
                    if (TRUE === $dungeonMythic['keystone_greaterOr15']) {
                        if (!in_array($member->character->name, $membersWith15PlusKeys)) {
                            $membersWith15PlusKeysCount++;
                            $membersWith15PlusKeys[] = $member->character->name;
                        }
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
        $cachedGuildMembers = $this->get('cache.app')->getItem('guild_members');
        $cachedGuildMembers->expiresAfter(\DateInterval::createFromDateString('1 week'));
        if (!$cachedGuildMembers->isHit()) {

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
                        if ($member->character->level === self::MAX_LEVEL && in_array($member->rank, $this->_guildRanks)) {
                            $members[] = $member;
                        }
                    }
                }
            }
            $cachedGuildMembers->set($members);
            $this->get('cache.app')->save($cachedGuildMembers);
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
        $cachedKeysMembers = $this->get('cache.app')->getItem('members_keys');
        $cachedKeysMembers->expiresAfter(\DateInterval::createFromDateString('1 week'));
        if (!$cachedKeysMembers->isHit()) {
            if (!empty($members)) {
                foreach ($members as $member) {
                    foreach ($this->_dungeons as $dungeon) {
                        $uri = sprintf(self::BASE_URL_LEADERBOARD,
                            strtolower(str_replace(['\''], [''], $member->character->realm)),
                            $dungeon);
                        /** @var Client $client */
                        $client = new Client();
                        /** @var Response $request */
                        $response = $client->get($uri);
                        $htmlDomLeaderboard = (string)$response->getBody();
                        $urlArmory = sprintf('http://eu.battle.net/wow/en/character/%s/%s/simple',
                            strtolower(str_replace(['\''], [''], $member->character->realm)),
                            $member->character->name);
                        if (stripos($htmlDomLeaderboard, $urlArmory) !== FALSE) {
                            //Is found at least, now check the mythic difficulty
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
                                $this->_errors['e_findingKey'][$member->character->name][] = [$dungeon, 'error' => $e];
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
}
