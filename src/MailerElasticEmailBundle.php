<?php

namespace bertoost\Mailer\ElasticEmail;

use bertoost\Mailer\ElasticEmail\DependencyInjection\MailerElasticEmailExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

class MailerElasticEmailBundle extends AbstractBundle
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new MailerElasticEmailExtension();
    }
}
