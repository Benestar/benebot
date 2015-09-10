<?php

namespace BeneBot;

use DataValues\Serializers\DataValueSerializer;
use Deserializers\Deserializer;
use Exception;
use Mediawiki\Api\ApiUser;
use Mediawiki\Api\MediawikiApi;
use Mediawiki\Api\SimpleRequest;
use Mediawiki\Bot\Config\AppConfig;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Wikibase\Api\WikibaseFactory;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;

/**
 * Purge Wikipedia pages with badges to update page props.
 *
 * @licence GNU GPL v2+
 * @author Bene* < benestar.wikimedia@gmail.com >
 */
class PurgeBadgesPageProps extends Command {

	/**
	 * @var AppConfig
	 */
	private $appConfig;

	/**
	 * @var Deserializer
	 */
	private $dataValueDeserializer;

	public function __construct( AppConfig $appConfig, Deserializer $dataValueDeserializer ) {
		$this->appConfig = $appConfig;
		$this->dataValueDeserializer = $dataValueDeserializer;

		parent::__construct( null );
	}

	protected function configure() {
		$defaultUser = $this->appConfig->get( 'defaults.user' );
		$defaultDatabase = $this->appConfig->get( 'defaults.database' );
		$defaultWiki = $this->appConfig->get( 'defaults.wiki' );
		$defaultRepo = $this->appConfig->get( 'defaults.repo' );

		$this->setName( 'task:purge-badge-page-props' )
			->setDescription( 'Purge page props of pages to update badges' )
			->addOption(
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
				'The configured wiki to use',
				$defaultWiki
			)
			->addOption(
				'repo',
				null,
				( $defaultRepo === null ? InputOption::VALUE_REQUIRED : InputOption::VALUE_OPTIONAL ),
				'The Wikibase repository to use',
				$defaultRepo
			);
	}

	protected function execute( InputInterface $input, OutputInterface $output ) {
		$user = $input->getOption( 'user' );
		$database = $input->getOption( 'database' );
		$repo = $input->getOption( 'repo' );

		$userDetails = $this->appConfig->get( 'users.' . $user );
		$databaseDetails = $this->appConfig->get( 'users.' . $database );
		$repoDetails = $this->appConfig->get( 'wikis.' . $repo );

		if( $userDetails === null ) {
			throw new RuntimeException( 'User not found in config' );
		}

		if( $repoDetails === null ) {
			throw new RuntimeException( 'Repo not found in config' );
		}

		$repoApi = new MediawikiApi( $repoDetails['url'] );
		$loggedIn = $repoApi->login( new ApiUser( $userDetails['username'], $userDetails['password'] ) );
		$db = new \mysqli( $repo . '.labsdb', $databaseDetails['username'], $databaseDetails['password'], $repo . '_p' );

		if( !$loggedIn || $db->connect_error ) {
			$output->writeln( 'Failed to log in' );
			return -1;
		}

		$wikibaseFactory = new WikibaseFactory( $repoApi, $this->dataValueDeserializer, new DataValueSerializer() );
		$badgeIdsGetter = $wikibaseFactory->newBadgeIdsGetter();
		$revisionsGetter = $wikibaseFactory->newRevisionGetter();

		$output->writeln( 'Fetching badge ids...' );

		$badgeIds = $badgeIdsGetter->get();

		$output->writeln( 'Fetching badge usages...' );
		$pagesToPurge = array();

		$results = $db->query(
			'SELECT page_title FROM pagelinks
			JOIN page ON pl_from = page_id
			WHERE pl_title IN( "' . implode( '", "', $badgeIds ) . '" )
			AND pl_from_namespace = 0'
		);

		if ( $results ) {
			while ( $row = $results->fetch_assoc() ) {
				try {
					/** @var Item $item */
					$revision = $revisionsGetter->getFromId( new ItemId( $row['page_title'] ) );

					if ( $revision === false ) {
						// this shouldn't (can't) happen
						$output->writeln( "\nNo item found for id {$row['page_title']}" );
					}

					$item = $revision->getContent()->getData();

					foreach ( $item->getSiteLinkList()->toArray() as $siteLink ) {
						if ( !empty( $siteLink->getBadges() ) ) {
							$pagesToPurge[$siteLink->getSiteId()][] = $siteLink->getPageName();
						}

						$output->write( '.' );
					}
				} catch ( Exception $ex ) {
					$output->writeln( "\nFailed to fetch data for id {$row['page_title']} (" . $ex->getMessage() . ")" );
				}
			}
		}

		$results->free();

		$output->writeln( "\nStarting to purge pages" );
		$siteApis = array();

		$results = $db->query(
			'select site_global_key, site_data from sites'
		);

		if ( $results ) {
			while ( $row = $results->fetch_assoc() ) {
				$data = unserialize( $row['site_data'] );
				$siteApis[$row['site_global_key']] = str_replace( '$1', 'api.php', $data['paths']['file_path'] );
			}
		}

		$results->free();

		$allCount = 0;

		foreach ( $siteApis as $siteId => $url ) {
			if ( !isset( $pagesToPurge[$siteId] ) ) {
				$output->writeln( "\nNo pages found to purge for site $siteId ($url)" );
				continue;
			}

			$count = count( $pagesToPurge[$siteId] );
			$output->writeln( "\nPurging $count pages for site $siteId ($url)" );

			$api = new MediawikiApi( $url );
			$api->login( new ApiUser( $userDetails['username'], $userDetails['password'] ) );

			$progressBar = new ProgressBar( $output, $count );
			$progressBar->start();

			foreach ( array_chunk( $pagesToPurge[$siteId], 10 ) as $pages ) {
				$params = array(
					'titles' => implode( '|', $pages )
				);

				$api->getRequest( new SimpleRequest( 'purge', $params ) );
				$progressBar->advance( 10 );
			}

			$progressBar->finish();
			$allCount += $count;
			sleep( 0.1 );
		}

		$output->writeln( "\nFinished purging $allCount pages" );

		return null;
	}

}
