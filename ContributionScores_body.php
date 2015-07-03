<?php
/** \file
* \brief Contains code for the ContributionScores Class (extends SpecialPage).
*/

/// Special page class for the Contribution Scores extension
/**
 * Special page that generates a list of wiki contributors based
 * on edit diversity (unique pages edited) and edit volume (total
 * number of edits.
 *
 * @ingroup Extensions
 * @author Tim Laqua <t.laqua@gmail.com>
 */
class ContributionScores extends IncludableSpecialPage {
	public function __construct() {
		parent::__construct( 'ContributionScores' );
	}

	/// Generates a "Contribution Scores" table for a given LIMIT and date range
	/**
	 * Function generates Contribution Scores tables in HTML format (not wikiText)
	 *
	 * @param $days int Days in the past to run report for
	 * @param $limit int Maximum number of users to return (default 50)
	 * @param $title Title (default null)
	 * @param $options array of options (default none; nosort/notools)
	 * @return Html Table representing the requested Contribution Scores.
	 */
	function genContributionScoreTable( $days, $limit = 50, $title = null, $options = 'none' ) {
		global $wgContribScoreIgnoreBots, $wgContribScoreIgnoreBlockedUsers, $wgContribScoresUseRealName;

		$opts = explode( ',', strtolower( $options ) );

		$dbr = wfGetDB( DB_SLAVE );

		$userTable = $dbr->tableName( 'user' );
		$userGroupTable = $dbr->tableName( 'user_groups' );
		$revTable = $dbr->tableName( 'revision' );
		$ipBlocksTable = $dbr->tableName( 'ipblocks' );

		$sqlWhere = "";
		$nextPrefix = "WHERE";

		$vars = array(
			'r.rev_user',
			'page_count' => 'COUNT(DISTINCT r.rev_page)',
			'rev_count' => 'COUNT(r.rev_id)',
		);
		$conds = array();
		$options = array( 'GROUP BY' => 'r.rev_user' );

		if ( $days > 0 ) {
			$date = time() - ( 60 * 60 * 24 * $days );
			$dateString = $dbr->timestamp( $date );
			$cond = "r.rev_timestamp > '$dateString'";
			$sqlWhere .= " {$nextPrefix} {$cond}";
			$conds[] = $cond;
			$nextPrefix = "AND";
		}

		if ($limit > 0)
		{
			$options['LIMIT'] = $limit;
		}

		if ( $wgContribScoreIgnoreBlockedUsers ) {
			$cond = "r.rev_user NOT IN (SELECT ipb_user FROM {$ipBlocksTable} WHERE ipb_user <> 0)";
			$sqlWhere .= " {$nextPrefix} {$cond}";
			$conds[] = $cond;
			$nextPrefix = "AND";
		}

		if ( $wgContribScoreIgnoreBots ) {
			$cond = "r.rev_user NOT IN (SELECT ug_user FROM {$userGroupTable} WHERE ug_group='bot')";
			$sqlWhere .= " {$nextPrefix} {$cond}";
			$conds[] = $cond;
		}

		$options['ORDER BY'] = 'page_count DESC';
		$sqlMostPages = $dbr->selectSQLText( ['r' => 'revision'], $vars, $conds, __METHOD__, $options);

		$options['ORDER BY'] = 'rev_count DESC';
		$sqlMostRevs = $dbr->selectSQLText( ['r' => 'revision'], $vars, $conds, __METHOD__, $options);

		$tables = ['r' => 'revision', 's' => 'revision' ];
		$vars = array(
			'X' => 'r.rev_user',
			'size_diff' => 'SUM( @a := CAST( r.rev_len AS SIGNED ) - CAST( s.rev_len AS SIGNED ) )',
			'pos_diff' => 'SUM( CASE WHEN @a >0 THEN @a ELSE 0 END )',
			'neg_diff' => 'SUM( CASE WHEN @a <0 THEN @a ELSE 0 END )',
		);
		$options['ORDER BY'] = 'size_diff DESC';
		$joins = array(
			's' => array( 'JOIN', 's.rev_id = r.rev_parent_id' )
		);
		$sqlDiffSizes = $dbr->selectSQLText($tables, $vars, $conds, __METHOD__, $options, $joins);

		$vars = array( 'user_id', 'user_name', 'page_count', 'rev_count', 'size_diff', 'pos_diff', 'neg_diff' );
		$vars['wiki_rank'] = '(page_count + SQRT(rev_count - page_count) * 2)';
		$options = [ 'ORDER BY' => 'wiki_rank DESC' ];
		if ($limit > 0)
		{
			$options['LIMIT'] = $limit;
		}
		$union = $dbr->unionQueries([ $sqlMostRevs, $sqlMostPages ], FALSE);
		$tables = array(
			'u' => 'user',
			's' => "({$union})",
			't' => "({$sqlDiffSizes})"
		);
		$joins = array(
			't' => array( 'INNER JOIN', 'user_id = X' ),
			's' => array( 'JOIN', 'user_id = rev_user' ),
		);

		$res = $dbr->select($tables, $vars, [], __METHOD__, $options, $joins);

		$sortable = in_array( 'nosort', $opts ) ? '' : ' sortable';

		$output = "<table class=\"wikitable contributionscores plainlinks{$sortable}\" >\n" .
			"<tr class='header'>\n" .
			Html::element( 'th', array(), $this->msg( 'contributionscores-rank' )->text() ) .
			Html::element( 'th', array(), $this->msg( 'contributionscores-score' )->text() ) .
			Html::element( 'th', array(), $this->msg( 'contributionscores-pages' )->text() ) .
			Html::element( 'th', array(), $this->msg( 'contributionscores-changes' )->text() ) .
			Html::element( 'th', array(), $this->msg( 'contributionscores-diff' ) ) .
			Html::element( 'th', array(), $this->msg( 'contributionscores-add' ) ) .
			Html::element( 'th', array(), $this->msg( 'contributionscores-sub' ) ) .
			Html::element( 'th', array('style' => 'width: 100%;'), $this->msg( 'contributionscores-username' )->text() );

		$altrow = '';
		$user_rank = 1;

		$lang = $this->getLanguage();
		foreach ( $res as $row ) {
			// Use real name if option used and real name present.
			if ( $wgContribScoresUseRealName && $row->user_real_name !== '' ) {
				$userLink = Linker::userLink(
					$row->user_id,
					$row->user_name,
					$row->user_real_name
				);
			} else {
				$userLink = Linker::userLink(
					$row->user_id,
					$row->user_name
				);
			}

			$output .= Html::closeElement( 'tr' );
			$output .= "<tr class='{$altrow}'>\n<td class='content' style='padding-right:10px;text-align:right;'>" .
				$lang->formatNum( round( $user_rank, 0 ) ) . "\n</td><td class='content' style='padding-right:10px;text-align:right;'>" .
				$lang->formatNum( round( $row->wiki_rank, 0 ) ) . "\n</td><td class='content' style='padding-right:10px;text-align:right;'>" .
				$lang->formatNum( $row->page_count ) . "\n</td><td class='content' style='padding-right:10px;text-align:right;'>" .
				$lang->formatNum( $row->rev_count ) . "\n</td><td class='content' style='padding-right:10px;text-align:right;'>" .
				$lang->formatNum( $row->size_diff ) . "\n</td><td class='content' style='padding-right:10px;text-align:right;'>" .
				$lang->formatNum( $row->pos_diff ) . "\n</td><td class='content' style='padding-right:10px;text-align:right;'>" .
				$lang->formatNum( $row->neg_diff ) . "\n</td><td class='content'>" .
				$userLink;

			# Option to not display user tools
			if ( !in_array( 'notools', $opts ) ) {
				$output .= Linker::userToolLinks( $row->user_id, $row->user_name );
			}

			$output .= Html::closeElement( 'td' ) . "\n";

			if ( $altrow == '' && empty( $sortable ) ) {
				$altrow = 'odd ';
			} else {
				$altrow = '';
			}

			$user_rank++;
		}
		$output .= Html::closeElement( 'tr' );
		$output .= Html::closeElement( 'table' );

		$dbr->freeResult( $res );

		if ( !empty( $title ) )
			$output = Html::rawElement( 'table',
				array(
					'style' => 'border-spacing: 0; padding: 0',
					'class' => 'contributionscores-wrapper',
					'lang' => htmlspecialchars( $lang->getCode()),
					'dir' => $lang->getDir()
				),
				"\n" .
					"<tr>\n" .
					"<td style='padding: 0px;'>{$title}</td>\n" .
					"</tr>\n" .
					"<tr>\n" .
					"<td style='padding: 0px;'>{$output}</td>\n" .
					"</tr>\n"
			);

		return $output;
	}

	function execute( $par ) {
		$this->setHeaders();

		if ( $this->including() ) {
			$this->showInclude( $par );
		} else {
			$this->showPage();
		}

		return true;
	}

	/**
	 * Called when being included on a normal wiki page.
	 * Cache is disabled so it can depend on the user language.
	 * @param $par
	 */
	function showInclude( $par ) {
		$days = null;
		$limit = null;
		$options = 'none';

		if ( !empty( $par ) ) {
			$params = explode( '/', $par );

			$limit = intval( $params[0] );

			if ( isset( $params[1] ) ) {
				$days = intval( $params[1] );
			}

			if ( isset( $params[2] ) ) {
				$options = $params[2];
			}
		}

		if ( empty( $limit ) || $limit < 1 || $limit > CONTRIBUTIONSCORES_MAXINCLUDELIMIT ) {
			$limit = 10;
		}
		if ( is_null( $days ) || $days < 0 ) {
			$days = 7;
		}

		if (stripos($options, 'notitle') === FALSE)
		{
			if ( $days > 0 ) {
				$reportTitle = $this->msg( 'contributionscores-days' )->numParams( $days )->text();
			} else {
				$reportTitle = $this->msg( 'contributionscores-allrevisions' )->text();
			}
			if ($limit > 0) {
				$reportTitle .= " " . $this->msg( 'contributionscores-top' )->numParams( $limit )->text();
			}
			$title = Xml::element( 'h4', array( 'class' => 'contributionscores-title' ), $reportTitle ) . "\n";
		}

		$this->getOutput()->addHTML( $this->genContributionScoreTable( $days, $limit, $title, $options ) );
	}

	/**
	 * Show the special page
	 */
	function showPage() {
		global $wgContribScoreReports;

		if ( !is_array( $wgContribScoreReports ) ) {
			$wgContribScoreReports = array(
				array( 7, -1 ),
				array( 30, -1 ),
				array( 0, 50 )
			);
		}

		$out = $this->getOutput();
		$out->addWikiMsg( 'contributionscores-info' );

		foreach ( $wgContribScoreReports as $scoreReport ) {
			list( $days, $revs ) = $scoreReport;
			if ( $days > 0 ) {
				$reportTitle = $this->msg( 'contributionscores-days' )->numParams( $days )->text();
			} else {
				$reportTitle = $this->msg( 'contributionscores-allrevisions' )->text();
			}
			if ($revs > 0) {
				$reportTitle .= " " . $this->msg( 'contributionscores-top' )->numParams( $revs )->text();
			}
			$title = Xml::element( 'h2', array( 'class' => 'contributionscores-title' ), $reportTitle ) . "\n";
			$out->addHTML( $title );
			$out->addHTML( $this->genContributionScoreTable( $days, $revs ) );
		}
	}
}
