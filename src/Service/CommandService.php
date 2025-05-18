<?php

namespace LeTots\MakeBundle\Service;
use JsonException;
use Symfony\Bundle\MakerBundle\ConsoleStyle;
use Symfony\Bundle\MakerBundle\Util\ClassSourceManipulator;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Process\Process;
use Throwable;

class CommandService
{
	private Filesystem $filesystem;
	private string $logDir;
	
	public function __construct(
		private readonly KernelInterface $kernel,
	)
	{
		$this->filesystem = new Filesystem();
		$this->logDir = $kernel->getProjectDir() . '/var/log/';
	}
	
	public function runCLICommand(OutputInterface $output, array $command, ?bool $interactive = false, ?bool $ignoreErrors = false): string|int|null|false
	{
		$process = new Process($command);
		
		if($interactive) {
			$process->setTty(true); // ⚠️ Permet l'interaction avec l'utilisateur
			$process->setPty(true); // ⚠️ Nécessaire sur certains systèmes Unix
		}
		
		$process->run(function ($type, $buffer) use ($output) {
			$output->write($buffer); // Affiche la sortie en temps réel
		});
		
		if (!$ignoreErrors && !$process->isSuccessful()) {
			throw new \RuntimeException(sprintf("Erreur lors de l'exécution de %s", implode(' ', $command)));
		}
		
		if(!$interactive) {
			return $process->getOutput();
		}
		
		return $process->getExitCode();
	}
	
	public function runSymfonyCli(OutputInterface $output, array $command, ?bool $interactive = false, ?bool $ignoreErrors = false): string|int|null|false
	{
		$command = $this->hasSymfonyCli() ? ['symfony', ...$command] : $command;
		
		return $this->runCLICommand($output, $command, $interactive, $ignoreErrors);
	}
	
	public function runSymfonyCommand(OutputInterface $output, array $command): void
	{
		$process = new Process($this->getCommandWithSymfonyCli($command));
		$process->setTty(true); // ⚠️ Permet l'interaction avec l'utilisateur
		$process->setPty(true); // ⚠️ Nécessaire sur certains systèmes Unix
		
		$process->run(function ($type, $buffer) use ($output) {
			$output->write($buffer); // Affiche la sortie en temps réel
		});
		
		if (!$process->isSuccessful()) {
			throw new \RuntimeException(sprintf("Erreur lors de l'exécution de %s", implode(' ', $command)));
		}
	}
	
	public function runSymfonyCommandWithDefaults(OutputInterface $output, array $command, string $input): void
	{
		// Créer un processus pour exécuter la commande
		$process = new Process($this->getCommandWithSymfonyCli($command));
		
		// Simuler une entrée en envoyant des retours à la commande
		$process->setInput($input);
		
		$process->setTty(false);
		$process->setPty(false);
		
		$process->run(function ($type, $buffer) use ($output) {
			$output->write($buffer);
		});
		
		if (!$process->isSuccessful()) {
			throw new \RuntimeException(sprintf("Erreur lors de l'exécution de %s", implode(' ', $command)));
		}
	}
	
	// Méthode pour déterminer si on utilise le CLI Symfony ou PHP bin/console
	private function getCommandWithSymfonyCli(array $command): array
	{
		// Vérifier si le CLI symfony est disponible
		if ($this->hasSymfonyCli()) {
			return ['symfony', 'console', ...$command];
		}
		
		// Si symfony CLI n'est pas disponible, utiliser php bin/console
		return ['php', 'bin/console', ...$command];
	}
	
	// Méthode pour déterminer si Symfony CLI est installé
	private function hasSymfonyCli(): bool
	{
		return file_exists('/usr/local/bin/symfony') || shell_exec('which symfony');
	}
	
	public function createLogFile(string $path, string $content = ''): string
	{
		$filePath = $this->logDir . $path;
		
		try {
			$dirPath = dirname($filePath);
			if (!$this->filesystem->exists($dirPath)) {
				$this->filesystem->mkdir($dirPath, 0755);
			}
			
			// Créer le fichier avec le contenu spécifié
			$this->filesystem->dumpFile($filePath, $content);
			
			return $filePath;
		} catch (IOExceptionInterface $exception) {
			throw new \RuntimeException(sprintf('Impossible de créer le fichier : %s', $exception->getMessage()));
		}
	}
	
	public function getProjectDir(): string
	{
		return $this->kernel->getProjectDir();
	}
	
	public function getBundleDir(): string
	{
		return $this->getProjectDir() . '/vendor/letots/make-bundle';
	}
	
	public function createClassManipulator(string $path, ConsoleStyle $io, bool $overwrite): ClassSourceManipulator
	{
		$manipulator = new ClassSourceManipulator(
			sourceCode: file_get_contents($path),
			overwrite: $overwrite,
		);
		
		$manipulator->setIo($io);
		
		return $manipulator;
	}
	
	public function askYN(ConsoleStyle $io, string $question, string $default = 'y'): string
	{
		return $io->ask($question, $default, function (string $answer) {
			if (!in_array($answer, ['y', 'n'])) {
				throw new \RuntimeException('Réponse invalide, veuillez répondre par "y" ou "n".');
			}
			
			return $answer;
		});
	}
	
	/**
	 * @throws JsonException
	 */
	public function notInComposerJson(array $packages): array
	{
		$packagesNotInComposerJson = [];
		
		$composerJsonPath = $this->getProjectDir() . '/composer.json';
		
		if (!file_exists($composerJsonPath)) {
			return $packages;
		}
		
		$composerJson = json_decode(file_get_contents($composerJsonPath), true, 512, JSON_THROW_ON_ERROR);
		$require = $composerJson['require'] ?? [];
		
		foreach ($packages as $package) {
			if (isset($require[$package])) {
				continue;
			}
			
			try {
				$composerShowOutput = $this->runSymfonyCli(new NullOutput(), ['composer', 'show', $package, '--all', '--format=json'], false, true);
				$packageData = json_decode($composerShowOutput, true, 512, JSON_THROW_ON_ERROR);
			} catch (Throwable $e) {
				$packagesNotInComposerJson[] = $package;
				continue;
			}
			
			if (($packageData['type'] ?? null) === 'symfony-pack') {
				$subPackages = array_keys($packageData['requires'] ?? []);
				$missingSubPackages = $this->notInComposerJson($subPackages);
				
				if (!empty($missingSubPackages)) {
					$packagesNotInComposerJson[] = $package;
				}
			} else {
				$packagesNotInComposerJson[] = $package;
			}
		}
		
		return $packagesNotInComposerJson;
	}
	
	/**
	 * @throws JsonException
	 */
	public function addInComposerJsonIfNecessary(OutputInterface $output, ConsoleStyle $io, string|array $package, string|array|null $version = null): void
	{
		$package = is_array($package) ? $package : [$package];
		$version = $version ?? [];
		$version = is_array($version) ? $version : [$version];
		
		$packages = $this->notInComposerJson($package);

		$packagesDiff = array_diff($packages, $package);
		
		if(!empty($packages)) {
			$io->text('Ajout de ' . implode(' + ', $packages) . ' au composer.json');
			foreach($packages as $key => $pack) {
				$packages[$key] = $pack . ':' . ($version[$key] ?? '*');
			}
			$this->runSymfonyCli($output, ['composer', 'require', implode(' ', $packages)]);
		}
		
		foreach($packagesDiff as $packageAlreadyPresent) {
			$io->text('Le package ' . $packageAlreadyPresent . ' est déjà présent dans le composer.json');
		}
	}
}
