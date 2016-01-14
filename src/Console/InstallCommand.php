<?php namespace Platform\Util\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Ahead4\Licensing\Licensing;
use Illuminate\Support\Str;

class InstallCommand extends Command
{
	/**
	 * The constructor.
	 */
	public function __construct()
	{
		parent::__construct();

		$this->licensing = new Licensing;
	}

	/**
	 * Configure the command.
	 * 
	 * @return void
	 */
	protected function configure()
	{
		$this
			->setName('install')
			->setDescription('Installs the latest version of Platform')
			->addArgument('path', InputArgument::REQUIRED, 'The path to where Platform should be installed.')
			->addArgument('licenseKey', InputArgument::REQUIRED, 'The license key to use.')
		;
	}

	/**
	 * Execute the command.
	 * 
	 * @param  \Symfony\Component\Console\Input\InputInterface   $input
	 * @param  \Symfony\Component\Console\Output\OutputInterface $output
	 * @return void
	 */
	protected function execute(InputInterface $input, OutputInterface $output)
	{
		$licenseData = $this->decryptLicenseKey($input->getArgument('licenseKey'));
		if (!$licenseData) {
			return $output->writeln('<error>Unable to decrypt license key.</error>');
		}

		$path = realpath($input->getArgument('path'));
		if (!is_dir($path)) {
			return $output->writeln('<error>Installation path either doesn\'t exist or is not a directory.</error>');
		}

		$output->writeln('<info>Installing base system...</info>');
		$this->installBaseSystem($path);

		$output->writeln('<info>Installing features...</info>');
		$this->installFeatures($path, $licenseData['features']);

		$output->writeln('<info>Installation complete.</info>');
	}

	/**
	 * Decrypt the license key and return it's data.
	 * 
	 * @param  string $licenseKey
	 * @return array|null
	 */
	protected function decryptLicenseKey($licenseKey)
	{
		$licensePath = tempnam(sys_get_temp_dir(), 'platform-license');
		file_put_contents($licensePath, $licenseKey);
		return $this->licensing->getLicenseData($licensePath);
	}

	/**
	 * Install the base system.
	 * 
	 * @param  string $path
	 * @return void
	 */
	protected function installBaseSystem($path)
	{
		$command = 'composer create-project ahead4-platform/base --no-interaction --repository-url=http://packages.ahead4.com --stability=dev ' . $path;
		$process = new Process($command);
		$process->setTimeout(600); // 10 Mins

		$process->run(function($type, $buffer) {
			echo ' > ' . $buffer;
		});
	}

	/**
	 * Install the features.
	 * 
	 * @param  string $path
	 * @param  array  $features
	 * @return void
	 */
	protected function installFeatures($path, array $features)
	{
		$composerPath = $path . '/composer.json';
		$composerConf = json_decode(file_get_contents($composerPath), true);

		foreach ($features as $feature) {
			$composerConf['require']['ahead4-platform/' . $feature] = 'dev-master';
		}

		file_put_contents($composerPath, json_encode($composerConf, JSON_PRETTY_PRINT));

		$command = 'composer update --no-interaction';
		$process = new Process($command, $path);
		$process->setTimeout(600); // 10 Mins

		$process->run(function($type, $buffer) {
			echo ' > ' . $buffer;
		});
	}
}