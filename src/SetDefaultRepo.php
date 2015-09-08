<?php

namespace BeneBot;

use Mediawiki\Bot\Config\AppConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Sets the default wikibase repository to be used by scripts.
 *
 * @author Bene* < benestar.wikimedia@gmail.com >
 */
class SetDefaultRepo extends Command {

	/**
	 * @var AppConfig
	 */
	private $appConfig;

	public function __construct( AppConfig $appConfig ) {
		$this->appConfig = $appConfig;

		parent::__construct( null );
	}

	protected function configure() {
		$this->setName( 'config:set:default:repo' )
			->setDescription( 'Sets the default wikibase repository to be used by scripts' )
			->addArgument(
				'code',
				InputArgument::REQUIRED,
				'The wikicode to set as the default',
				null
			);
	}

	protected function execute( InputInterface $input, OutputInterface $output ) {
		$code = $input->getArgument( 'code' );

		if( !$this->appConfig->has( 'wikis.' . $code ) ) {
			$output->writeln( "No wiki with the code $code found" );
			return -1;
		}

		$this->appConfig->set( 'defaults.repo', $code );
		$output->writeln( "Default repo set to: $code" );
	}

}
