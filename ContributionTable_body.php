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
 * @author Joe ST <joe@fbstj.net>
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
	 * @param $includeUserNamespace bool Whether to include edits on pages in the user namespace (default false)
	 * @return table of data
	 */
	function GetContribs( $days, $limit = 50, $includeUserNamespace = false) {

		$dbr = wfGetDB( DB_REPLICA );

		# the 'diffs' query maps rev_id to diff size
		$diffs = $dbr->selectSQLText(
			[ 'r' => 'revision', 's' => 'revision', ],
			[
				'diff_id' => 'r.rev_id',
				'diff_size' => '( CAST( r.rev_len AS SIGNED ) - IFNULL( CAST( s.rev_len AS SIGNED ), 0 ) )',
			],
			[], __METHOD__, [],
			[ 's' => [ 'LEFT JOIN', 's.rev_id = r.rev_parent_id', ], ]
		);

		# most of this query is provided by getQueryInfo
		$revs = Revision::getQueryInfo();
		# wire up the 'diffs' query to it
		$revs['tables']['diffs'] = new Wikimedia\Rdbms\Subquery($diffs);
		$revs['tables']['pages'] = 'page';
		$revs['joins']['diffs'] = ['JOIN', 'diff_id = rev_id'];
		$revs['joins']['pages'] = ['JOIN', 'page_id = rev_page'];
		$revs['fields'][] = 'diffs.diff_size';
		# Exclude pages in talk, user and site namespaces, unless user should explicitly be included
		$excludedNamespaces = [3002];
		if (!$includeUserNamespace) {
			$excludedNamespaces[] = 2;
		}
		$revs['conds'] = [
			'MOD(pages.page_namespace, 2) = 0',
			sprintf('pages.page_namespace NOT IN (%s)', implode(',', $excludedNamespaces))
		];
		$revs['order'] = [];
		# configure the @days limits
		if ($days > 1) {
			$date = time() - (60 * 60 * 24 * $days);
			$date = $dbr->timestamp($date);
			$revs['conds'][] = "rev_timestamp > $date";
		}

		# make the query out of it
		$revs = $dbr->selectSQLText(
			$revs['tables'],
			$revs['fields'],
			$revs['conds'],
			__METHOD__,
			$revs['order'],
			$revs['joins']
		);

		# create parts of the outer query
		$tables = [
			'revs' => new Wikimedia\Rdbms\Subquery($revs)
		];
		$fields = [
			'user_id' => 'rev_user',
			# this column is not unique :|
			'user_name' => 'MAX(rev_user_text)',
			# all pages this user has edited
			'page_count' => 'COUNT( DISTINCT rev_page )',
			# all revisions this user has made
			'edit_count' => 'COUNT( rev_id )',
			# the total size of their contributions
			'diff_len' => 'SUM( diff_size )',
			# the additions they've made
			'diff_add' => 'SUM( CASE WHEN diff_size >0 THEN diff_size ELSE 0 END )',
			# the removals they've made
			'diff_sub' => 'SUM( CASE WHEN diff_size <0 THEN diff_size ELSE 0 END )',
		];
		$conds = [];
		$order = [];
		$joins = [];
		# grouping by user
		$order['GROUP BY'] = 'rev_user';
		# sort by edit count
		$order['ORDER BY'] = 'edit_count DESC';
		# hide anons
		$conds[] = 'rev_user IS NOT null';
		# limit to this number of total rows
		if ($limit > 1) { $order['LIMIT'] = $limit; }

		return $dbr->select(
			$tables, $fields, $conds, __METHOD__, $order, $joins
		);
	}

	/// Generates a Contribution table for a given LIMIT and date range
	/**
	 * Function generates Contribution tables in HTML format (not wikiText)
	 *
	 * @param $days int Days in the past to run report for
	 * @param $limit int Maximum number of users to return (default 50)
	 * @param $title Title (default null)
	 * @param $options array of options (default none; nosort/notools)
	 * @param $includeUserNamespace bool Whether to include edits on pages in the user namespace (default false)
	 * @return Html Table representing the requested Contribution Table.
	 */
	function genContributionScoreTable( $days, $limit = 50, $title = null, $options = 'none', $includeUserNamespace = false ) {
		$opts = explode( ',', strtolower( $options ) );

		$res = $this->GetContribs($days, $limit, $includeUserNamespace);

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
			$userLink = Linker::userLink(
				$row->user_id,
				$row->user_name
			);
			# Option to not display user tools
			if ( !in_array( 'notools', $opts ) ) {
				$userLink .= Linker::userToolLinks( $row->user_id, $row->user_name );
			}

			// construct row
			$attr = [ 'style' => 'padding-right: 10px; text-align: right;' ];
			$output .= Html::rawElement('tr', ['class' => "{$altrow}"],
				Html::element('td', $attr, $lang->formatNum( $row->edit_count ) ) .
				Html::element('td', $attr, $lang->formatNum( $row->page_count ) ) .
				Html::element('td', $attr, $lang->formatNum( $row->diff_len ) ) .
				Html::element('td', $attr, $lang->formatNum( $row->diff_add ) ) .
				Html::element('td', $attr, $lang->formatNum( $row->diff_sub ) ) .
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

		if ( empty( $limit ) || $limit < 1) {
			$limit = 10;
		}
		if ( is_null( $days ) || $days < 0 ) {
			$days = 7;
		}

		$title = '';
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

		$includeUserNamespace = $days > 0;

		$this->getOutput()->addHTML( $this->genContributionScoreTable( $days, $limit, $title, $options, $includeUserNamespace ) );
	}

	/**
	 * Show the special page
	 */
	function showPage() {
		global $wgContribScoreReports;

		$out = $this->getOutput();
		if ( is_null($wgContribScoreReports) ||  !is_array( $wgContribScoreReports ) || empty( $wgContribScoreReports ) ) {
			$out->addWikiMsg( 'contributiontable-reporterror' );
			return;
		}

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

			$includeUserNamespace = $days > 0;

			$out->addHTML( $this->genContributionScoreTable( $days, $limit, null, 'none', $includeUserNamespace ) );
		}
	}

	protected function getGroupName() {
		return 'wiki';
	}
}
