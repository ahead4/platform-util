<?php namespace Platform\Util\Console;

use PDO;
use PDOException;
use Exception;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Ahead4\Licensing\Licensing;
use Illuminate\Support\Str;

class InstallCommand extends Command
{
	/**
	 * The input interface.
	 *
	 * @var \Symfony\Component\Console\Input\InputInterface
	 */
	protected $input;

	/**
	 * The output interface.
	 *
	 * @var \Symfony\Component\Console\Output\OutputInterface
	 */
	protected $output;

	/**
	 * The constructor.
	 */
	public function __construct()
	{
		parent::__construct();

		$this->licensing = new Licensing;
	}

	protected function info($message)
	{
		$this->output->writeln('<info>' . $message . '</info>');
	}

	protected function error($message)
	{
		$this->output->writeln('<error>' . $message . '</error>');
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
			->setDescription('Installs the latest version of Platform');
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
		$this->input  = $input;
		$this->output = $output;

		try {
			$helper = $this->getHelper('question');
			
			$licenseKey = $helper->ask($input, $output, new Question('Enter the Platform license key: '));
			$licenseData = $this->decryptLicenseKey($licenseKey);
			if (!$licenseData) {
				return $this->error('Unable to decrypt license key.');
			}

			$installPath = $helper->ask($input, $output, new Question('Enter the installation path: '));
			if (file_exists($installPath) && !is_dir($installPath)) {
				return $this->error('Installation path is not a directory.');
			}

			$dbNameQuestion = new Question('Enter the database name: ');
			$dbNameQuestion->setValidator(function ($answer) {
				if (!$this->checkDatabaseExists($answer)) {
					return $this->error('A database with the name "' . $answer . '" does not exist.');
				}
				return $answer;
			});
			$dbName = $helper->ask($input, $output, $dbNameQuestion);
			if (!$dbName) {
				return;
			}

			$this->info('Installing base system...');
			$this->installBaseSystem($installPath);

			$this->info('Installing features...');
			$this->installFeatures($installPath, $licenseData['features']);

			$this->info('Setting up the environment...');
			$this->setupEnvironment($installPath, $dbName);

			$this->info('Migrating and seeding database...');
			$this->migrateAndSeed($installPath, $licenseKey);

			$this->info('Installation complete.');
		} catch (Exception $e) {
			$this->error($e->getMessage());
		}
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
	 * Check whether a database exists.
	 *
	 * @param  string $name
	 * @return boolean
	 */
	protected function checkDatabaseExists($name)
	{
		try {
			$pdo = new PDO('mysql:dbname=' . $name . ';host=127.0.0.1', 'root', '');

			$pdo->query('SHOW DATABASES LIKE \'' . $name . '\';');
		} catch (PDOException $e) {
			$this->error('PDO Exception: ' . $e->getMessage());

			return false;
		}

		return true;
	}

	/**
	 * Execute a command.
	 *
	 * @param  string $command
	 * @param  string $path
	 * @return void
	 */
	protected function execCommand($command, $path = null)
	{
		$process = new Process($command, $path);
		$process->setTimeout(600); // 10 Mins

		$process->run(function ($type, $buffer) {
			echo $buffer;
		});
	}

	/**
	 * Install the base system.
	 *
	 * @param  string $path
	 * @return void
	 */
	protected function installBaseSystem($path)
	{
		$this->execCommand('composer create-project ahead4-platform/base --no-interaction --repository-url=http://packages.ahead4.com --stability=dev ' . $path);
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

		$this->execCommand('composer update --no-interaction', $path);
	}

	/**
	 * Setup the environment.
	 *
	 * @param  string $path
	 * @param  string $dbName
	 * @return void
	 */
	protected function setupEnvironment($path, $dbName)
	{
		$envPath = $path . '/.env';

		$contents = file_get_contents($envPath);
		$contents = str_replace('DB_DATABASE=platform', 'DB_DATABASE=' . $dbName, $contents);

		file_put_contents($envPath, $contents);
	}

	/**
	 * Migrate and seed the database.
	 *
	 * @param  string $path
	 * @param  string $licenseKey
	 * @return void
	 */
	protected function migrateAndSeed($path, $licenseKey)
	{
		$this->execCommand('php artisan platform:migrate', $path);

		$this->execCommand('php artisan platform:update-license ' . $licenseKey, $path);

		$this->execCommand('php artisan platform:seed', $path);
	}
}
