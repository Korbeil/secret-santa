<?php

/*
 * This file is part of the Secret Santa project.
 *
 * (c) JoliCode <coucou@jolicode.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace JoliCode\SecretSanta\Slack;

use JoliCode\Slack\Api\Client;
use JoliCode\Slack\ClientFactory as DefaultClientFactory;

class ClientFactory
{
    /** @var array<string, Client> */
    private $clientsByToken = [];

    public function getClientForToken(string $token): Client
    {
        if (!isset($this->clientsByToken[$token])) {
            $this->clientsByToken[$token] = DefaultClientFactory::create($token);
        }

        return $this->clientsByToken[$token];
    }
}
