<?php

namespace LeTots\MakeBundle\Command\User;

use Doctrine\ORM\EntityManagerInterface;
use Exception;
use LeTots\MakeBundle\Service\CommandService;
use Symfony\Bundle\MakerBundle\ConsoleStyle;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'letots:user:create', description: 'Création d\'un utilisateur admin avec mot de passe root')]
class UserCreateCommand extends Command
{
	public function __construct(
		private readonly UserPasswordHasherInterface $passwordHasher,
		private readonly EntityManagerInterface $entityManager,
		private readonly CommandService $commandService,
	)
	{
		parent::__construct();
	}
	
	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new ConsoleStyle($input, $output);
		$io->text('Création d\'un utilisateur en base de données');
		
		$entityClass = 'App\\Entity\\User';
		
		if (!class_exists($entityClass)) {
			$io->error('L\'entité utilisateur n\'existe pas, veuillez d\'abord exécuter la commande letots:make:user');
			return Command::FAILURE;
		}
		
		$userRepository = $this->entityManager->getRepository($entityClass);
		$userExists = $userRepository->findOneBy(['email' => 'admin@admin.com']);
		if ($userExists) {
			$keepExistingUser = $this->getKeepExistingUser($io);
			if (!$keepExistingUser) {
				$this->entityManager->remove($userExists);
				$this->entityManager->flush();
				$io->text('Utilisateur admin@akyos.com supprimé.');
			} else {
				$changeExistingUserPassword = $this->getChangeExistingUserPassword($io);
				if ($changeExistingUserPassword) {
					$userExists->setPassword($this->passwordHasher->hashPassword($userExists, 'root'));
					$this->entityManager->flush();
					$io->text('Utilisateur admin@akyos.com mis à jour.');
				} else {
					$io->text('Aucun changement effectué.');
					return Command::SUCCESS;
				}
			}
		}
		
		if (!$userExists || !$keepExistingUser) {
			try {
				$user = (new $entityClass())
					->setEmail('admin@admin.com')
					->setRoles(['ROLE_ADMIN']);
				$user->setPassword($this->passwordHasher->hashPassword($user, 'root'));
				$this->entityManager->persist($user);
				$this->entityManager->flush();
				$io->text('Utilisateur admin@akyos.com créé.');
			} catch (Exception $e) {
				$io->error('Erreur lors de la création de l\'utilisateur : ' . $e->getMessage());
				return Command::FAILURE;
			}
		}
		
		$io->text([
			'Mot de passe : root',
			'Vous pouvez changer le mot de passe de l\'utilisateur admin avec la commande suivante : symfony console letots:user:change-password',
		]);
		
		return Command::SUCCESS;
	}
	
	private function getKeepExistingUser(ConsoleStyle $io): bool
	{
		return $this->commandService->askYN($io, 'L\'utilisateur admin@akyos.com existe déjà, souhaitez vous le conserver ? Si non, il sera supprimé et remplacé par un nouveau compte. (n/y)') === 'y';
	}
	
	private function getChangeExistingUserPassword(ConsoleStyle $io): bool
	{
		return $this->commandService->askYN($io, 'Souhaitez vous écraser le mot de passe de l\'utilisateur par \'root\' ? (n/y)', 'n') === 'y';
	}
}