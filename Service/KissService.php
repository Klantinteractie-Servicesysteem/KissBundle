<?php

// src/Service/KissService.php

namespace CommonGateway\KissBundle\Service;

class KissService
{

    /*
     * Returns a welcoming string
     * 
     * @return array 
     */
    public function petStoreHandler(array $data, array $configuration): array
    {
        return ['response' => 'Hello. Your KissBundle works'];
    }
}
