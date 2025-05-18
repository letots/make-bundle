<?php

namespace LeTots\MakeBundle\Command\MakeAuth;

use LeTots\MakeBundle\Service\CommandService;
use Symfony\Bundle\MakerBundle\ConsoleStyle;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'letots:make:user', description: 'Amélioration de la commande Symfony make:user')]
class MakeUserCommand extends Command
{
	public function __construct(
		private readonly CommandService $commandService,
	)
	{
		parent::__construct();
	}
	
	/**
	 * @throws \JsonException
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new ConsoleStyle($input, $output);
		
		$this->commandService->addInComposerJsonIfNecessary($output, $io, ['symfony/orm-pack', 'symfony/security-bundle']);
		
		$io->text("Création de l'entité utilisateur");
		
		$entityClass = 'App\\Entity\\User';
		
		if (!class_exists($entityClass)) {
			$this->commandService->runSymfonyCommandWithDefaults($output, ['make:user'], "\n\n\n\n");
			$this->commandService->runSymfonyCommand($output, ['d:s:u', '--force']);
			$io->text("L'entité App\\Entity\\User a été créée.");
		} else {
			$io->text("L'entité App\\Entity\\User existe déjà, pas de changements effectués.");
		}
		
		return Command::SUCCESS;
	}
}
