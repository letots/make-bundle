<?php

namespace LeTots\MakeBundle;

use LeTots\MakeBundle\DependencyInjection\MakeExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class MakeBundle extends Bundle
{
	public function build(ContainerBuilder $container): void
	{
		parent::build($container);
	}
	
	public function getContainerExtension(): ?ExtensionInterface
	{
		return new MakeExtension();
	}
}
