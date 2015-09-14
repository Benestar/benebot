<?php

namespace BeneBot;

use ConfigurationException;
use Deserializers\Deserializer;
use Mediawiki\Api\ApiUser;
use Mediawiki\Api\MediawikiApi;
use Mediawiki\Bot\Config\AppConfig;
use RuntimeException;
use Serializers\Serializer;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Wikibase\Api\WikibaseApi;
use Wikibase\Api\WikibaseFactory;

class CommandHelper {


	/**
	 * @var InputInterface
	 */
	private $input;

	/**
	 * @var OutputInterface
	 */
	private $output;

	/**
	 * @var AppConfig
	 */
	private $appConfig;

	/**
	 * @var Deserializer
	 */
	private $dataValueDeserializer;

	/**
	 * @var Serializer
	 */
	private $dataValueSerializer;

	public function __construct(
		InputInterface $input,
		OutputInterface $output,
		AppConfig $appConfig,
		Deserializer $dataValueDeserializer,
		Serializer $dataValueSerializer
	) {
		$this->input = $input;
		$this->output = $output;
		$this->appConfig = $appConfig;
		$this->dataValueDeserializer = $dataValueDeserializer;
		$this->dataValueSerializer = $dataValueSerializer;
	}

	/**
	 * @param string $wiki
	 * @return \mysqli
	 * @throws RuntimeException
	 */
	public function getDatabase( $wiki = null ) {
		$wiki = $this->input->getOption( $wiki ?: 'wiki' );
		$database = $this->input->getOption( 'database' );
		$databaseDetails = $this->appConfig->get( 'users.' . $database );

		if ( $databaseDetails === null ) {
			throw new RuntimeException( "Database user $database not found in config" );
		}

		$db = new \mysqli( $wiki . '.labsdb', $databaseDetails['username'], $databaseDetails['password'], $wiki . '_p' );

		if ( $db->connect_error ) {
			throw new RuntimeException( "Could not connect to the database. ($db->connect_error)" );
		}

		return $db;
	}

	/**
	 * @param string $user
	 * @return ApiUser
	 * @throws RuntimeException
	 */
	public function getApiUser( $user = null ) {
		$user = $this->input->getOption( $user ?: 'user' );
		$userDetails = $this->appConfig->get( 'users.' . $user );

		if( $userDetails === null ) {
			throw new RuntimeException( "User $user not found in config" );
		}

		return new ApiUser( $userDetails['username'], $userDetails['password'] );
	}

	/**
	 * @param string $wiki
	 * @param string $user
	 * @return MediawikiApi
	 * @throws RuntimeException
	 */
	public function getMediawikiApi( $wiki = null, $user = null ) {
		$wiki = $this->input->getOption( $wiki ?: 'wiki' );
		$wikiDetails = $this->appConfig->get( 'wikis.' . $wiki );

		if( $wikiDetails === null ) {
			throw new RuntimeException( "Wiki $wiki not found in config" );
		}

		$api = new MediawikiApi( $wikiDetails['url'] );
		$loggedIn = $api->login( $this->getApiUser( $user ) );

		if( !$loggedIn ) {
			throw new RuntimeException( 'Failed to login' );
		}

		return $api;
	}

	/**
	 * @param string $repo
	 * @param string $user
	 * @return WikibaseFactory
	 * @throws RuntimeException
	 */
	public function getWikibaseFactory( $wiki = null, $user = null ) {
		return new WikibaseFactory(
			$this->getMediawikiApi( $wiki ?: 'repo', $user ),
			$this->dataValueDeserializer,
			$this->dataValueSerializer
		);
	}

	/**
	 * @param string $line
	 * @param int $verbosity
	 */
	public function writeln( $line, $verbosity = OutputInterface::VERBOSITY_NORMAL ) {
		if ( $verbosity <= $this->output->getVerbosity() ) {
			$this->output->writeln( $line );
		}
	}

	/**
	 * @param string $line
	 * @param int $verbosity
	 */
	public function write( $line, $verbosity = OutputInterface::VERBOSITY_NORMAL ) {
		if ( $verbosity <= $this->output->getVerbosity() ) {
			$this->output->write( $line );
		}
	}

	/**
	 * @param int $max
	 * @return ProgressBar
	 */
	public function getProgressBar( $max ) {
		return new ProgressBar( $this->output, $max );
	}

	/**
	 * @param string $name
	 * @return mixed
	 */
	public function getOption( $name ) {
		return $this->input->getOption( $name );
	}

}
