<?php

namespace Kiss\KissBundle\Service;

use App\Entity\CollectionEntity;
use CommonGateway\CoreBundle\Installer\InstallerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Doctrine\ORM\EntityManagerInterface;

class InstallationService implements InstallerInterface
{
    private EntityManagerInterface $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    public function install(){
        $this->checkDataConsistency();
    }

    public function update() {
        $this->checkDataConsistency();
    }

    public function uninstall(){
        // Do some cleanup
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
                // todo: this works, we should go to php 8.0 later
                if (str_contains($endpoint->getPathRegex(), $collection->getPrefix()) === false) {
                    continue;
                }
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

    public function checkDataConsistency()
    {
        // Clean up prefixes from all ZGW endpoints
        $this->cleanZgwEndpointPrefixes();

        $this->entityManager->flush();
    }
}
