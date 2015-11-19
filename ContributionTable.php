<?php

if ( function_exists( 'wfLoadExtension' ) ) {
	wfLoadExtension( 'ContributionTable' );
	// Keep i18n globals so mergeMessageFileList.php doesn't break
	$wgMessagesDirs['ContributionTable'] = __DIR__ . '/i18n';
	$wgExtensionMessagesFiles['ContributionTableAlias'] = __DIR__ . '/ContributionTable.alias.php';
	/* wfWarn(
		'Deprecated PHP entry point used for ContributionTable extension. Please use wfLoadExtension instead, ' .
		'see https://www.mediawiki.org/wiki/Extension_registration for more details.'
	); */
	return;
} else {
	die( 'This version of the ContributionTable extension requires MediaWiki 1.25+' );
}
