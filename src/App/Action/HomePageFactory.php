<?php

namespace App\Action;

use GuzzleHttp\Client;
use Interop\Container\ContainerInterface;
use Zend\Db\TableGateway\TableGateway;
use Zend\Expressive\Router\RouterInterface;
use Zend\Expressive\Template\TemplateRendererInterface;

class HomePageFactory
{
    public function __invoke(ContainerInterface $container)
    {
        $client = new Client($container->get('config')['github_client']);

        $adapter = $container->get('Zend\Db\Adapter\Adapter');
        $table = new TableGateway('pull_requests', $adapter);

        return new HomePageAction($client, $table);
    }
}
