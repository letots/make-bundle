<?php

namespace LeTots\MakeBundle\Command;

use LeTots\MakeBundle\Service\CommandService;
use Symfony\Bundle\MakerBundle\ConsoleStyle;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'letots:make:auth', description: 'Génère l\'authentification de base avec un utilisateur et un système de login.')]
class MakeAuthCommand extends Command
{
	public function __construct(
		private readonly CommandService $commandService,
	)
	{
		parent::__construct();
	}
	
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
		$io = new ConsoleStyle($input, $output);
		$io->title('Configuration de l\'authentification Symfony');
		
		// Commit pour pouvoir revenir en arrière si besoin
		if($this->commandService->askYN($io, 'Souhaitez-vous effectuer un commit pour pouvoir revert si les changements ne conviennent pas ? (n/y)') === 'y') {
			$this->commandService->runSymfonyCommand($output, ['letots:git:commit']);
		}
		
		// Création de l'entité App/Entity/User
		$this->commandService->runSymfonyCommand($output, ['letots:make:user']);
		
		// Ajout du login listener
		if($this->commandService->askYN($io, 'Souhaitez-vous ajouter la date de dernière connexion et le nombre de connexions sur l\'entité utilisateur ? (n/y)') === 'y') {
			$this->commandService->runSymfonyCommand($output, ['letots:make:login-listener']);
		}
		
		// Création SecurityController, update security.yaml et template de login
		$this->commandService->runSymfonyCommand($output, ['letots:make:security-form-login']);
		
		// Mise en place mot de passe oublié
		
		// Mise en place double authentification
		
		// Mise en place first login workflow
		
		// Mise en place de l'authentification OAuth (google, github, etc.)
		
		// Création d'un utilisateur admin@admin.com
		$this->commandService->runSymfonyCommand($output, ['letots:user:create']);
		
		$this->commandService->runSymfonyCommand($output, ['cache:clear']);
		$this->commandService->runSymfonyCommand($output, ['cache:warmup']);
		
		$io->success('Authentification fonctionnelle, vous pouvez dès à présent vous connecter.');
		
		return Command::SUCCESS;
    }
}
