<?php

// src/Service/LarpingService.php
namespace Kiss\KissBundle\Service;

use App\Entity\Action;
use App\Entity\CollectionEntity;
use App\Entity\DashboardCard;
use App\Entity\Cronjob;
use App\Entity\Endpoint;
use App\Entity\Entity;
use CommonGateway\CoreBundle\Installer\InstallerInterface;
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
        'https://kissdevelopment.commonground.nu/kiss.openpubType.schema.json'
    ];
    //

    public const SCHEMAS_THAT_SHOULD_HAVE_ENDPOINTS = [
        ['reference' => 'https://kissdevelopment.commonground.nu/kiss.openpubSkill.schema.json',                 'path' => '/ref/openpub_skill',                    'methods' => []],
        ['reference' => 'https://kissdevelopment.commonground.nu/kiss.openpubType.schema.json',                 'path' => '/ref/openpub_type',                      'methods' => []],
        ['reference' => 'https://kissdevelopment.commonground.nu/kiss.afdelingsnaam.schema.json',                 'path' => '/ref/afdelingsnamen',                    'methods' => []],
        ['reference' => 'https://kissdevelopment.commonground.nu/kiss.link.schema.json',                 'path' => '/kiss/links',                    'methods' => []],
        ['reference' => 'https://kissdevelopment.commonground.nu/kiss.medewerker.schema.json',                 'path' => '/medewerkers',                    'methods' => []],
        ['reference' => 'https://kissdevelopment.commonground.nu/kiss.medewerkerAvailabilities.schema.json',                 'path' => '/mederwerkerAvailabilities',                    'methods' => []],
        ['reference' => 'https://kissdevelopment.commonground.nu/kiss.review.schema.json',                 'path' => '/reviews',                    'methods' => []],
        ['reference' => 'https://kissdevelopment.commonground.nu/kiss.sdgProduct.schema.json',                 'path' => '/sdg/kennisartikelen',                    'methods' => []],
    ];

    public const ACTION_HANDLERS = [
        'Kiss\KissBundle\ActionHandler\HandelsRegisterSearchHandler'
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
                    if (in_array('example', $value)) {
                        $defaultConfig[$key] = $value['example'];
                    }
                    break;
                case 'object':
                    break;
                case 'uuid':
                    if (in_array('$ref', $value) &&
                        $entity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => $value['$ref']])) {
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
     * This function creates actions for all the actionHandlers in Kiss
     *
     * @return void
     */
    public function createActions(): void
    {
        $actionHandlers = $this::ACTION_HANDLERS;
        (isset($this->io)?$this->io->writeln(['','<info>Looking for actions</info>']):'');

        foreach ($actionHandlers as $handler) {
            $actionHandler = $this->container->get($handler);

            if ($this->entityManager->getRepository('App:Action')->findOneBy(['class'=> get_class($actionHandler)])) {
                (isset($this->io)?$this->io->writeln(['Action found for '.$handler]):'');
                continue;
            }

            if (!$schema = $actionHandler->getConfiguration()) {
                continue;
            }

            $defaultConfig = $this->addActionConfiguration($actionHandler);

            $action = new Action($actionHandler);
            $action->setListens(['kiss.default.listens']);
            $action->setConfiguration($defaultConfig);

            $this->entityManager->persist($action);
            (isset($this->io)?$this->io->writeln(['Action created for '.$handler]):'');
        }
    }

    private function createEndpoints($objectsThatShouldHaveEndpoints): array
    {
        $endpointRepository = $this->entityManager->getRepository('App:Endpoint');
        $endpoints = [];
        foreach($objectsThatShouldHaveEndpoints as $objectThatShouldHaveEndpoint) {
            $entity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => $objectThatShouldHaveEndpoint['reference']]);
            if ($entity instanceof Entity && !$endpointRepository->findOneBy(['name' => $entity->getName()])) {
                $endpoint = new Endpoint($entity, $objectThatShouldHaveEndpoint['path'], $objectThatShouldHaveEndpoint['methods']);

                $this->entityManager->persist($endpoint);
                $this->entityManager->flush();
                $endpoints[] = $endpoint;
            }
        }
        (isset($this->io) ? $this->io->writeln(count($endpoints).' Endpoints Created'): '');

        return $endpoints;
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
            ['name' => 'Kiss',  'prefix' => 'kiss', 'schemaPrefix' => 'https://kissdevelopment.commonground.nu/kiss'],
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


        // Lets see if there is a generic search endpoint

        // aanmaken van actions met een cronjob
        $this->createActions();

        (isset($this->io)?$this->io->writeln(['','<info>Looking for cronjobs</info>']):'');
        // We only need 1 cronjob so lets set that
        if(!$cronjob = $this->entityManager->getRepository('App:Cronjob')->findOneBy(['name'=>'Kiss']))
        {
            $cronjob = new Cronjob();
            $cronjob->setName('Kiss');
            $cronjob->setDescription("This cronjob fires all the kiss actions every 5 minutes");
            $cronjob->setThrows(['kiss.default.listens']);

            $this->entityManager->persist($cronjob);

            (isset($this->io)?$this->io->writeln(['','Created a cronjob for Kiss']):'');
        }
        else {
            (isset($this->io)?$this->io->writeln(['','There is already a cronjob for Kiss']):'');
        }

        $this->entityManager->flush();
    }
}
