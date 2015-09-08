<?php

namespace BeneBot;

use DataValues\Serializers\DataValueSerializer;
use Deserializers\Deserializer;
use Exception;
use Mediawiki\Api\ApiUser;
use Mediawiki\Api\MediawikiApi;
use Mediawiki\Bot\Config\AppConfig;
use Mediawiki\DataModel\EditInfo;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Wikibase\Api\WikibaseFactory;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\SiteLink;

/**
 * Update badges based on Wikipedia categories on Wikidata.
 *
 * @author Bene* < benestar.wikimedia@gmail.com >
 */
class UpdateBadges extends Command {

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

		$this->setName( 'task:update-badges' )
			->setDescription( 'Update badges based on Wikipedia categories on Wikidata' )
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
			)
			->addOption(
				'badge',
				null,
				InputOption::VALUE_REQUIRED,
				'The badge to set'
			)
			->addOption(
				'category',
				null,
				InputOption::VALUE_REQUIRED,
				'The category to query'
			)
			->addOption(
				'bot',
				null,
				InputOption::VALUE_OPTIONAL,
				'Mark edits as bot',
				true
			)
			->addOption(
				'summary',
				null,
				InputOption::VALUE_OPTIONAL,
				'Override the default edit summary',
				'Bot: Adding badge [[$badgeId]] for site $wiki based on Category:$category'
			);
	}

	protected function execute( InputInterface $input, OutputInterface $output ) {
		$wiki = $input->getOption( 'wiki' );
		$database = $input->getOption( 'database' );
		$user = $input->getOption( 'user' );
		$repo = $input->getOption( 'repo' );

		$userDetails = $this->appConfig->get( 'users.' . $user );
		$databaseDetails = $this->appConfig->get( 'users.' . $database );
		$wikiDetails = $this->appConfig->get( 'wikis.' . $wiki );
		$repoDetails = $this->appConfig->get( 'wikis.' . $repo );

		if( $userDetails === null ) {
			throw new RuntimeException( 'User not found in config' );
		}

		if( $wikiDetails === null ) {
			throw new RuntimeException( 'Wiki not found in config' );
		}

		if( $repoDetails === null ) {
			throw new RuntimeException( 'Repo not found in config' );
		}

		$api = new MediawikiApi( $wikiDetails['url'] );
		$repoApi = new MediawikiApi( $repoDetails['url'] );
		$loggedIn = $repoApi->login( new ApiUser( $userDetails['username'], $userDetails['password'] ) );
		$db = new \mysqli( $wiki . '.labsdb', $databaseDetails['username'], $databaseDetails['password'], $wiki . '_p' );

		if( !$loggedIn || $db->connect_error ) {
			$output->writeln( 'Failed to log in' );
			return -1;
		}

		$wikibaseFactory = new WikibaseFactory( $repoApi, $this->dataValueDeserializer, new DataValueSerializer() );
		$revisionsGetter = $wikibaseFactory->newRevisionGetter();
		$siteLinkSetter = $wikibaseFactory->newSiteLinkSetter();

		$badgeId = new ItemId( $input->getOption( 'badge' ) );
		$editInfo = new EditInfo(
			$this->getSummary( $input->getOption( 'summary' ), $input->getOption( 'category' ), $wiki, $badgeId->getSerialization() ),
			false,
			$input->getOption( 'bot' )
		);

		$results = $db->query(
			'SELECT page_title FROM categorylinks
			JOIN page ON page_id = cl_from
			WHERE cl_to = "' . $db->escape_string( $input->getOption( 'category' ) ) . '"
			AND page_namespace = 0'
		);

		$skipped = 0;
		$added = 0;
		$failed = 0;

		if ( $results ) {
			while ( $row = $results->fetch_assoc() ) {
				try {
					/** @var Item $item */
					$revision = $revisionsGetter->getFromSiteAndTitle( $wiki, $row['page_title'] );

					if ( $revision === false ) {
						$output->writeln( "\nNo item found for $wiki:{$row['page_title']}" );
						$failed++;
					}

					$item = $revision->getContent()->getData();
					$badges = $item->getSiteLinkList()->getBySiteId( $wiki )->getBadges();

					if ( in_array( $badgeId, $badges ) ) {
						$output->write( '.' );
						$skipped++;
						continue;
					}

					$badges[] = $badgeId;

					$siteLinkSetter->set(
						new SiteLink( $wiki, $row['page_title'], $badges ),
						new SiteLink( $wiki, $row['page_title'] )
						//$editInfo
					);

					$output->writeln( "\nAdded badge for $wiki:{$row['page_title']}" );
					$added++;
				} catch ( Exception $ex ) {
					$output->writeln( "\nFailed to add badge for $wiki:{$row['page_title']} (" . $ex->getMessage() . ")" );
					$failed++;
				}
			}
		}

		$output->writeln( "Finished iterating through $results->num_rows site links." );
		$output->writeln( "Added: $added" );
		$output->writeln( "Skipped: $skipped" );
		$output->writeln( "Failed: $failed" );

		return null;
	}

	private function getSummary( $rawSummary, $category, $wiki, $badgeId ) {
		return strtr(
			$rawSummary,
			array(
				'$category' => $category,
				'$wiki' => $wiki,
				'$badgeId' => $badgeId
			)
		);
	}

}
