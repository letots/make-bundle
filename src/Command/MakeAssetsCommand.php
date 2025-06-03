<?php

namespace LeTots\MakeBundle\Command;

use JsonException;
use LeTots\MakeBundle\Service\CommandService;
use Symfony\Bundle\MakerBundle\ConsoleStyle;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;

#[AsCommand(name: 'letots:make:assets', description: 'Génère la configuration de base pour les assets avec Tailwind et SASS.')]
class MakeAssetsCommand extends Command
{
	public function __construct(
		private readonly CommandService $commandService,
	)
	{
		parent::__construct();
	}
	
	/**
	 * @throws JsonException
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int
    {
		$io = new ConsoleStyle($input, $output);
		$io->title('Configuration des assets');
		
		// Commit pour pouvoir revenir en arrière si besoin
		if($this->commandService->askYN($io, 'Souhaitez-vous effectuer un commit pour pouvoir revert si les changements ne conviennent pas ? (n/y)') === 'y') {
			$this->commandService->runSymfonyCommand($output, ['letots:git:commit']);
		}
		
		// Check si asset mapper est déjà configuré
		
		// Check si webpack encore est déjà configuré, si oui, déplacer la config et le package.json dans le système asset mapper
		
		// Mise en place de SASS et Tailwind bundles
		$this->commandService->addInComposerJsonIfNecessary($output, $io, ['symfonycasts/sass-bundle'], ['0.7.0']);
		$this->commandService->addInComposerJsonIfNecessary($output, $io, ['symfonycasts/tailwind-bundle']);
		
		$fileSystem = new Filesystem();
		$io->text('Création des fichiers :');
		
		// Création de la configuration asset mapper
		$destination = $this->commandService->getProjectDir() . '/config/packages/asset_mapper.yaml';
		$source = $this->commandService->getBundleDir() . '/templates/config/asset_mapper.yaml';
		$fileSystem->copy($source, $destination, true);
		
		$io->text('config/packages/asset_mapper.yaml');
		
		// Création de la configuration tailwind
		$destination = $this->commandService->getProjectDir() . '/config/packages/symfonycasts_tailwind.yaml';
		$source = $this->commandService->getBundleDir() . '/templates/config/symfonycasts_tailwind.yaml';
		$fileSystem->copy($source, $destination, true);
		
		$io->text('config/packages/symfonycasts_tailwind.yaml');
		
		// Création du fichier app.scss
		$destination = $this->commandService->getProjectDir() . '/assets/styles/app.scss';
		$source = $this->commandService->getBundleDir() . '/templates/styles/app.scss';
		$fileSystem->copy($source, $destination, true);
		$io->text('assets/styles/app.scss');
		
		// Création du fichier tailwind.css
		$destination = $this->commandService->getProjectDir() . '/assets/styles/tailwind.css';
		$source = $this->commandService->getBundleDir() . '/templates/styles/tailwind.css';
		$fileSystem->copy($source, $destination, true);
		
		$io->text('assets/styles/tailwind.css');
		
		// Création du fichier base.html.twig
		$destination = $this->commandService->getProjectDir() . '/templates/base.html.twig';
		$source = $this->commandService->getBundleDir() . '/templates/base.html.twig';
		$fileSystem->copy($source, $destination, true);
		
		$io->text('templates/base.html.twig');
		
		// Création du .symfony.local.yaml pour ajouter les watcher
		$destination = $this->commandService->getProjectDir() . '/.symfony.local.yaml';
		$source = $this->commandService->getBundleDir() . '/templates/config/.symfony.local.yaml';
		$fileSystem->copy($source, $destination, true);
		
		$io->text('.symfony.local.yaml');
		
		$this->commandService->runSymfonyCommand($output, ['tailwind:init']);
		$this->commandService->runSymfonyCommand($output, ['tailwind:build']);
		
		$io->success('Assets configurés, vous pouvez dès à présent lancer la commande <info>symfony serve</info> pour démarrer le serveur Symfony, Tailwind et SASS compilent automatiquement en mode dev. Lors du passage en production, pensez à lancer les commandes <info>php bin/console sass:build</info> et <info>php bin/console tailwind:build --minify</info> pour compiler les assets.');
		
		return Command::SUCCESS;
    }
}
