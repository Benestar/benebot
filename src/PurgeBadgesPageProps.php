<?php

namespace BeneBot;

use Exception;
use Mediawiki\Api\ApiUser;
use Mediawiki\Api\MediawikiApi;
use Mediawiki\Api\SimpleRequest;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Wikibase\DataModel\Entity\Item;

/**
 * Purge Wikipedia pages with badges to update page props.
 *
 * @licence GNU GPL v2+
 * @author Bene* < benestar.wikimedia@gmail.com >
 */
class PurgeBadgesPageProps implements WikibaseExecutor {

	public function configure( Command $command ) {
		$command->setName( 'task:purge-badge-page-props' )
			->setDescription( 'Purge page props of pages to update badges' )
			->addOption(
				'chunk',
				null,
				InputOption::VALUE_OPTIONAL,
				'The chunk size to fetch entities',
				100
			);
	}

	public function execute( CommandHelper $commandHelper ) {
		$db = $commandHelper->getDatabase( 'repo' );

		$wikibaseFactory = $commandHelper->getWikibaseFactory();
		$badgeIdsGetter = $wikibaseFactory->newBadgeIdsGetter();
		$revisionsGetter = $wikibaseFactory->newRevisionsGetter();

		$commandHelper->writeln( 'Fetching sites info...' );
		$siteApis = $this->getSiteApis( $db );

		if ( $siteApis === null ) {
			throw new RuntimeException( 'Failed to fetch sites data' );
		}

		$commandHelper->writeln( 'Fetching badge ids...' );
		$badgeIds = $badgeIdsGetter->get();
		$commandHelper->writeln( 'Got ' . implode( ', ', $badgeIds ) );

		$commandHelper->writeln( 'Fetching badge usages...' );
		$pagesToPurge = array();

		$results = $db->query(
			'SELECT page_title FROM pagelinks
			JOIN page ON pl_from = page_id
			WHERE pl_title IN( "' . implode( '", "', $badgeIds ) . '" )
			AND pl_namespace = 0
			AND pl_from_namespace = 0'
		);

		if ( !$results ) {
			throw new RuntimeException( 'Failed to fetch badge usage' );
		}

		$entityIds = array();

		while ( $row = $results->fetch_assoc() ) {
			$entityIds[] = $row['page_title'];
		}

		$progressBar = $commandHelper->getProgressBar( $results->num_rows );
		$results->free();

		$commandHelper->writeln( 'Fetching entities...' );
		$progressBar->start();

		$chunk = $commandHelper->getOption( 'chunk' );

		foreach ( array_chunk( $entityIds, $chunk ) as $batch ) {
			try {
				$revisions = $revisionsGetter->getRevisions( $batch );

				foreach ( $revisions->toArray() as $revision ) {
					/** @var Item $item */
					$item = $revision->getContent()->getData();

					foreach ( $item->getSiteLinkList()->toArray() as $siteLink ) {
						if ( !empty( $siteLink->getBadges() ) ) {
							$pagesToPurge[$siteLink->getSiteId()][] = $siteLink->getPageName();
						}
					}
				}

				$progressBar->advance( $chunk );
			} catch ( Exception $ex ) {
				$commandHelper->writeln( 'Failed to fetch data for ids ' . implode( ', ', $batch ) . ' (' . $ex->getMessage() . ')' );
			}
		}

		$progressBar->finish();

		$commandHelper->writeln( 'Starting to purge pages' );
		$this->purgePages( $pagesToPurge, $siteApis, $commandHelper, $commandHelper->getApiUser() );
	}

	private function getSiteApis( \mysqli $db ) {
		$siteApis = array();

		$results = $db->query(
			'select site_global_key, site_data from sites'
		);

		if ( !$results ) {
			return null;
		}

		while ( $row = $results->fetch_assoc() ) {
			$data = unserialize( $row['site_data'] );
			$siteApis[$row['site_global_key']] = str_replace( '$1', 'api.php', $data['paths']['file_path'] );
		}

		$results->free();

		return $siteApis;
	}

	private function purgePages( array $pagesToPurge, array $siteApis, CommandHelper $commandHelper, ApiUser $apiUser ) {
		$allCount = 0;

		foreach ( $siteApis as $siteId => $url ) {
			if ( !isset( $pagesToPurge[$siteId] ) ) {
				$commandHelper->writeln( "\nNo pages found to purge for site $siteId ($url)" );
				continue;
			}

			$count = count( $pagesToPurge[$siteId] );
			$commandHelper->writeln( "\nPurging $count pages for site $siteId ($url)" );

			$api = new MediawikiApi( $url );
			$api->login( $apiUser );

			$progressBar = $commandHelper->getProgressBar( $count );
			$progressBar->start();

			foreach ( array_chunk( $pagesToPurge[$siteId], 10 ) as $pages ) {
				$params = array(
					'titles' => implode( '|', $pages ),
					'forcelinkupdate' => true
				);

				$api->postRequest( new SimpleRequest( 'purge', $params ) );
				$progressBar->advance( 10 );
			}

			$progressBar->finish();
			$allCount += $count;
		}

		$commandHelper->writeln( "\nFinished purging $allCount pages" );
	}

}
