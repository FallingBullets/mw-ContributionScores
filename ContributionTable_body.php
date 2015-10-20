<?php
/** \file
* \brief Contains code for the ContributionTable Class (extends SpecialPage).
*/

/// Special page class for the Contribution Table extension
/**
 * Special page that generates a list of wiki contributors based
 * on edit diversity (unique pages edited) and edit volume (total
 * number of edits.
 *
 * @ingroup Extensions
 * @author Tim Laqua <t.laqua@gmail.com>
 */
class ContributionTable extends IncludableSpecialPage {
	public function __construct() {
		parent::__construct( 'ContributionTable' );
	}

	/**
	 * Collects records within passed limits
	 *
	 * @param $days int Days in the past to run report for
	 * @param $limit int Maximum number of users to return (default 50)
	 * @param $ignore_blocked ignore blocked users
	 * @param $ignore_bots ignore bot accounts
	 * @return table of data
	 */
	function GetContribs( $days, $limit = 50, $ignore_blocked = false, $ignore_bots = false ) {

		$dbr = wfGetDB( DB_SLAVE );

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
			$conds[] = "r.rev_timestamp > '{$dateString}'";
		}

		if ( $ignore_blocked ) {
			$ipBlocksTable = $dbr->tableName( 'ipblocks' );
			$conds[] = "r.rev_user NOT IN (SELECT ipb_user FROM {$ipBlocksTable} WHERE ipb_user <> 0)";
		}

		if ( $ignore_bots ) {
			$userGroupTable = $dbr->tableName( 'user_groups' );
			$conds[] = "r.rev_user NOT IN (SELECT ug_user FROM {$userGroupTable} WHERE ug_group='bot')";
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
		$options = [ 'ORDER BY' => 'rev_count DESC' ];
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

		return $dbr->select($tables, $vars, [], __METHOD__, $options, $joins);
	}

	/// Generates a Contribution table for a given LIMIT and date range
	/**
	 * Function generates Contribution tables in HTML format (not wikiText)
	 *
	 * @param $days int Days in the past to run report for
	 * @param $limit int Maximum number of users to return (default 50)
	 * @param $title Title (default null)
	 * @param $options array of options (default none; nosort/notools)
	 * @return Html Table representing the requested Contribution Table.
	 */
	function genContributionScoreTable( $days, $limit = 50, $title = null, $options = 'none' ) {
		global $wgContribScoreIgnoreBots, $wgContribScoreIgnoreBlockedUsers, $wgContribScoresUseRealName;

		$opts = explode( ',', strtolower( $options ) );

		$res = $this->GetContribs($days, $limit, $wgContribScoreIgnoreBlockedUsers, $wgContribScoreIgnoreBots);

		$sortable = in_array( 'nosort', $opts ) ? '' : 'sortable';

		$output = Html::openElement('table', [ 'class' => "wikitable contributiontable plainlinks {$sortable}" ]);
		// construct row
		$row = Html::rawElement('tr', ['header'],
			Html::element('th', [], $this->msg( 'contributiontable-changes' ) ) .
			Html::element('th', [], $this->msg( 'contributiontable-pages' ) ) .
			Html::element('th', [], $this->msg( 'contributiontable-diff' ) ) .
			Html::element('th', [], $this->msg( 'contributiontable-add' ) ) .
			Html::element('th', [], $this->msg( 'contributiontable-sub' ) ) .
			Html::element('th', ['style' => 'width: 100%;'], $this->msg( 'contributiontable-username' ) )
			);
		$output .= $row;

		$altrow = '';

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
			# Option to not display user tools
			if ( !in_array( 'notools', $opts ) ) {
				$userLink .= Linker::userToolLinks( $row->user_id, $row->user_name );
			}

			// construct row
			$attr = [ 'style' => 'padding-right: 10px; text-align: right;' ];
			$output .= Html::rawElement('tr', ['class' => "{$altrow}"],
				Html::element('td', $attr, $lang->formatNum( $row->rev_count ) ) .
				Html::element('td', $attr, $lang->formatNum( $row->page_count ) ) .
				Html::element('td', $attr, $lang->formatNum( $row->size_diff ) ) .
				Html::element('td', $attr, $lang->formatNum( $row->pos_diff ) ) .
				Html::element('td', $attr, $lang->formatNum( $row->neg_diff ) ) .
				Html::rawElement('td', [], $userLink )
				);

			if ( $altrow == '' && empty( $sortable ) ) {
				$altrow = 'odd';
			} else {
				$altrow = '';
			}
		}
		$output .= Html::closeElement( 'table' );

		if ( empty( $title ) )
			return $output;
		// wrap in 'titled' table
		return Html::rawElement( 'table',
			array(
				'style' => 'border-spacing: 0; padding: 0',
				'class' => 'contributiontable-wrapper',
				'lang' => htmlspecialchars( $lang->getCode()),
				'dir' => $lang->getDir()
			),
			Html::rawElement('tr', [],
				Html::rawElement('td', [ 'style' => 'padding: 0px;'], $title)
				) .
			Html::rawElement('tr', [],
				Html::rawElement('td', [ 'style' => 'padding: 0px;'], $output)
				)
		);
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
				$title = $this->msg( 'contributiontable-days' )->numParams($days);
			} else {
				$title = $this->msg( 'contributiontable-allrevisions' );
			}
			if ($limit > 0) {
				$title .= " " . $this->msg( 'contributiontable-top' )->numParams($limit);
			}
			$title = Html::element('h4', array( 'class' => 'contributiontable-title' ), $title );
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
		$out->addWikiMsg( 'contributiontable-info' );

		foreach ( $wgContribScoreReports as $scoreReport ) {
			list( $days, $limit ) = $scoreReport;
			if ( $days > 0 ) {
				$title = $this->msg( 'contributiontable-days' )->numParams($days);
			} else {
				$title = $this->msg( 'contributiontable-allrevisions' );
			}
			if ($limit > 0) {
				$title .= " " . $this->msg( 'contributiontable-top' )->numParams($limit);
			}
			$title = Html::element('h2', array( 'class' => 'contributiontable-title' ), $title);
			$out->addHTML( $title );
			$out->addHTML( $this->genContributionScoreTable( $days, $limit ) );
		}
	}
}
