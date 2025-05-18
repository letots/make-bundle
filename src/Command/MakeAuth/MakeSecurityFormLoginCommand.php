<?php

namespace LeTots\MakeBundle\Command\MakeAuth;

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
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Yaml\Yaml;

#[AsCommand(name: 'letots:make:security-form-login', description: 'Amélioration de la commande Symfony make:security:form-login')]
class MakeSecurityFormLoginCommand extends Command
{
	public function __construct(
		private readonly CommandService $commandService,
		private readonly Generator      $generator,
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
		
		$this->commandService->addInComposerJsonIfNecessary($output, $io, 'symfony/ux-toggle-password');
		$this->commandService->addInComposerJsonIfNecessary($output, $io, 'symfony/translation');
		
		$io->text('Ajout du SecurityController, configuration du security.yaml et création du template login');
		
		if (!file_exists($this->commandService->getProjectDir() . '/src/Controller/SecurityController.php') && !file_exists($this->commandService->getProjectDir() . '/src/Controller/Security/SecurityController.php') ) {
			$this->commandService->runSymfonyCommandWithDefaults($output, ['make:security:form-login'], "\n\n\n");
		}
		if(!file_exists($this->commandService->getProjectDir() . '/src/Security/AppAuthenticator.php')) {
			$this->commandService->runSymfonyCommandWithDefaults($output, ['make:security:custom'], "AppAuthenticator\n");
		}
		$this->overrideAppAuthenticator($io);
		$this->removeFormLoginPartFromSecurityConfig($io);
		$this->moveSecurityController($io);
		$loginTypeName = $this->getLoginTypeName($io);
		$this->createLoginType($io, $output, $loginTypeName);
		$this->changeSecurityController($io, $loginTypeName);
		$io->text('Création des fichiers :');
		$this->generateLayoutTemplate($io);
		$this->generateLoginTemplate($io);
		$this->generateTranslationFiles($io);
		$this->updateTranslationConfig($io);
		
		return Command::SUCCESS;
	}
	
	private function overrideAppAuthenticator(ConsoleStyle $io): void
	{
		$fileSystem = new Filesystem();
		$destination = $this->commandService->getProjectDir() . '/src/Security/AppAuthenticator.php';
		$source = $this->commandService->getBundleDir() . '/templates/class/AppAuthenticator.php.txt';
		$fileSystem->copy($source, $destination, true);
		
		$io->text('AppAuthenticator a été remplacé.');
	}
	
	private function removeFormLoginPartFromSecurityConfig(ConsoleStyle $io): void
	{
		$securityConfigPath = $this->commandService->getProjectDir() . '/config/packages/security.yaml';
		// get yaml content as array
		$fileContents = file_get_contents($securityConfigPath);
		$yaml = Yaml::parse($fileContents);

		if(isset($yaml['security']['firewalls']['main']['form_login'])) {
			unset($yaml['security']['firewalls']['main']['form_login']);
		}
		
		if(empty($yaml['security']['firewalls']['main']['custom_authenticators'])) {
			$yaml['security']['firewalls']['main']['custom_authenticators'] = [
				'App\\Security\\AppAuthenticator',
			];
		}
		
		$yaml = Yaml::dump($yaml, 2);
		file_put_contents($securityConfigPath, $yaml);
		
		$io->text('Le fichier security.yaml a été modifié.');
	}
	
	private function moveSecurityController(ConsoleStyle $io): void
	{
		$destination = $this->commandService->getProjectDir() . '/src/Controller/Security/SecurityController.php';
		$source = $this->commandService->getProjectDir() . '/src/Controller/SecurityController.php';
		if (file_exists($destination)) {
			$io->text('Le fichier SecurityController.php existe déjà dans le répertoire src/Controller/Security.');
			return;
		}
		
		$fileSystem = new Filesystem();
		if (!$fileSystem->exists($this->commandService->getProjectDir() . '/src/Controller/Security')) {
			$fileSystem->mkdir($this->commandService->getProjectDir() . '/src/Controller/Security');
		}
		
		$fileSystem->rename($source, $destination);
		$contents = file_get_contents($destination);
		$contents = str_replace('namespace App\Controller;', 'namespace App\Controller\Security;', $contents);
		file_put_contents($destination, $contents);
		
		$io->text('Le fichier SecurityController.php a été déplacé vers le répertoire src/Controller/Security.');
	}
	
	private function getLoginTypeName(ConsoleStyle $io): string
	{
		$loginTypeName = 'LoginType';
		
		while (class_exists('App\\Form\\Security\\' . $loginTypeName)) {
			$loginTypeName = $io->ask('La classe App\\Form\Security\\' . $loginTypeName . ' existe déjà. Choisissez un nom de classe pour le formulaire de connexion. (ex: LoginType)', null, function (string $answer): string {
				if ($answer === '' || preg_match('/^[A-Z][a-zA-Z0-9]*Type$/', $answer) !== 1) {
					throw new \RuntimeException("Le nom de la classe doit commencer par une majuscule, ne doit pas contenir d\'espaces ou de caractères spéciaux, et doit se terminer par 'Type'.");
				}
				
				return $answer;
			});
		}
		
		return $loginTypeName;
	}
	
	/**
	 * @throws Exception
	 */
	private function createLoginType(ConsoleStyle $io, OutputInterface $output, string $loginTypeName): void
	{
		$this->commandService->runSymfonyCommandWithDefaults($output, ['make:form', 'Security\\' . $loginTypeName, '--quiet'], "\n");
		
		$this->renameLoginType($io, $loginTypeName);
		
		$typePath = $this->commandService->getProjectDir() . '/src/Form/Security/' . $loginTypeName . '.php';
		$manipulator = $this->commandService->createClassManipulator($typePath, $io, true);
		$this->addUseStatementsForLoginType($manipulator);
		$this->addBuildFormMethodForLoginType($manipulator);
		$this->addConfigureOptionsMethodForLoginType($manipulator);
		$this->generator->dumpFile($typePath, $manipulator->getSourceCode());
		$this->generator->writeChanges();
		
		$io->text('Le formulaire ' . $loginTypeName . ' a été créé avec succès.');
	}
	
	private function renameLoginType(ConsoleStyle $io, string $loginTypeName): void
	{
		$oldPath = $this->commandService->getProjectDir() . '/src/Form/Security/' . $loginTypeName . 'Form.php';
		$newPath = $this->commandService->getProjectDir() . '/src/Form/Security/' . $loginTypeName . '.php';
		
		if (file_exists($oldPath)) {
			$fileSystem = new Filesystem();
			$fileSystem->rename($oldPath, $newPath);
			
			$contents = file_get_contents($newPath);
			$contents = str_replace('class LoginFormType', 'class ' . $loginTypeName, $contents);
			file_put_contents($newPath, $contents);
			
			$io->text('Le fichier ' . $oldPath . ' a été renommé en ' . $newPath);
		}
	}
	
	/**
	 * @throws Exception
	 */
	private function addUseStatementsForLoginType(ClassSourceManipulator $manipulator): void
	{
		$manipulator->addUseStatementIfNecessary(EmailType::class);
		$manipulator->addUseStatementIfNecessary(PasswordType::class);
	}
	
	private function addBuildFormMethodForLoginType(ClassSourceManipulator $manipulator): void
	{
		$buildFormMethodBuilder = $manipulator->createMethodBuilder('buildForm', 'void', false);
		
		$buildFormMethodBuilder->addParams([
			(new Param('builder'))->setType('FormBuilderInterface'),
			(new Param('options'))->setType('array'),
		]);
		
		$manipulator->addMethodBody($buildFormMethodBuilder, <<<'CODE'
            <?php
            $builder
            	->add('email', EmailType::class, [
            		'label' => 'form.email',
            		'attr' => [
            			'autofocus' => 'autofocus',
            		],
            	])
            	->add('password', PasswordType::class, [
            		'toggle' => true,
            		'label' => 'form.password',
            		'visible_label' => null,
            		'hidden_label' => null,
            	])
            ;
            CODE
		);
		$manipulator->addMethodBuilder($buildFormMethodBuilder);
	}
	
	private function addConfigureOptionsMethodForLoginType(ClassSourceManipulator $manipulator): void
	{
		$configureOptionsMethodBuilder = $manipulator->createMethodBuilder('configureOptions', 'void', false);
		
		$configureOptionsMethodBuilder->addParam(
			(new Param('resolver'))->setType('OptionsResolver')
		);
		
		$manipulator->addMethodBody($configureOptionsMethodBuilder, <<<'CODE'
            <?php
            $resolver->setDefaults([
            	'translation_domain' => 'security.login',
            ]);
            CODE
		);
		$manipulator->addMethodBuilder($configureOptionsMethodBuilder);
	}
	
	/**
	 * @throws Exception
	 */
	private function changeSecurityController(ConsoleStyle $io, string $loginTypeName): void
	{
		$controllerPath = $this->commandService->getProjectDir() . '/src/Controller/Security/SecurityController.php';
		$manipulator = $this->commandService->createClassManipulator($controllerPath, $io, true);
		$this->addUseStatementsForSecurityController($manipulator, $loginTypeName);
		$this->addLoginMethodForSecurityController($manipulator, $loginTypeName);
		$this->generator->dumpFile($controllerPath, $manipulator->getSourceCode());
		$this->generator->writeChanges();
		
		$io->text('Le SecurityController a été modifié avec succès.');
	}
	
	/**
	 * @throws Exception
	 */
	private function addUseStatementsForSecurityController(ClassSourceManipulator $manipulator, string $loginTypeName): void
	{
		$manipulator->addUseStatementIfNecessary('App\\Form\\Security\\'.$loginTypeName);
	}
	
	private function addLoginMethodForSecurityController(ClassSourceManipulator $manipulator, string $loginTypeName): void
	{
		$loginMethodBuilder = $manipulator->createMethodBuilder('login', 'Response', false);
		
		$loginMethodBuilder->addAttribute($manipulator->buildAttributeNode(Route::class, ['path' => '/login', 'name' => 'app_login']));
		
		$loginMethodBuilder->addParam((new Param('authenticationUtils'))->setType('AuthenticationUtils'));
		
		$manipulator->addMethodBody($loginMethodBuilder, <<<'CODE'
            <?php
            $error = $authenticationUtils->getLastAuthenticationError();
            $lastUsername = $authenticationUtils->getLastUsername();
            $form = $this->createForm(
            CODE. $loginTypeName . <<<'CODE'
            ::class);
            
            return $this->render('security/login.html.twig', [
            	'last_username' => $lastUsername,
            	'error' => $error,
            	'form' => $form,
            ]);
            CODE
		);
		$manipulator->addMethodBuilder($loginMethodBuilder);
	}
	
	private function generateLayoutTemplate(ConsoleStyle $io): void
	{
		$destination = $this->commandService->getProjectDir() . '/templates/security/layout.html.twig';
		$source = $this->commandService->getBundleDir() . '/templates/layout.html.twig';
		$fileSystem = new Filesystem();
		$fileSystem->copy($source, $destination, true);
		
		$io->text('templates/security/layout.html.twig');
	}
	
	private function generateLoginTemplate(ConsoleStyle $io): void
	{
		$destination = $this->commandService->getProjectDir() . '/templates/security/login.html.twig';
		$source = $this->commandService->getBundleDir() . '/templates/login.html.twig';
		$fileSystem = new Filesystem();
		$fileSystem->copy($source, $destination, true);
		
		$io->text('templates/security/login.html.twig');
	}
	
	private function generateTranslationFiles(ConsoleStyle $io): void
	{
		$fileSystem = new Filesystem();
		$destination = $this->commandService->getProjectDir() . '/translations/security/security.login.fr.yaml';
		$source = $this->commandService->getBundleDir() . '/translations/login.fr.yaml';
		$fileSystem->copy($source, $destination, true);
		
		$io->text('translations/security/security.login.fr.yaml');
	}
	
	private function updateTranslationConfig(ConsoleStyle $io): void
	{
		$fileSystem = new Filesystem();
		$destination = $this->commandService->getProjectDir() . '/config/packages/translation.yaml';
		$source = $this->commandService->getBundleDir() . '/templates/config/translation.yaml';
		$fileSystem->copy($source, $destination, true);
		
		$io->text('config/packages/translation.yaml');
	}
}
