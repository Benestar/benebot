<?php

namespace BeneBot;

use Exception;
use Mediawiki\DataModel\EditInfo;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\SiteLink;

/**
 * Update badges based on Wikipedia categories on Wikidata.
 *
 * @licence GNU GPL v2+
 * @author Bene* < benestar.wikimedia@gmail.com >
 */
class UpdateBadges implements WikibaseExecutor {

	public function configure( Command $command ) {
		$command->setName( 'task:update-badges' )
			->setDescription( 'Update badges based on Wikipedia categories on Wikidata' )
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

	public function execute( CommandHelper $commandHelper ) {
		$db = $commandHelper->getDatabase();

		$wikibaseFactory = $commandHelper->getWikibaseFactory();
		$revisionsGetter = $wikibaseFactory->newRevisionGetter();
		$siteLinkSetter = $wikibaseFactory->newSiteLinkSetter();

		$wiki = $commandHelper->getOption( 'wiki' );

		$badgeId = new ItemId( $commandHelper->getOption( 'badge' ) );
		$editInfo = new EditInfo(
			$this->getSummary(
				$commandHelper->getOption( 'summary' ),
				$commandHelper->getOption( 'category' ),
				$wiki,
				$badgeId->getSerialization()
			),
			false,
			$commandHelper->getOption( 'bot' )
		);

		$results = $db->query(
			'SELECT page_title FROM categorylinks
			JOIN page ON page_id = cl_from
			WHERE cl_to = "' . $db->escape_string( $commandHelper->getOption( 'category' ) ) . '"
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
						$commandHelper->writeln( "\nNo item found for $wiki:{$row['page_title']}" );
						$failed++;
						continue;
					}

					$item = $revision->getContent()->getData();
					$badges = $item->getSiteLinkList()->getBySiteId( $wiki )->getBadges();

					if ( in_array( $badgeId, $badges ) ) {
						$commandHelper->write( '.' );
						$skipped++;
						continue;
					}

					$badges[] = $badgeId;

					$siteLinkSetter->set(
						new SiteLink( $wiki, $row['page_title'], $badges ),
						new SiteLink( $wiki, $row['page_title'] ),
						$editInfo
					);

					$commandHelper->writeln( "\nAdded badge for $wiki:{$row['page_title']}" );
					$added++;
				} catch ( Exception $ex ) {
					$commandHelper->writeln( "\nFailed to add badge for $wiki:{$row['page_title']} (" . $ex->getMessage() . ")" );
					$failed++;
				}
			}
		}

		$results->free();

		$commandHelper->writeln( "Finished iterating through $results->num_rows site links." );
		$commandHelper->writeln( "Added: $added" );
		$commandHelper->writeln( "Skipped: $skipped" );
		$commandHelper->writeln( "Failed: $failed" );
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
