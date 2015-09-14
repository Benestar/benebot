<?php

namespace BeneBot;

use DataValues\Serializers\DataValueSerializer;
use Deserializers\Deserializer;
use Exception;
use Mediawiki\Bot\Config\AppConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class WikibaseCommand extends Command {

	/**
	 * @var AppConfig
	 */
	private $appConfig;

	/**
	 * @var Deserializer
	 */
	private $dataValueDeserializer;

	/**
	 * @var WikibaseExecutor
	 */
	private $wikibaseExecutor;

	public function __construct(
		AppConfig $appConfig,
		Deserializer $dataValueDeserializer,
		WikibaseExecutor $wikibaseExecutor
	) {
		$this->appConfig = $appConfig;
		$this->dataValueDeserializer = $dataValueDeserializer;
		$this->wikibaseExecutor = $wikibaseExecutor;

		parent::__construct( null );
	}

	protected function configure() {
		$defaultUser = $this->appConfig->get( 'defaults.user' );
		$defaultDatabase = $this->appConfig->get( 'defaults.database' );
		$defaultWiki = $this->appConfig->get( 'defaults.wiki' );
		$defaultRepo = $this->appConfig->get( 'defaults.repo' );

		$this->addOption(
			'user',
			null,
			( $defaultUser === null ? InputOption::VALUE_REQUIRED : InputOption::VALUE_OPTIONAL ),
			'The configured user to use',
			$defaultUser
		)
		->addOption(
			'database',
			null,
			( $defaultUser === null ? InputOption::VALUE_REQUIRED : InputOption::VALUE_OPTIONAL ),
			'The configured database to use',
			$defaultDatabase
		)
		->addOption(
			'wiki',
			null,
			( $defaultWiki === null ? InputOption::VALUE_REQUIRED : InputOption::VALUE_OPTIONAL ),
			'The client wiki to use',
			$defaultWiki
		)
		->addOption(
			'repo',
			null,
			( $defaultRepo === null ? InputOption::VALUE_REQUIRED : InputOption::VALUE_OPTIONAL ),
			'The repo wiki to use',
			$defaultRepo
		);

		$this->wikibaseExecutor->configure( $this );
	}

	protected function execute( InputInterface $input, OutputInterface $output ) {
		$commandHelper = new CommandHelper(
			$input,
			$output,
			$this->appConfig,
			$this->dataValueDeserializer,
			new DataValueSerializer()
		);

		try {
			$this->wikibaseExecutor->execute( $commandHelper );
		}
		catch ( Exception $ex ) {
			$commandHelper->writeln( 'An error has occured: ' . $ex->getMessage(), OutputInterface::VERBOSITY_QUIET );
			return -1;
		}

		return 1;
	}

}
