<?php

namespace LeTots\MakeBundle\Command\MakeAuth;

use Doctrine\ORM\EntityManagerInterface;
use Exception;
use LeTots\MakeBundle\Service\CommandService;
use PhpParser\Builder\Param;
use Symfony\Bundle\MakerBundle\ConsoleStyle;
use Symfony\Bundle\MakerBundle\Generator;
use Symfony\Bundle\MakerBundle\Util\ClassSourceManipulator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\InteractiveLoginEvent;

#[AsCommand(name: 'letots:make:login-listener', description: 'Ajout champs sur l\'entité User + LoginListener pour incrémenter loginCount et update lastLogin')]
class MakeLoginListenerCommand extends Command
{
	public function __construct(
		private readonly CommandService $commandService,
		private readonly Generator $generator,
	)
	{
		parent::__construct();
	}
	
	/**
	 * @throws Exception
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new ConsoleStyle($input, $output);
		
		$this->commandService->addInComposerJsonIfNecessary($output, $io, 'symfony/event-dispatcher');
		
		$io->text('Ajout champs sur l\'entité User + LoginListener pour incrémenter loginCount et update lastLogin');
		
		$this->addUserFields($io, $output);
		
		if($loginListenerName = $this->getLoginListenerName($io)) {
			$this->createLoginListener($io, $output, $loginListenerName);
		}
		
		return Command::SUCCESS;
	}
	
	private function addUserFields(ConsoleStyle $io, OutputInterface $output): void
	{
		if(!class_exists('App\\Entity\\User')) {
			$io->error('L\'entité utilisateur n\'existe pas, veuillez d\'abord exécuter la commande letots:make:user');
			return;
		}
		
		if(!property_exists('App\\Entity\\User', 'loginCount') && !property_exists('App\\Entity\\User', 'lastLogin')) {
			$this->commandService->runSymfonyCommandWithDefaults($output, ['make:entity'], "User\nlastLogin\ndatetime\nyes\nloginCount\ninteger\nyes\n\n");
			$message = 'Champs loginCount(integer nullable) et lastLogin(datetime nullable) ajoutés avec succès.';
		} elseif (!property_exists('App\\Entity\\User', 'lastLogin')) {
			$this->commandService->runSymfonyCommandWithDefaults($output, ['make:entity'], "User\nlastLogin\ndatetime\nyes\n\n");
			$message = 'Champs lastLogin(datetime nullable) ajouté avec succès.';
		} elseif (!property_exists('App\\Entity\\User', 'loginCount')) {
			$this->commandService->runSymfonyCommandWithDefaults($output, ['make:entity'], "User\nloginCount\ninteger\nyes\n\n");
			$message = 'Champs loginCount(integer nullable) ajouté avec succès.';
		} else {
			$io->text('Les champs lastLogin et loginCount existent déjà sur l\'entité User.');
			return;
		}
		
		$this->commandService->runSymfonyCommand($output, ['d:s:u', '--force']);
		$io->text($message);
	}
	
	private function getLoginListenerName(ConsoleStyle $io): ?string
	{
		$loginListenerName = 'LoginListener';
		
		while(class_exists('App\\EventListener\\Security\\'.$loginListenerName)) {
			$loginListenerName = $io->ask('La classe App\\EventListener\\Security\\'.$loginListenerName.' existe déjà. Choisissez un nom de classe pour le listener. (ex: LogLoginListener). Validez sans aucune valeur pour ne pas créer de listener.', null, function(?string $answer = null): ?string {
				if($answer && preg_match('/^[A-Z][a-zA-Z0-9]*Listener$/', $answer) !== 1) {
					throw new \RuntimeException("Le nom de la classe doit commencer par une majuscule, ne doit pas contenir d\'espaces ou de caractères spéciaux, et doit se terminer par 'Listener'.");
				}
				
				return $answer;
			});
		}
		
		return $loginListenerName;
	}
	
	/**
	 * @throws Exception
	 */
	private function createLoginListener(ConsoleStyle $io, OutputInterface $output, string $loginListenerName): void
	{
		$this->commandService->runSymfonyCommandWithDefaults($output, ['make:listener', 'Security\\'.$loginListenerName, InteractiveLoginEvent::class, '--quiet'], "\n");
		$listenerPath = $this->commandService->getProjectDir() . '/src/EventListener/Security/'.$loginListenerName.'.php';
		$manipulator = $this->commandService->createClassManipulator($listenerPath, $io, true);
		$this->addUseStatements($manipulator);
		$this->addConstructor($manipulator);
		$this->addOnInteractiveLoginEventMethod($manipulator);
		$this->generator->dumpFile($listenerPath, $manipulator->getSourceCode());
		$this->generator->writeChanges();
		
		$io->text('Le listener '.$loginListenerName.' a été créé avec succès.');
	}
	
	/**
	 * @throws Exception
	 */
	private function addUseStatements(ClassSourceManipulator $manipulator): void
	{
		$manipulator->addUseStatementIfNecessary(AsEventListener::class);
		$manipulator->addUseStatementIfNecessary(EntityManagerInterface::class);
		$manipulator->addUseStatementIfNecessary(InteractiveLoginEvent::class);
		$manipulator->addUseStatementIfNecessary('App\Entity\User');
	}
	
	private function addConstructor(ClassSourceManipulator $manipulator): void
	{
		$manipulator->addConstructor([
			(new Param('entityManager'))->setType('EntityManagerInterface')->makeReadonly()->makePrivate()->getNode(),
		], ''
		);
	}
	
	private function addOnInteractiveLoginEventMethod(ClassSourceManipulator $manipulator): void
	{
		$loginMethodBuilder = $manipulator->createMethodBuilder('onInteractiveLoginEvent', 'void', false);
		
		$loginMethodBuilder->addAttribute($manipulator->buildAttributeNode(AsEventListener::class, ['event' => InteractiveLoginEvent::class]));
		$loginMethodBuilder->addParam(
			(new Param('event'))->setType('InteractiveLoginEvent')
		);
		
		$manipulator->addMethodBody($loginMethodBuilder, <<<'CODE'
            <?php
            /** @var User $user */
            $user = $event->getAuthenticationToken()->getUser();
            $user->setLastLogin(new \DateTimeImmutable());
            $user->setLoginCount($user->getLoginCount() + 1);
            CODE
		);
		$loginMethodBuilder->addStmt($manipulator->createMethodLevelBlankLine());
		$manipulator->addMethodBody($loginMethodBuilder, <<<'CODE'
            <?php
            $this->entityManager->flush();
            CODE
		);
		$manipulator->addMethodBuilder($loginMethodBuilder);
	}
}
