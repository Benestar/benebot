<?php

/**
 * Register commands for addwiki.
 */

$dataValueDeserializer = new DataValues\Deserializers\DataValueDeserializer( array(
	'boolean' => 'DataValues\BooleanValue',
	'number' => 'DataValues\NumberValue',
	'string' => 'DataValues\StringValue',
	'unknown' => 'DataValues\UnknownValue',
	'globecoordinate' => 'DataValues\Geo\Values\GlobeCoordinateValue',
	'monolingualtext' => 'DataValues\MonolingualTextValue',
	'multilingualtext' => 'DataValues\MultilingualTextValue',
	'quantity' => 'DataValues\QuantityValue',
	'time' => 'DataValues\TimeValue',
	'wikibase-entityid' => 'Wikibase\DataModel\Entity\EntityIdValue',
) );

$GLOBALS['awwCommands'][] = function( Mediawiki\Bot\Config\AppConfig $appConfig ) use ( $dataValueDeserializer ) {
	return array(
		new BeneBot\SetDefaultRepo( $appConfig ),
		new BeneBot\UpdateBadges( $appConfig, $dataValueDeserializer ),
		new BeneBot\PurgeBadgesPageProps( $appConfig, $dataValueDeserializer ),
	);
};
