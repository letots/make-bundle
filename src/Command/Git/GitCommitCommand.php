<?php

namespace LeTots\MakeBundle\Command\Git;

use LeTots\MakeBundle\Service\CommandService;
use Symfony\Bundle\MakerBundle\ConsoleStyle;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'letots:git:commit', description: 'Automatic git commit')]
class GitCommitCommand extends Command
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
		
		$gitCommandExists = $this->commandService->runCLICommand($output, ['git', '--version']);

		if (!$gitCommandExists || !str_contains($gitCommandExists, 'git version')) {
			$io->error('La commande git n\'est pas disponible, veuillez vérifier votre installation de git.');
			return Command::FAILURE;
		}
		
		$gitIsInitialized = is_dir('.git');
		
		if(!$gitIsInitialized) {
			$io->text('Le dépôt git n\'est pas initialisé.');
			$gitInit = $this->commandService->askYN($io, 'Souhaitez-vous initialiser le dépôt git ? (n/y)');
			
			if($gitInit === 'y') {
				$this->commandService->runCLICommand($output, ['git', 'init']);
				$io->text('Le dépôt git a été initialisé.');
			} else {
				$io->error('Le dépôt git n\'est pas initialisé, impossible de continuer.');
				return Command::FAILURE;
			}
		}
		
		$this->commandService->runCLICommand($output, ['git', 'add', '-A'], false, true);
		$this->commandService->runCLICommand($output, ['git', 'commit', '-m', 'Auto commit before make:auth'], false, true);
		
		return Command::SUCCESS;
	}
}