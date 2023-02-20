<?php

// src/Service/LarpingService.php
namespace Kiss\KissBundle\Service;

use App\Entity\Action;
use App\Entity\CollectionEntity;
use App\Entity\DashboardCard;
use App\Entity\Cronjob;
use App\Entity\Endpoint;
use App\Entity\Entity;
use App\Entity\Gateway as Source;
use CommonGateway\CoreBundle\Installer\InstallerInterface;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class InstallationService implements InstallerInterface
{
    private EntityManagerInterface $entityManager;
    private ContainerInterface $container;
    private SymfonyStyle $io;

    public const OBJECTS_THAT_SHOULD_HAVE_CARDS = [
        'https://kissdevelopment.commonground.nu/kiss.openpubSkill.schema.json',
        'https://kissdevelopment.commonground.nu/kiss.resultaatypeomschrijvinggeneriek.schema.json',
        'https://kissdevelopment.commonground.nu/kiss.link.schema.json',
        'https://kissdevelopment.commonground.nu/kiss.afdelingsnaam.schema.json'
    ];

    public const SCHEMAS_THAT_SHOULD_HAVE_ENDPOINTS = [
        ['reference' => 'https://kissdevelopment.commonground.nu/kiss.openpubSkill.schema.json',                 'path' => 'ref/openpub_skill',                    'methods' => []],
        ['reference' => 'https://kissdevelopment.commonground.nu/kiss.openpubType.schema.json',                 'path' => 'ref/openpub_type',                      'methods' => []],
        ['reference' => 'https://kissdevelopment.commonground.nu/kiss.afdelingsnaam.schema.json',                 'path' => 'ref/afdelingsnamen',                    'methods' => []],
        ['reference' => 'https://kissdevelopment.commonground.nu/kiss.link.schema.json',                 'path' => 'kiss/links',                    'methods' => []],
        ['reference' => 'https://kissdevelopment.commonground.nu/kiss.medewerker.schema.json',                 'path' => 'medewerkers',                    'methods' => []],
        ['reference' => 'https://kissdevelopment.commonground.nu/kiss.medewerkerAvailabilities.schema.json',                 'path' => 'mederwerkerAvailabilities',                    'methods' => []],
        ['reference' => 'https://kissdevelopment.commonground.nu/kiss.review.schema.json',                 'path' => 'reviews',                    'methods' => []],
        ['reference' => 'https://kissdevelopment.commonground.nu/kiss.sdgProduct.schema.json',                 'path' => 'sdg/kennisartikelen',                    'methods' => []],
        ['reference' => 'https://kissdevelopment.commonground.nu/kiss.pubPublicatie.schema.json',                 'path' => 'kiss_openpub_pub',                    'methods' => []],
        ['reference' => 'https://kissdevelopment.commonground.nu/kiss.resultaatypeomschrijvinggeneriek.schema.json',                 'path' => 'ref/resultaattypeomschrijvingen',                    'methods' => []],
    ];

    public const SOURCES = [
        ['name' => 'EnterpriseSearch API Search', 'location' => 'https://enterprise-search-ent-http:3002',
            'headers' => ['accept' => 'application/json'], 'auth' => 'apikey', 'apikey' => '!secret-ChangeMe!elastic-search-key', 'configuration' => ['verify' => false]],
        ['name' => 'EnterpriseSearch API Private', 'location' => 'https://enterprise-search-ent-http:3002',
            'headers' => ['accept' => 'application/json'], 'auth' => 'apikey', 'apikey' => '!secret-ChangeMe!elastic-private-key', 'configuration' => ['verify' => false]],
        ['name' => 'OpenPub API', 'location' => 'https://openweb.{yourDomain}/wp-json/wp/v2',
            'headers' => ['accept' => 'application/json'], 'auth' => 'none', 'configuration' => ['verify' => false]]
    ];

    public const PROXY_ENDPOINTS = [
        ['name' => 'Elasticsearch proxy endpoint', 'proxy' => 'EnterpriseSearch API Search', 'path' => 'elastic', 'methods' => ['POST']],
        ['name' => 'OpenPub WP proxy endpoint', 'proxy' => 'OpenPub API', 'path' => 'openpub', 'methods' => ['GET']]
    ];

    public const ACTION_HANDLERS = [
        ['name' => 'HandelsRegisterSearchAction', 'actionHandler' => 'Kiss\KissBundle\ActionHandler\HandelsRegisterSearchHandler', 'listens' => ['commongateway.response.pre']],
        [
            'name' => 'SyncPubAction',
            'actionHandler' => 'App\ActionHandler\SynchronizationCollectionHandler',
            'config'=> [
                'location' => '/kiss_openpub_pub',
                'entity' => 'https://kissdevelopment.commonground.nu/kiss.pubPublicatie.schema.json',
                'source' => 'OpenPub API',
                "apiSource" => [
                    "location" => [
                        "objects" => "#",
                        "object" => "#",
                        "idField" => "id",
                        "dateChangedField" => "modified_gmt"
                    ],
                    "sourcePaginated" => true,
                    "syncFromList" => true,
                    "sourceLeading" => true,
                    "mappingIn" => [
                        "acf.publicationContent" => "acf.publication_content",
                        "acf.publicationFeatured" => "acf.publication_featured",
                        "acf.publicationEndDate" => "acf.publication_end_date",
                        "acf.publicationType" => "acf.publication_type",
                        "acf.publicationSkill" => "acf.publication_skill"
                    ],
                    "mappingOut" => [],
                    "translationsIn" => [],
                    "translationsOut" => [],
                    "skeletonIn" => [],
                    "skeletonOut" => [],
                    "collectionDelete" => true
                ]
            ],
            'conditions' => [
                '==' => [
                    1, 1
                ]
            ],
            'listens' => [
                'kiss.default.listens'
            ]
        ],
        [
            'name' => 'SyncEmployeeElasticAction',
            'actionHandler' => 'App\ActionHandler\SynchronizationPushHandler',
            'config' => [
                'location' => '/api/as/v1/engines/kiss-engine/documents',
                'entity' => 'https://kissdevelopment.commonground.nu/kiss.medewerker.schema.json',
                'source' => 'EnterpriseSearch API Private',
                "apiSource" => [
                    "location" => [
                        "dateChangedField" => "",
                        "idField" => "0.id",
                        "objects" => "#"
                    ],
                    "mappingIn" => [],
                    "mappingOut" => [
                        "object" => "object | array",
                        "object_meta" => "function+department+skills | concatenation <br/>",
                        "title" => "contact.voornaam+contact.voorvoegselAchternaam+contact.achternaam | concatenation &nbsp;",
                        "self" => "'/api/medewerkers/'+_self.id | concatenation",
                        "id" => "'smoelenboek_'+_self.id | concatenation"
                    ],
                    "queryMethod" => "page",
                    "skeletonIn" => [],
                    "skeletonOut" => [
                        "object_bron" => "Smoelenboek",
                        "title" => "medewerker"
                    ],
                    "sourceLeading" => false,
                    "syncFromList" => true,
                    "translationsIn" => [],
                    "translationsOut" => [],
                    "unavailablePropertiesOut" => [
                        "availabilities",
                        "contact",
                        "department",
                        "description",
                        "function",
                        "replacement",
                        "skills",
                        "_self"
                    ]
                ],
                "callService" => [
                    [
                        "key" => "method",
                        "value" => "POST"
                    ]
                ],
                "replaceTwigLocation" => "",
                "useDataFromCollection" => false,
                "queryParams" => [
                ],
                "owner" => "",
                "actionConditions" => [
                ]
            ],
            'conditions' => [
                '==' => [
                    [
                        'var' => 'entity'
                    ],
                    'https://kissdevelopment.commonground.nu/kiss.medewerker.schema.json'
                ]
            ],
            'listens' => [
                'commongateway.object.create',
                'commongateway.object.update'
            ]
        ],
        [
            'name' => 'SyncKennisArtikelElasticAction',
            'actionHandler' => 'App\ActionHandler\SynchronizationPushHandler',
            'config' => [
                'location' => '/api/as/v1/engines/kiss-engine/documents',
                'entity' => 'https://kissdevelopment.commonground.nu/kiss.sdgProduct.schema.json',
                'source' => 'EnterpriseSearch API Private',
                "apiSource" => [
                    "location" => [
                        "dateChangedField" => "",
                        "idField" => "0.id",
                        "objects" => "#"
                    ],
                    "mappingIn" => [
                    ],
                    "mappingOut" => [
                        "object" => "object | array",
                        "object_meta" => "vertalingen.0.specifiekeTekst | concatenation <br/>",
                        "title" => "vertalingen.0.productTitelDecentraal | concatenation &nbsp;",
                        "self" => "'/api/sdg/kennisartikel/'+_self.id | concatenation",
                        "id" => "'kennisartikel_'+_self.id | concatenation"
                    ],
                    "queryMethod" => "page",
                    "skeletonIn" => [
                    ],
                    "skeletonOut" => [
                        "object_bron" => "Kennisartikel",
                        "title" => "kennisartikel"
                    ],
                    "sourceLeading" => false,
                    "syncFromList" => true,
                    "translationsIn" => [
                    ],
                    "translationsOut" => [
                    ],
                    "unavailablePropertiesOut" => [
                        "url",
                        "uuid",
                        "upnLabel",
                        "upnUri",
                        "versie",
                        "publicatieDatum",
                        "productAanwezig",
                        "productValtOnder",
                        "verantwoordelijkeOrganisatie",
                        "bevoegdeOrganisatie",
                        "catalogus",
                        "locaties",
                        "doelgroep",
                        "vertalingen",
                        "gerelateerdeProducten",
                        "_self"
                    ]
                ],
                "callService" => [
                    [
                        "key" => "method",
                        "value" => "POST"
                    ]
                ],
                "replaceTwigLocation" => "",
                "useDataFromCollection" => false,
                "queryParams" => [
                ],
                "owner" => "",
                "actionConditions" => [
                ]
            ],
            'conditions' => [
                '==' => [
                    [
                        'var' => 'entity'
                    ],
                    'https://kissdevelopment.commonground.nu/kiss.sdgProduct.schema.json'
                ]
            ],
            'listens' => [
                'commongateway.object.create',
                'commongateway.object.update'
            ]
        ],
        [
            'name' => 'SendReviewMailAction',
            'actionHandler' => 'App\ActionHandler\EmailHandler',
            'configuration' => [
                "serviceDNS" => "",
                "template" => "eyMgdG9kbzogbW92ZSB0aGlzIHRvIGFuIGVtYWlsIHBsdWdpbiAoc2VlIEVtYWlsU2VydmljZS5waHApICN9CjwhRE9DVFlQRSBodG1sIFBVQkxJQyAiLS8vVzNDLy9EVEQgWEhUTUwgMS4wIFRyYW5zaXRpb25hbC8vRU4iICJodHRwOi8vd3d3LnczLm9yZy9UUi94aHRtbDEvRFREL3hodG1sMS10cmFuc2l0aW9uYWwuZHRkIj4KPGh0bWwgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGh0bWwiPgo8aGVhZD4KICA8bWV0YSBuYW1lPSJ2aWV3cG9ydCIgY29udGVudD0id2lkdGg9ZGV2aWNlLXdpZHRoLCBpbml0aWFsLXNjYWxlPTEuMCIgLz4KICA8bWV0YSBodHRwLWVxdWl2PSJDb250ZW50LVR5cGUiIGNvbnRlbnQ9InRleHQvaHRtbDsgY2hhcnNldD1VVEYtOCIgLz4KICA8dGl0bGU+e3sgc3ViamVjdCB9fTwvdGl0bGU+CgogIDxsaW5rIHJlbD0icHJlY29ubmVjdCIgaHJlZj0iaHR0cHM6Ly9mb250cy5nc3RhdGljLmNvbSIgLz4KICA8bGluawogICAgICAgICAgaHJlZj0iaHR0cHM6Ly9mb250cy5nb29nbGVhcGlzLmNvbS9jc3MyP2ZhbWlseT1GYXVzdGluYTp3Z2h0QDYwMCZkaXNwbGF5PXN3YXAiCiAgICAgICAgICByZWw9InN0eWxlc2hlZXQiCiAgLz4KICA8bGluawogICAgICAgICAgaHJlZj0iaHR0cHM6Ly9mb250cy5nb29nbGVhcGlzLmNvbS9jc3MyP2ZhbWlseT1Tb3VyY2UrU2FucytQcm8mZGlzcGxheT1zd2FwIgogICAgICAgICAgcmVsPSJzdHlsZXNoZWV0IgogIC8+CgogIDxzdHlsZSB0eXBlPSJ0ZXh0L2NzcyIgcmVsPSJzdHlsZXNoZWV0IiBtZWRpYT0iYWxsIj4KICAgIC8qIEJhc2UgLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tICovCgogICAgYm9keSB7CiAgICAgIHdpZHRoOiAxMDAlICFpbXBvcnRhbnQ7CiAgICAgIGhlaWdodDogMTAwJTsKICAgICAgbWFyZ2luOiAwOwogICAgICBtc28tbGluZS1oZWlnaHQtcnVsZTogZXhhY3RseTsKICAgICAgbGluZS1oZWlnaHQ6IDEuNDsKICAgICAgYmFja2dyb3VuZC1jb2xvcjogI2ZmZmZmZjsKICAgICAgY29sb3I6ICM3NDc4N2U7CiAgICAgIC13ZWJraXQtdGV4dC1zaXplLWFkanVzdDogbm9uZTsKICAgIH0KCiAgICBwLAogICAgdWwsCiAgICBvbCwKICAgIGJsb2NrcXVvdGUgewogICAgICBtc28tbGluZS1oZWlnaHQtcnVsZTogZXhhY3RseTsKICAgICAgbGluZS1oZWlnaHQ6IDEuNDsKICAgICAgdGV4dC1hbGlnbjogbGVmdDsKICAgIH0KCiAgICBhIHsKICAgICAgY29sb3I6ICMxZDU1ZmY7CiAgICAgIHRleHQtZGVjb3JhdGlvbjogbm9uZTsKICAgIH0KCiAgICBhOmhvdmVyIHsKICAgICAgdGV4dC1kZWNvcmF0aW9uOiB1bmRlcmxpbmU7CiAgICB9CgogICAgcCBhIHsKICAgICAgdGV4dC1kZWNvcmF0aW9uOiB1bmRlcmxpbmU7CiAgICB9CgogICAgYSBpbWcgewogICAgICBib3JkZXI6IG5vbmU7CiAgICB9CgogICAgdGQgewogICAgICB3b3JkLWJyZWFrOiBicmVhay13b3JkOwogICAgfQogICAgLyogTGF5b3V0IC0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLSAqLwoKICAgIC5oZWFkZXIgewogICAgICBiYWNrZ3JvdW5kOiAjMWQ1NWZmOwogICAgICB3aWR0aDogMTAwJTsKICAgICAgaGVpZ2h0OiAyMzZweDsKICAgICAgYmFja2dyb3VuZC1yZXBlYXQ6IG5vLXJlcGVhdDsKICAgICAgYmFja2dyb3VuZC1wb3NpdGlvbjogY2VudGVyOwogICAgfQoKICAgIC5oZWFkZXItY2VsbCB7CiAgICAgIHBhZGRpbmc6IDE2cHggMjRweDsKICAgIH0KCiAgICAuZW1haWwtd3JhcHBlciB7CiAgICAgIHdpZHRoOiAxMDAlOwogICAgICBtYXJnaW46IDA7CiAgICAgIHBhZGRpbmc6IDA7CiAgICAgIC1wcmVtYWlsZXItd2lkdGg6IDEwMCU7CiAgICAgIC1wcmVtYWlsZXItY2VsbHBhZGRpbmc6IDA7CiAgICAgIC1wcmVtYWlsZXItY2VsbHNwYWNpbmc6IDA7CiAgICAgIGJhY2tncm91bmQtY29sb3I6ICNmZmZmZmY7CiAgICB9CgogICAgLmVtYWlsLWNvbnRlbnQgewogICAgICB3aWR0aDogMTAwJTsKICAgICAgbWFyZ2luOiAwOwogICAgICBwYWRkaW5nOiAwOwogICAgICAtcHJlbWFpbGVyLXdpZHRoOiAxMDAlOwogICAgICAtcHJlbWFpbGVyLWNlbGxwYWRkaW5nOiAwOwogICAgICAtcHJlbWFpbGVyLWNlbGxzcGFjaW5nOiAwOwogICAgfQogICAgLyogTWFzdGhlYWQgLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0gKi8KCiAgICAuZW1haWwtbWFzdGhlYWQgewogICAgICBwYWRkaW5nOiAyNXB4IDA7CiAgICAgIHRleHQtYWxpZ246IGNlbnRlcjsKICAgIH0KCiAgICAuZW1haWwtbWFzdGhlYWRfbG9nbyB7CiAgICAgIHdpZHRoOiA5NHB4OwogICAgfQoKICAgIC5lbWFpbC1tYXN0aGVhZF9uYW1lIHsKICAgICAgZm9udC1zaXplOiAxNnB4OwogICAgICBmb250LXdlaWdodDogNjAwOwogICAgICBjb2xvcjogI2JiYmZjMzsKICAgICAgdGV4dC1kZWNvcmF0aW9uOiBub25lOwogICAgICB0ZXh0LXNoYWRvdzogMCAxcHggMCB3aGl0ZTsKICAgIH0KICAgIC8qIEJvZHkgLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tICovCgogICAgLmVtYWlsLWJvZHkgewogICAgICB3aWR0aDogMTAwJTsKICAgICAgbWFyZ2luOiAwOwogICAgICBwYWRkaW5nOiAwOwogICAgICAtcHJlbWFpbGVyLXdpZHRoOiAxMDAlOwogICAgICAtcHJlbWFpbGVyLWNlbGxwYWRkaW5nOiAwOwogICAgICAtcHJlbWFpbGVyLWNlbGxzcGFjaW5nOiAwOwogICAgICBiYWNrZ3JvdW5kOiBub25lOwogICAgfQoKICAgIC5lbWFpbC1ib2R5X2lubmVyIHsKICAgICAgd2lkdGg6IDY0MHB4OwogICAgICBtYXJnaW46IDAgYXV0bzsKICAgICAgcGFkZGluZzogMDsKICAgICAgLXByZW1haWxlci13aWR0aDogNTcwcHg7CiAgICAgIC1wcmVtYWlsZXItY2VsbHBhZGRpbmc6IDA7CiAgICAgIC1wcmVtYWlsZXItY2VsbHNwYWNpbmc6IDA7CiAgICAgIGJhY2tncm91bmQtY29sb3I6ICNmZmZmZmY7CiAgICB9CgogICAgLmVtYWlsLWZvb3RlciB7CiAgICAgIHdpZHRoOiA2NDBweDsKICAgICAgbWFyZ2luOiAwIGF1dG87CiAgICAgIHBhZGRpbmc6IDA7CiAgICAgIC1wcmVtYWlsZXItd2lkdGg6IDU3MHB4OwogICAgICAtcHJlbWFpbGVyLWNlbGxwYWRkaW5nOiAwOwogICAgICAtcHJlbWFpbGVyLWNlbGxzcGFjaW5nOiAwOwogICAgICB0ZXh0LWFsaWduOiBjZW50ZXI7CiAgICB9CgogICAgLmVtYWlsLWZvb3RlciBwIHsKICAgICAgY29sb3I6ICNhZWFlYWU7CiAgICB9CgogICAgLmJvZHktYWN0aW9uIHsKICAgICAgd2lkdGg6IDEwMCU7CiAgICAgIG1hcmdpbjogNDBweCBhdXRvOwogICAgICBwYWRkaW5nOiAwOwogICAgICAtcHJlbWFpbGVyLXdpZHRoOiAxMDAlOwogICAgICAtcHJlbWFpbGVyLWNlbGxwYWRkaW5nOiAwOwogICAgICAtcHJlbWFpbGVyLWNlbGxzcGFjaW5nOiAwOwogICAgICB0ZXh0LWFsaWduOiBjZW50ZXI7CiAgICB9CgogICAgLmJvZHktc3ViIHsKICAgICAgbWFyZ2luLXRvcDogMjVweDsKICAgICAgcGFkZGluZy10b3A6IDI1cHg7CiAgICAgIGJvcmRlci10b3A6IDFweCBzb2xpZCAjZWRlZmYyOwogICAgfQoKICAgIC5jb250ZW50LWNlbGwgewogICAgICBwYWRkaW5nOiAzNnB4IDE2cHg7CiAgICB9CgogICAgLnByZWhlYWRlciB7CiAgICAgIGRpc3BsYXk6IG5vbmUgIWltcG9ydGFudDsKICAgICAgdmlzaWJpbGl0eTogaGlkZGVuOwogICAgICBtc28taGlkZTogYWxsOwogICAgICBmb250LXNpemU6IDFweDsKICAgICAgbXNvLWxpbmUtaGVpZ2h0LXJ1bGU6IGV4YWN0bHk7CiAgICAgIGxpbmUtaGVpZ2h0OiAxcHg7CiAgICAgIG1heC1oZWlnaHQ6IDA7CiAgICAgIG1heC13aWR0aDogMDsKICAgICAgb3BhY2l0eTogMDsKICAgICAgb3ZlcmZsb3c6IGhpZGRlbjsKICAgIH0KICAgIC8qIEF0dHJpYnV0ZSBsaXN0IC0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLSAqLwoKICAgIC5hdHRyaWJ1dGVzIHsKICAgICAgbWFyZ2luOiAwIDAgMjFweDsKICAgIH0KCiAgICAuYXR0cmlidXRlc19jb250ZW50IHsKICAgICAgYmFja2dyb3VuZC1jb2xvcjogI2VkZWZmMjsKICAgICAgcGFkZGluZzogMTZweDsKICAgIH0KCiAgICAuYXR0cmlidXRlc19pdGVtIHsKICAgICAgcGFkZGluZzogMDsKICAgIH0KICAgIC8qIFJlbGF0ZWQgSXRlbXMgLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tICovCgogICAgLnJlbGF0ZWQgewogICAgICB3aWR0aDogMTAwJTsKICAgICAgbWFyZ2luOiAwOwogICAgICBwYWRkaW5nOiAyNXB4IDAgMCAwOwogICAgICAtcHJlbWFpbGVyLXdpZHRoOiAxMDAlOwogICAgICAtcHJlbWFpbGVyLWNlbGxwYWRkaW5nOiAwOwogICAgICAtcHJlbWFpbGVyLWNlbGxzcGFjaW5nOiAwOwogICAgfQoKICAgIC5yZWxhdGVkX2l0ZW0gewogICAgICBwYWRkaW5nOiAxMHB4IDA7CiAgICAgIGNvbG9yOiAjNzQ3ODdlOwogICAgICBmb250LXNpemU6IDE1cHg7CiAgICAgIG1zby1saW5lLWhlaWdodC1ydWxlOiBleGFjdGx5OwogICAgICBsaW5lLWhlaWdodDogMThweDsKICAgIH0KCiAgICAucmVsYXRlZF9pdGVtLXRpdGxlIHsKICAgICAgZGlzcGxheTogYmxvY2s7CiAgICAgIG1hcmdpbjogMC41ZW0gMCAwOwogICAgfQoKICAgIC5yZWxhdGVkX2l0ZW0tdGh1bWIgewogICAgICBkaXNwbGF5OiBibG9jazsKICAgICAgcGFkZGluZy1ib3R0b206IDEwcHg7CiAgICB9CgogICAgLnJlbGF0ZWRfaGVhZGluZyB7CiAgICAgIGJvcmRlci10b3A6IDFweCBzb2xpZCAjZWRlZmYyOwogICAgICB0ZXh0LWFsaWduOiBjZW50ZXI7CiAgICAgIHBhZGRpbmc6IDI1cHggMCAxMHB4OwogICAgfQoKICAgIC8qIFV0aWxpdGllcyAtLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0gKi8KCiAgICAubm8tbWFyZ2luIHsKICAgICAgbWFyZ2luOiAwOwogICAgfQoKICAgIC5tYXJnaW4tdG9wIHsKICAgICAgbWFyZ2luLXRvcDogOHB4OwogICAgfQoKICAgIC5hbGlnbi1yaWdodCB7CiAgICAgIHRleHQtYWxpZ246IHJpZ2h0OwogICAgfQoKICAgIC5hbGlnbi1sZWZ0IHsKICAgICAgdGV4dC1hbGlnbjogbGVmdDsKICAgIH0KCiAgICAuYWxpZ24tY2VudGVyIHsKICAgICAgdGV4dC1hbGlnbjogY2VudGVyOwogICAgfQogICAgLypNZWRpYSBRdWVyaWVzIC0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLSAqLwoKICAgIEBtZWRpYSBvbmx5IHNjcmVlbiBhbmQgKG1heC13aWR0aDogNjAwcHgpIHsKICAgICAgLmVtYWlsLWJvZHlfaW5uZXIsCiAgICAgIC5lbWFpbC1mb290ZXIgewogICAgICAgIHdpZHRoOiAxMDAlICFpbXBvcnRhbnQ7CiAgICAgIH0KICAgIH0KCiAgICBAbWVkaWEgb25seSBzY3JlZW4gYW5kIChtYXgtd2lkdGg6IDUwMHB4KSB7CiAgICAgIC5idXR0b24gewogICAgICAgIHdpZHRoOiAxMDAlICFpbXBvcnRhbnQ7CiAgICAgIH0KICAgIH0KCiAgICAvKiBDYXJkcyAtLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0gKi8KICAgIC5jYXJkIHsKICAgICAgYmFja2dyb3VuZC1jb2xvcjogI2ZmZjsKICAgICAgYm9yZGVyLXRvcDogMXB4IHNvbGlkICNlMGUxZTU7CiAgICAgIGJvcmRlci1yaWdodDogMXB4IHNvbGlkICNlMGUxZTU7CiAgICAgIGJvcmRlci1ib3R0b206IDFweCBzb2xpZCAjZTBlMWU1OwogICAgICBib3JkZXItbGVmdDogMXB4IHNvbGlkICNlMGUxZTU7CiAgICAgIHBhZGRpbmc6IDI0cHg7CiAgICAgIGRpc3BsYXk6IGlubGluZS1ibG9jazsKICAgICAgY29sb3I6ICMzOTM5M2E7CiAgICAgIHRleHQtZGVjb3JhdGlvbjogbm9uZTsKICAgICAgd2lkdGg6IDEwMCU7CiAgICAgIGJvcmRlci1yYWRpdXM6IDNweDsKICAgICAgYm94LXNoYWRvdzogMCA0cHggM3B4IC0zcHggcmdiYSgwLCAwLCAwLCAwLjA4KTsKICAgICAgLXdlYmtpdC10ZXh0LXNpemUtYWRqdXN0OiBub25lOwogICAgICBtc28tbGluZS1oZWlnaHQtcnVsZTogZXhhY3RseTsKICAgICAgbGluZS1oZWlnaHQ6IDEuNzU7CiAgICAgIGxldHRlci1zcGFjaW5nOiAwLjhweDsKICAgIH0KCiAgICAvKiBCdXR0b25zIC0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLSAqLwoKICAgIC5idXR0b24gewogICAgICBiYWNrZ3JvdW5kLWNvbG9yOiAjMWRiNGVkOwogICAgICBib3JkZXItdG9wOiAxMHB4IHNvbGlkICMxZGI0ZWQ7CiAgICAgIGJvcmRlci1yaWdodDogMThweCBzb2xpZCAjMWRiNGVkOwogICAgICBib3JkZXItYm90dG9tOiAxMHB4IHNvbGlkICMxZGI0ZWQ7CiAgICAgIGJvcmRlci1sZWZ0OiAxOHB4IHNvbGlkICMxZGI0ZWQ7CiAgICAgIGRpc3BsYXk6IGlubGluZS1ibG9jazsKICAgICAgY29sb3I6ICNmZmY7CiAgICAgIHRleHQtZGVjb3JhdGlvbjogbm9uZTsKICAgICAgYm9yZGVyLXJhZGl1czogNHB4OwogICAgICBib3gtc2hhZG93OiAwIDJweCAzcHggcmdiYSgwLCAwLCAwLCAwLjE2KTsKICAgICAgLXdlYmtpdC10ZXh0LXNpemUtYWRqdXN0OiBub25lOwogICAgICBtc28tbGluZS1oZWlnaHQtcnVsZTogZXhhY3RseTsKICAgICAgd2lkdGg6IDEwMCU7CiAgICAgIHRleHQtYWxpZ246IGNlbnRlcjsKICAgICAgZm9udC1zaXplOiAxNHB4OwogICAgICBmb250LXdlaWdodDogNjAwOwogICAgfQoKICAgIC5zbWFsbC1sb2dvIHsKICAgICAgd2lkdGg6IDI0cHg7CiAgICAgIGhlaWdodDogMjRweDsKICAgIH0KCiAgICAuaW5saW5lIHsKICAgICAgZGlzcGxheTogaW5saW5lOwogICAgfQogICAgLyogVHlwZSAtLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0gKi8KCiAgICBwIHsKICAgICAgbWFyZ2luOiAwOwogICAgICBjb2xvcjogIzM5MzkzYTsKICAgICAgZm9udC1zaXplOiAxNXB4OwogICAgICBtc28tbGluZS1oZWlnaHQtcnVsZTogZXhhY3RseTsKICAgICAgbGV0dGVyLXNwYWNpbmc6IG5vcm1hbDsKICAgICAgdGV4dC1hbGlnbjogbGVmdDsKICAgICAgbGluZS1oZWlnaHQ6IDIwcHg7CiAgICB9CgogICAgcCArIHAgewogICAgICBtYXJnaW4tdG9wOiAyMHB4OwogICAgfQoKICAgIHAuc3VmZml4IHsKICAgICAgZm9udC1zaXplOiAxNHB4OwogICAgfQoKICAgIHAuc3ViIHsKICAgICAgZm9udC1zaXplOiAxMnB4OwogICAgfQoKICAgIHAuY2VudGVyIHsKICAgICAgdGV4dC1hbGlnbjogY2VudGVyOwogICAgfQoKICAgIC5zdWJ0bGUgewogICAgICBjb2xvcjogI2IxYjFiMTsKICAgIH0KCiAgICAvKiBGb290ZXIgLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tICovCgogICAgLmxvZ28tbGFiZWwgewogICAgICB2ZXJ0aWNhbC1hbGlnbjogdG9wOwogICAgICBmb250LXNpemU6IDE0cHg7CiAgICAgIG1hcmdpbi1sZWZ0OiA0cHg7CiAgICB9CgogICAgLmZvb3Rlci1jZWxsIHsKICAgICAgcGFkZGluZzogOHB4IDI0cHg7CiAgICB9CgogICAgLmZvb3Rlci1uYXYgewogICAgICBtYXJnaW4tbGVmdDogOHB4OwogICAgICBmb250LXNpemU6IDE0cHg7CiAgICAgIGNvbG9yOiAjMzkzOTNhOwogICAgICB0ZXh0LWRlY29yYXRpb246IG5vbmU7CiAgICB9CgogICAgLmhlYWRlci1saW5rIHsKICAgICAgdGV4dC1kZWNvcmF0aW9uOiBub25lOwogICAgICBmb250LXNpemU6IDE0cHg7CiAgICAgIGNvbG9yOiAjMWQ1NWZmOwogICAgICBmb250LXdlaWdodDogNTAwOwogICAgfQoKICAgIC5tYXJnaW4tdG9wIHsKICAgICAgbWFyZ2luLXRvcDogMTZweDsKICAgIH0KCiAgICAubG9nby1jb250YWluZXIgewogICAgICB3aWR0aDogMTAwJTsKICAgICAgbWFyZ2luLWJvdHRvbTogNTZweDsKICAgIH0KCiAgICAubG9nbyB7CiAgICAgIGRpc3BsYXk6IGJsb2NrOwogICAgfQoKICAgIC8qIEN1c3RvbSBzdHlsZXMgLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tICovCiAgICBociB7CiAgICAgIGJvcmRlci10b3A6IDFweCBzb2xpZCAjZDlkOWRlOwogICAgICBjb2xvcjogI2Q5ZDlkZTsKICAgICAgYmFja2dyb3VuZC1jb2xvcjogI2Q5ZDlkZTsKICAgICAgbWFyZ2luLXRvcDogMzJweDsKICAgICAgbWFyZ2luLWJvdHRvbTogNDBweDsKICAgIH0KCiAgICBoMSB7CiAgICAgIGZvbnQtZmFtaWx5OiAiRmF1c3RpbmEiLCBzZXJpZjsKICAgICAgZm9udC1zaXplOiAzMnB4OwogICAgICBmb250LXdlaWdodDogNjAwOwogICAgICBjb2xvcjogIzIzMjMyNjsKICAgICAgbWFyZ2luLWJvdHRvbTogMjJweDsKICAgIH0KCiAgICBwIHsKICAgICAgZm9udC1mYW1pbHk6ICJTb3VyY2UgU2FucyBQcm8iLCBzYW5zLXNlcmlmOwogICAgICBmb250LXNpemU6IDE4cHg7CiAgICAgIGxpbmUtaGVpZ2h0OiAxLjY7CiAgICAgIGNvbG9yOiAjMjMyMzI2OwogICAgfQoKICAgIC5idXR0b24gewogICAgICBmb250LWZhbWlseTogIlNvdXJjZSBTYW5zIFBybyIsIHNhbnMtc2VyaWY7CiAgICB9CgogICAgLmNvbnRlbnQtY2VsbCB7CiAgICAgIHBhZGRpbmc6IDQwcHggNDBweDsKICAgIH0KCiAgICAuYnV0dG9uIHsKICAgICAgYmFja2dyb3VuZC1jb2xvcjogI2ZmNWEyNjsKICAgICAgYm9yZGVyLXRvcDogMTBweCBzb2xpZCAjZmY1YTI2OwogICAgICBib3JkZXItcmlnaHQ6IDE4cHggc29saWQgI2ZmNWEyNjsKICAgICAgYm9yZGVyLWJvdHRvbTogMTBweCBzb2xpZCAjZmY1YTI2OwogICAgICBib3JkZXItbGVmdDogMThweCBzb2xpZCAjZmY1YTI2OwogICAgICBkaXNwbGF5OiBpbmxpbmUtYmxvY2s7CiAgICAgIGNvbG9yOiAjZmZmOwogICAgICB3aWR0aDogYXV0bzsKICAgICAgYm94LXNoYWRvdzogbm9uZTsKICAgICAgdGV4dC1kZWNvcmF0aW9uOiBub25lOwogICAgICBib3JkZXItcmFkaXVzOiA4cHg7CiAgICAgIC13ZWJraXQtdGV4dC1zaXplLWFkanVzdDogbm9uZTsKICAgICAgbXNvLWxpbmUtaGVpZ2h0LXJ1bGU6IGV4YWN0bHk7CiAgICAgIHRleHQtYWxpZ246IGNlbnRlcjsKICAgICAgZm9udC1zaXplOiAxNHB4OwogICAgICBmb250LXdlaWdodDogNjAwOwogICAgfQogIDwvc3R5bGU+CjwvaGVhZD4KPGJvZHk+Cjx0YWJsZSBjbGFzcz0iZW1haWwtd3JhcHBlciIgd2lkdGg9IjEwMCUiIGNlbGxwYWRkaW5nPSIwIiBjZWxsc3BhY2luZz0iMCI+CiAgPHRyPgogICAgPHRkIGFsaWduPSJjZW50ZXIiPgogICAgICA8dGFibGUKICAgICAgICAgICAgICBjbGFzcz0iZW1haWwtY29udGVudCIKICAgICAgICAgICAgICB3aWR0aD0iMTAwJSIKICAgICAgICAgICAgICBjZWxscGFkZGluZz0iMCIKICAgICAgICAgICAgICBjZWxsc3BhY2luZz0iMCIKICAgICAgPgogICAgICAgIDx0cj4KICAgICAgICAgIDx0ZCBjbGFzcz0iZW1haWwtbWFzdGhlYWQiPjwvdGQ+CiAgICAgICAgPC90cj4KICAgICAgICA8IS0tIEVtYWlsIEJvZHkgLS0+CiAgICAgICAgPHRyPgogICAgICAgICAgPHRkCiAgICAgICAgICAgICAgICAgIGNsYXNzPSJlbWFpbC1ib2R5IgogICAgICAgICAgICAgICAgICB3aWR0aD0iMTAwJSIKICAgICAgICAgICAgICAgICAgY2VsbHBhZGRpbmc9IjAiCiAgICAgICAgICAgICAgICAgIGNlbGxzcGFjaW5nPSIwIgogICAgICAgICAgPgogICAgICAgICAgICA8dGFibGUKICAgICAgICAgICAgICAgICAgICBjbGFzcz0iZW1haWwtYm9keV9pbm5lciIKICAgICAgICAgICAgICAgICAgICBhbGlnbj0iY2VudGVyIgogICAgICAgICAgICAgICAgICAgIHdpZHRoPSIxMDAlIgogICAgICAgICAgICAgICAgICAgIGJhY2tncm91bmQtY29sb3I9IiNlZGVmZjIiCiAgICAgICAgICAgICAgICAgICAgY2VsbHBhZGRpbmc9IjAiCiAgICAgICAgICAgICAgICAgICAgY2VsbHNwYWNpbmc9IjAiCiAgICAgICAgICAgID4KICAgICAgICAgICAgICA8IS0tIEJvZHkgY29udGVudCAtLT4KICAgICAgICAgICAgICA8dHI+PC90cj4KICAgICAgICAgICAgICA8dHI+CiAgICAgICAgICAgICAgICA8dGQgY2xhc3M9ImNvbnRlbnQtY2VsbCIgd2lkdGg9IjEwMCUiPgogICAgICAgICAgICAgICAgICA8cD4KICAgICAgICAgICAgICAgICAgICAgIHt7IGF1dGhvciB9fSBoZWVmdCBmZWVkYmFjayBnZWdldmVuIG9wOiB7eyBzdWJqZWN0IH19CiAgICAgICAgICAgICAgICAgIDwvcD4KICAgICAgICAgICAgICAgICAgPGJyLz4KICAgICAgICAgICAgICAgICAgPHA+CiAgICAgICAgICAgICAgICAgICAgICB7eyBkZXNjcmlwdGlvbiB8IG5sMmJyIH19CiAgICAgICAgICAgICAgICAgIDwvcD4KICAgICAgICAgICAgICAgICAgPGhyIC8+CiAgICAgICAgICAgICAgICAgIDxwPk1ldCB2cmllbmRlbGlqa2UgZ3JvZXQsPC9wPgogICAgICAgICAgICAgICAgICA8cD5LSVNTPC9wPgogICAgICAgICAgICAgICAgPC90ZD4KICAgICAgICAgICAgICA8L3RyPgogICAgICAgICAgICAgIDx0cj48L3RyPgogICAgICAgICAgICA8L3RhYmxlPgogICAgICAgICAgPC90ZD4KICAgICAgICA8L3RyPgogICAgICA8L3RhYmxlPgogICAgPC90ZD4KICA8L3RyPgo8L3RhYmxlPgo8L2JvZHk+CjwvaHRtbD4K",
                "variables" => [
                    "subject" => "name",
                    "author" => "author",
                    "topic" => "topic",
                    "description" => "description"
                ],
                "sender" => "kiss@commonground.nu ",
                "receiver" => "test@conduction.nl",
                "subject" => "KISS feedback: {{subject}}"
            ],
            'conditions' => [
                '==' => [
                    [
                        'var' => 'entity'
                    ],
                    'https://kissdevelopment.commonground.nu/kiss.review.schema.json'
                ]
            ],
            'listens' => [
                'commongateway.object.create',
            ],
        ],
    ];

    public function __construct(EntityManagerInterface $entityManager, ContainerInterface $container)
    {
        $this->entityManager = $entityManager;
        $this->container = $container;
    }

    /**
     * Set symfony style in order to output to the console
     *
     * @param SymfonyStyle $io
     * @return self
     */
    public function setStyle(SymfonyStyle $io):self
    {
        $this->io = $io;

        return $this;
    }

    public function install(){
        $this->checkDataConsistency();
    }

    public function update(){
        $this->checkDataConsistency();
    }

    public function uninstall(){
        // Do some cleanup
    }

    /**
     * This function creates default configuration for the action
     *
     * @param $actionHandler The actionHandler for witch the default configuration is set
     * @return array
     */
    public function addActionConfiguration($actionHandler): array
    {
        $defaultConfig = [];
        foreach ($actionHandler->getConfiguration()['properties'] as $key => $value) {

            switch ($value['type']) {
                case 'string':
                case 'array':
                    if (isset($value['example'])) {
                        $defaultConfig[$key] = $value['example'];
                    }
                    break;
                case 'object':
                    break;
                case 'uuid':
                    if (isset($value['$ref'])) {
                        try {
                            $entity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => $value['$ref']]);
                        } catch (Exception $exception) {
                            throw new Exception("No entity found with reference {$value['$ref']}");
                        }
                        $defaultConfig[$key] = $entity->getId()->toString();
                    }
                    break;
                default:
                    // throw error
            }
        }
        return $defaultConfig;
    }

    /**
     * Decides wether or not an array is associative.
     *
     * @param array $array The array to check
     *
     * @return bool Wether or not the array is associative
     */
    private function isAssociative(array $array)
    {
        if ([] === $array) {
            return false;
        }

        return array_keys($array) !== range(0, count($array) - 1);
    }

    /**
     * @param array $defaultConfig
     * @param array $overrides
     * @return array
     * @throws Exception
     */
    public function overrideConfig(array $defaultConfig, array $overrides): array
    {
        foreach($overrides as $key => $override) {
            if(is_array($override) && $this->isAssociative($override)) {
                $defaultConfig[$key] = $this->overrideConfig(isset($defaultConfig[$key]) ? $defaultConfig[$key] : [], $override);
            } elseif($key == 'entity') {
                $entity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => $override]);
                if(!$entity) {
                    throw new Exception("No entity found with reference {$override}");
                }
                $defaultConfig[$key] = $entity->getId()->toString();
            } elseif($key == 'source') {
                $source = $this->entityManager->getRepository('App:Gateway')->findOneBy(['name' => $override]);
                if(!$source) {
                    throw new Exception("No source found with name {$override}");
                }
                $defaultConfig[$key] = $source->getId()->toString();
            } else {
                $defaultConfig[$key] = $override;
            }
        }
        return $defaultConfig;
    }

    public function replaceRefById(array $conditions): array
    {
        if($conditions['=='][0]['var'] == 'entity') {
            try {
                $conditions['=='][1] = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => $conditions['=='][1]]);
            } catch (Exception $exception) {
                throw new Exception("No entity found with reference {$conditions['=='][1]}");
            }
        }
        return $conditions;
    }
    
    /**
     * This function creates actions for all the actionHandlers in Kiss
     *
     * @return void
     * @throws Exception
     */
    public function createActions(): void
    {
        $actionHandlers = $this::ACTION_HANDLERS;
        (isset($this->io)?$this->io->writeln(['','<info>Looking for actions</info>']):'');

        foreach ($actionHandlers as $handler) {
            $actionHandler = $this->container->get($handler['actionHandler']);

            if (array_key_exists('name', $handler)) {
                if ($this->entityManager->getRepository('App:Action')->findOneBy(['name'=> $handler['name']])) {
                    (isset($this->io)?$this->io->writeln(['Action found with name '.$handler['name']]):'');
                    continue;
                }
            } elseif ($this->entityManager->getRepository('App:Action')->findOneBy(['class'=> get_class($actionHandler)])) {
                (isset($this->io)?$this->io->writeln(['Action found for '.$handler['actionHandler']]):'');
                continue;
            }

            if (!$actionHandler->getConfiguration()) {
                continue;
            }

            $defaultConfig = $this->addActionConfiguration($actionHandler);
            isset($handler['config']) && $defaultConfig = $this->overrideConfig($defaultConfig, $handler['config']);

            $action = new Action($actionHandler);
            array_key_exists('name', $handler) ? $action->setName($handler['name']) : '';
            $action->setListens($handler['listens'] ?? ['kiss.default.listens']);
            $action->setConfiguration($defaultConfig);
            $action->setConditions($handler['conditions'] ?? ['==' => [1, 1]]);

            $this->entityManager->persist($action);
            (isset($this->io)?$this->io->writeln(['Created Action '.$action->getName().' with Handler: '.$handler['actionHandler']]):'');
        }
    }
    
    /**
     * Creates the kiss Endpoints
     *
     * @param $objectsThatShouldHaveEndpoints
     * @return array
     */
    private function createEndpoints($objectsThatShouldHaveEndpoints): array
    {
        $endpointRepository = $this->entityManager->getRepository('App:Endpoint');
        $endpoints = [];
        foreach($objectsThatShouldHaveEndpoints as $objectThatShouldHaveEndpoint) {
            $entity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => $objectThatShouldHaveEndpoint['reference']]);
            if ($entity instanceof Entity && !$endpointRepository->findOneBy(['name' => $entity->getName()])) {
                $endpoint = new Endpoint($entity, null, $objectThatShouldHaveEndpoint);

                $this->entityManager->persist($endpoint);
                $this->entityManager->flush();
                $endpoints[] = $endpoint;
            }
        }
        (isset($this->io) ? $this->io->writeln(count($endpoints).' Endpoints Created'): '');

        return $endpoints;
    }
    
    /**
     * Creates the kiss Sources
     *
     * @param $sourcesThatShouldExist
     * @return array
     */
    private function createSources($sourcesThatShouldExist): array
    {
        $sourceRepository = $this->entityManager->getRepository('App:Gateway');
        $sources = [];

        foreach($sourcesThatShouldExist as $sourceThatShouldExist) {
            if (!$sourceRepository->findOneBy(['name' => $sourceThatShouldExist['name']])) {
                $source = new Source($sourceThatShouldExist);
                $source->setApikey(array_key_exists('apikey', $sourceThatShouldExist) ? $sourceThatShouldExist['apikey'] : '');

                $this->entityManager->persist($source);
                $this->entityManager->flush();
                $sources[] = $source;
            }
        }

        (isset($this->io) ? $this->io->writeln(count($sources).' Sources Created'): '');

        return $sources;
    }
    
    /**
     * Creates the kiss proxy endpoints for some of the created sources
     *
     * @param $proxyEndpoints
     * @return array
     */
    private function createProxyEndpoints($proxyEndpoints): array
    {
        $endpointRepository = $this->entityManager->getRepository('App:Endpoint');
        $endpoints = [];
        foreach($proxyEndpoints as $proxyEndpoint) {
            $source = $this->entityManager->getRepository('App:Gateway')->findOneBy(['name' => $proxyEndpoint['proxy']]);
            if ($source instanceof Source && !$endpointRepository->findOneBy(['name' => $proxyEndpoint['name'], 'proxy' => $source])) {
                $endpoint = new Endpoint(null, $source, $proxyEndpoint);

                $this->entityManager->persist($endpoint);
                $this->entityManager->flush();
                $endpoints[] = $endpoint;
            }
        }
        (isset($this->io) ? $this->io->writeln(count($endpoints).' Proxy Endpoints Created'): '');

        return $endpoints;
    }
    
    /**
     * Update all existing zgw endpoints and remove any prefixes
     *
     * @return void
     */
    private function cleanZgwEndpointPrefixes()
    {
        (isset($this->io)?$this->io->writeln(['','<info>Removing ZGW endpoint prefixes</info>']):'');
    
        $collections = $this->entityManager->getRepository('App:CollectionEntity')->findBy(['plugin' => 'ZgwBundle']);
        (isset($this->io)?$this->io->writeln('Found '.count($collections).' Collections'):'');
        
        foreach ($collections as $collection) {
            (isset($this->io)?$this->io->writeln("Removing prefix {$collection->getPrefix()}") : '');
            $this->removeEntityEndpointsPrefix($collection);
            (isset($this->io)?$this->io->newLine() : '');
        }
    }
    
    /**
     * Remove prefixes from zgw endpoints, loop through all entities of a collection and remove prefix from all connected endpoints
     *
     * @return void
     */
    private function removeEntityEndpointsPrefix(CollectionEntity $collection)
    {
        foreach ($collection->getEntities() as $entity) {
            if (!$endpoints = $this->entityManager->getRepository('App:Endpoint')->findBy(['entity' => $entity])) {
                (isset($this->io)?$this->io->writeln(["No endpoint found for entity: {$entity->getName()}"]):'');
                continue;
            }
            (isset($this->io)?$this->io->writeln("Found ".count($endpoints)." endpoint(s) for : {$entity->getName()}, start removing prefix") : '');
            foreach ($endpoints as $endpoint) {
                // Update pathRegex, removing prefix
                $endpoint->setPathRegex(str_replace($collection->getPrefix().'/', '', $endpoint->getPathRegex()));
            
                // Count how many items we need to remove from the path array, by exploding prefix on '/'
                $explodedPrefix = explode('/', $collection->getPrefix());
                $arrayItemsCount = count($explodedPrefix);
                // Update path for this endpoint, removing the prefix
                $endpoint->setPath(array_slice($endpoint->getPath(), $arrayItemsCount));
            
                $this->entityManager->persist($endpoint);
                (isset($this->io)?$this->io->writeln("Updated endpoint {$endpoint->getName()}, prefix removed") : '');
            }
        }
    }

    private function addSchemasToCollection(CollectionEntity $collection, string $schemaPrefix): CollectionEntity
    {
        $entities = $this->entityManager->getRepository('App:Entity')->findByReferencePrefix($schemaPrefix);
        foreach($entities as $entity) {
            $entity->addCollection($collection);
        }
        return $collection;
    }

    private function createCollections(): array
    {
        $collectionConfigs = [
            // todo: disabled prefixes for now, because we need to change the kiss front-end first
//            ['name' => 'Kiss',  'prefix' => 'kiss', 'schemaPrefix' => 'https://kissdevelopment.commonground.nu/kiss'],
            ['name' => 'Kiss',  'prefix' => null, 'schemaPrefix' => 'https://kissdevelopment.commonground.nu/kiss'],
        ];
        $collections = [];
        foreach($collectionConfigs as $collectionConfig) {
            $collectionsFromEntityManager = $this->entityManager->getRepository('App:CollectionEntity')->findBy(['name' => $collectionConfig['name']]);
            if(count($collectionsFromEntityManager) == 0){
                $collection = new CollectionEntity($collectionConfig['name'], $collectionConfig['prefix'], 'KissBundle');
            } else {
                $collection = $collectionsFromEntityManager[0];
            }
            $collection = $this->addSchemasToCollection($collection, $collectionConfig['schemaPrefix']);
            $this->entityManager->persist($collection);
            $this->entityManager->flush();
            $collections[$collectionConfig['name']] = $collection;
        }
        (isset($this->io) ? $this->io->writeln(count($collections).' Collections Created'): '');
        return $collections;
    }

    public function createDashboardCards($objectsThatShouldHaveCards)
    {
        foreach ($objectsThatShouldHaveCards as $object) {
            (isset($this->io) ? $this->io->writeln('Looking for a dashboard card for: ' . $object) : '');
            $entity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => $object]);
            if (
                !$dashboardCard = $this->entityManager->getRepository('App:DashboardCard')->findOneBy(['entityId' => $entity->getId()])
            ) {
                $dashboardCard = new DashboardCard();
                $dashboardCard->setType('schema');
                $dashboardCard->setEntity('App:Entity');
                $dashboardCard->setObject('App:Entity');
                $dashboardCard->setName($entity->getName());
                $dashboardCard->setDescription($entity->getDescription());
                $dashboardCard->setEntityId($entity->getId());
                $dashboardCard->setOrdering(1);
                $this->entityManager->persist($dashboardCard);
                (isset($this->io) ? $this->io->writeln('Dashboard card created') : '');
                continue;
            }
            (isset($this->io) ? $this->io->writeln('Dashboard card found') : '');
        }
    }

    public function checkDataConsistency(){

        // Lets create some genneric dashboard cards
        $this->createDashboardCards($this::OBJECTS_THAT_SHOULD_HAVE_CARDS);

        $this->createCollections();

        // Let create some endpoints
        $this->createEndpoints($this::SCHEMAS_THAT_SHOULD_HAVE_ENDPOINTS);

        // Create Sources & proxy endpoints
        // $this->createSources($this::SOURCES); // OLD
        $this->createProxyEndpoints($this::PROXY_ENDPOINTS);
        
        // Clean up prefixes from all ZGW endpoints
        $this->cleanZgwEndpointPrefixes();

        // Lets see if there is a generic search endpoint

        // aanmaken van actions met een cronjob
        $this->createActions();

        (isset($this->io)?$this->io->writeln(['','<info>Looking for cronjobs</info>']):'');
        // We only need 1 cronjob so lets set that
        if(!$cronjob = $this->entityManager->getRepository('App:Cronjob')->findOneBy(['name'=>'Kiss']))
        {
            $cronjob = new Cronjob();
            $cronjob->setName('Kiss');
            $cronjob->setDescription("This cronjob fires all the kiss actions every minute");
            $cronjob->setThrows(['kiss.default.listens']);
            $cronjob->setCrontab('*/1 * * * *');

            $this->entityManager->persist($cronjob);

            (isset($this->io)?$this->io->writeln(['','Created a cronjob for Kiss']):'');
        }
        else {
            (isset($this->io)?$this->io->writeln(['','There is already a cronjob for Kiss']):'');
        }

        $this->entityManager->flush();
    }
}
