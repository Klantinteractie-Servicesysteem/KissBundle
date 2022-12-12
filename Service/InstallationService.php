<?php

// src/Service/LarpingService.php
namespace Kiss\KissBundle\Service;

use App\Entity\Action;
use App\Entity\DashboardCard;
use App\Entity\Cronjob;
use App\Entity\Endpoint;
use CommonGateway\CoreBundle\Installer\InstallerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class InstallationService implements InstallerInterface
{
    private EntityManagerInterface $entityManager;
    private ContainerInterface $container;
    private SymfonyStyle $io;

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
     * The actionHandlers in Kiss
     *
     * @return array
     */
    public function actionHandlers(): array
    {
        return [
            'Kiss\KissBundle\ActionHandler\HandelsRegisterSearchHandler'
        ];
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
    public function addActions(): void
    {
        $actionHandlers = $this->actionHandlers();
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

    public function checkDataConsistency(){

        // Lets create some genneric dashboard cards
        $objectsThatShouldHaveCards = [
            'https://kissdevelopment.commonground.nu/kiss.openpubSkill.schema.json',
            'https://kissdevelopment.commonground.nu/kiss.openpubType.schema.json'
        ];

        foreach($objectsThatShouldHaveCards as $object){
            (isset($this->io)?$this->io->writeln('Looking for a dashboard card for: '.$object):'');
            $entity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference'=>$object]);
            if(
                !$dashboardCard = $this->entityManager->getRepository('App:DashboardCard')->findOneBy(['entityId'=>$entity->getId()])
            ){
                $dashboardCard = new DashboardCard(
                    $entity->getName(),
                    $entity->getDescription(),
                    'schema',
                    'App:Entity',
                    'App:Entity',
                    $entity->getId(),
                    1
                );
                $this->entityManager->persist($dashboardCard);

                (isset($this->io) ?$this->io->writeln('Dashboard card created: ' . $dashboardCard->getName()):'');
                continue;
            }
            (isset($this->io)?$this->io->writeln('Dashboard card found'):'');
        }

        (isset($this->io)?$this->io->writeln(''):'');
        // Let create some endpoints
        $objectsThatShouldHaveEndpoints = [
            'https://kissdevelopment.commonground.nu/kiss.openpubSkill.schema.json',
            'https://kissdevelopment.commonground.nu/kiss.openpubType.schema.json',
            'https://kissdevelopment.commonground.nu/afdelingsnaam.schema.json',
            'https://kissdevelopment.commonground.nu/link.schema.json',
            'https://kissdevelopment.commonground.nu/medewerker.schema.json',
            'https://kissdevelopment.commonground.nu/medewerkerAvailabilities.schema.json',
            'https://kissdevelopment.commonground.nu/medewerkerAvailability.schema.json',
            'https://kissdevelopment.commonground.nu/review.schema.json',
            'https://kissdevelopment.commonground.nu/sdgLocatie.schema.json',
            'https://kissdevelopment.commonground.nu/sdgProduct.schema.json',
            'https://kissdevelopment.commonground.nu/sdgVertaling.schema.json'
        ];

        foreach($objectsThatShouldHaveEndpoints as $object){
            (isset($this->io)?$this->io->writeln('Looking for a endpoint for: '.$object):'');
            $entity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference'=>$object]);

            if (!$entity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference'=>$object])) {
                continue;
            }

            if(
                count($entity->getEndpoints()) == 0
            ){
                $endpoint = New Endpoint($entity);
                $this->entityManager->persist($endpoint);
                (isset($this->io)?$this->io->writeln('Endpoint created'):'');
                continue;
            }
            (isset($this->io)?$this->io->writeln('Endpoint found'):'');
        }

        // Lets see if there is a generic search endpoint

        // aanmaken van actions met een cronjob
        $this->addActions();

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
