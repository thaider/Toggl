<?php

namespace MediaWiki\Extension\Toggl;

/**
 * Hooks for Toggl
 *
 * @file
 * @ingroup Extensions
 */
class Hooks {
	protected static $report_summary = [];

	static function onGetPreferences( $user, &$preferences ) {
		$preferences['toggl-apikey'] = array(
			'type' => 'text',
			'label-message' => 'toggl-apikey',
			'section' => 'personal/toggl',
			'help-message' => 'toggl-apikey-help'
		);
		return true;
	}


	static function onParserFirstCallInit( \Parser &$parser ) {
		$parser->setFunctionHook( 'toggl-workspaces', [ self::class, 'workspaces' ] );
		$parser->setFunctionHook( 'toggl-workspace-users', [ self::class, 'workspaceUsers' ] );
		$parser->setFunctionHook( 'toggl-workspace-clients', [ self::class, 'workspaceClients' ] );
		$parser->setFunctionHook( 'toggl-workspace-projects', [ self::class, 'workspaceProjects' ] );
		$parser->setFunctionHook( 'toggl-report-summary', [ self::class, 'reportSummary' ] );
		$parser->setFunctionHook( 'toggl-report-summary-hours', [ self::class, 'reportSummaryHours' ] );
		return true;
	}
 

	/*
	 * Send call to RESTful API
	 *
	 * @param string $url
	 * @param array $params
	 *
	 * @return array code, response
	 *
	 * TODO: check for availability of cURL
	 * TODO: check for availability of API token
	 */
	static function callAPI( $url, $params = array() ) {
		$curl = curl_init();
		$url .= '?' . http_build_query( $params );

		$userpwd = $GLOBALS['wgUser']->getOption('toggl-apikey') . ':api_token';

		curl_setopt( $curl, CURLOPT_URL, $url );
		curl_setopt( $curl, CURLOPT_USERPWD, $userpwd );
		curl_setopt( $curl, CURLOPT_RETURNTRANSFER, true );
		$response = json_decode( curl_exec( $curl ) );
		$code = curl_getinfo( $curl, CURLINFO_HTTP_CODE );
		curl_close( $curl );
		return array( $code, $response );
	}


	/**
	 * Send call to toggl API
	 *
	 * @param string $type query type
	 * @param array $params
	 */
	static function callTogglAPI( $type, $params = array() ) {
		$url = 'https://toggl.com/api/v8/' . $type;
		return self::callAPI( $url, $params );
	}


	/**
	 * Send call to reports API
	 *
	 * @param string $type Report type (weekly/details/summary)
	 * @param array $params
	 */
	static function callReportAPI( $type, $params = array() ) {
		$url = 'https://toggl.com/reports/api/v2/' . $type;
		return self::callAPI( $url, $params );
	}


	/**
	 * Show error message
	 *
	 * @param string $msg System message to be used
	 * @param $params Parameters for the system message
	 */
	static function errorMsg( $msg, $params = '' ) {
		$error = wfMessage( $msg )
			->params( $params )
			->inContentLanguage()
			->parse();
		$error = '<div class="op-error alert alert-danger">' . $error . '</div>';
		return $error;
	}


	/**
	 * Get report
	 */
	static function getReportSummary($params) {
		$hash = md5( json_encode( $params ) );
		// already in the cash?
		if( isset( self::$report_summary[$hash] ) ) {
			return array( '200', self::$report_summary[$hash] );
		}
		
		list( $code, $response ) = self::callReportAPI( 'summary', $params );
		if( $code == '200' ) {
			self::$report_summary[$hash] = $response;
		}
		return array( $code, $response );	
	}

	/**
	 * Get hours
	 */
	static function reportSummaryHours( \Parser $parser ) {
		$parser->getOutput()->updateCacheExpiry(0);
		$params = self::extractOptions( array_slice(func_get_args(), 1 ) );
		$params['user_agent'] = $GLOBALS['wgEmergencyContact'];
		$params['grouping'] = $params['grouping'] ?? 'users';
		$params['subgrouping'] = $params['subgrouping'] ?? 'clients';
		$params['grouping_ids'] = true;
		$params['subgrouping_ids'] = true;

		if( !isset( $params['workspace_id'] ) ) {
			if( isset( $GLOBALS['wgTogglWorkspaceID'] ) && $GLOBALS['wgTogglWorkspaceID'] ) {
				$params['workspace_id'] = $GLOBALS['wgTogglWorkspaceID'];
			} else {
				return self::errorMsg( 'toggl-error-workspace-id' );
			}
		}

		list( $code, $response ) = self::getReportSummary( array_intersect_key($params, array_flip([
			'user_agent',
			'workspace_id',
			'grouping',
			'subgrouping',
			'grouping_ids',
			'subgrouping_ids',
			'since',
			'until'
		])) );

		if( $code != '200' ) {
			return self::errorMsg( 'toggl-response-error', $code );
		}

		$filter['group'] = $params[substr( $params['grouping'], 0, -1 ) . '_id'] ?? false;
		$filter['subgroup'] = $params[ substr( $params['subgrouping'], 0, -1 ) . '_id'] ?? false;

		// return total if no value for the group or subgroup has been set
		if( !$filter['group'] && !$filter['subgroup'] ) {
			return round( $response->total_grand / ( 3600 * 1000 ), 2 );
		} else {
			foreach( $response->data as $group ) {

				// group filter has been set
				if( $group->id == $filter['group'] ) {
					if( $filter['subgroup'] == false ) { 
						return round( $group->time / ( 3600 * 1000 ), 2 );

					// subgroup filter has been set
					} else {
						foreach( $group->items as $subgroup ) {
							if( $subgroup->ids == $filter['subgroup'] ) {
								return round( $subgroup->time / ( 3600 * 1000 ), 2 );
							}
						}
					}
				}
			}
		}
		return 0;
	}

	/**
	 * Get report
	 */
	static function reportSummary( \Parser $parser ) {
		$parser->getOutput()->updateCacheExpiry(0);
		$params = self::extractOptions( array_slice(func_get_args(), 1 ) );
		$params['user_agent'] = $GLOBALS['wgEmergencyContact'];

		if( !isset( $params['workspace_id'] ) ) {
			if( isset( $GLOBALS['wgTogglWorkspaceID'] ) && $GLOBALS['wgTogglWorkspaceID'] ) {
				$params['workspace_id'] = $GLOBALS['wgTogglWorkspaceID'];
			} else {
				return self::errorMsg( 'toggl-error-workspace-id' );
			}
		}

		list( $code, $response ) = self::getReportSummary( $params );

		if( $code != '200' ) {
			return self::errorMsg( 'toggl-response-error', $code );
		}

		$output = '<ul>';
		usort( $response->data, function ($a, $b) { return -( $a->time <=> $b->time ); });
		foreach( $response->data as $group ) {
			$output .= '<li>';
			switch( isset( $params['grouping'] ) ? $params['grouping'] : 'projects' ) {
				case 'clients':
					$output .= $group->title->client;
					break;
				case 'users':
					$output .= $group->title->user;
					break;
				case 'projects':
				default:
					$output .= $group->title->project . ' - ' . $group->title->client;
					break;
			}
			$output .= ' <small>(' . round( $group->time / (3600*1000) ) . 'h)</small>: ';
			//$output .= '<ul>';
				usort( $group->items, function( $a, $b ) { return -( $a->time <=> $b->time ); });
				foreach( $group->items as $item ) {
					// $output .= '<li>';
					// $output .= round( $item->time / (3600*1000), 2 ) . ' h' . ' - ';
					switch( isset( $params['subgrouping'] ) ? $params['subgrouping'] : 'time_entries' ) {
						case 'projects':
							$output .= $item->title->project . ' - ' . $item->title->client;
							break;
						case 'clients':
							$output .= $item->title->client;
							break;
						case 'users':
							$output .= $item->title->user;
							break;
						case 'tasks':
							$output .= $item->title->task;
							break;
						case 'time_entries':
						default:
							$output .= $item->title->time_entry;
							break;
					}
					// $output .= '</li>';
					$output = trim( $output, ', ' ) . ', ';
				}
			//$output .= '</ul>';
			$output = trim( $output, ', ' );
			$output .= '</li>';
		}
		$output .= '</ul>';

		return $output;
	}


	/*
	 * Get workspaces
	 *
	 */
	static function workspaces( \Parser $parser ) {
		$parser->getOutput()->updateCacheExpiry(0);

		$params = array();
		list( $code, $response ) = self::callTogglAPI( 'workspaces' );

		if( $code != '200' ) {
			return '<div class="op-error">' . wfMessage( 'toggl-response-error' )->params( $code )->inContentLanguage()->parse() . '</div>';
		}

		$output = '<ul>';
		foreach( $response as $workspace ) {
			$output .= '<li>' . $workspace->id . ': ' . $workspace->name . '</li>';
		}
		$output .= '</ul>';
		
		return array( $output, 'noparse' => true, 'isHTML' => true );
	}

	/*
	 * Get users
	 *
	 */
	static function workspaceUsers( \Parser $parser ) {
		$parser->getOutput()->updateCacheExpiry(0);

		$params = self::extractOptions( array_slice(func_get_args(), 1 ) );
		if( !isset( $params['workspace_id'] ) ) {
			if( isset( $GLOBALS['wgTogglWorkspaceID'] ) && $GLOBALS['wgTogglWorkspaceID'] ) {
				$workspace_id = $GLOBALS['wgTogglWorkspaceID'];
			} else {
				return self::errorMsg( 'toggl-error-workspace-id' );
			}
		} else {
			$workspace_id = $params['workspace_id'];
			unset( $params['workspace_id'] );
		}

		list( $code, $response ) = self::callTogglAPI( 'workspaces/' . $workspace_id . '/users', $params );

		if( $code != '200' ) {
			return '<div class="op-error">' . wfMessage( 'toggl-response-error' )->params( $code )->inContentLanguage()->parse() . '</div>';
		}

		$output = '<ul>';
		foreach( $response as $user ) {
			$output .= '<li>' . $user->id . ': ' . $user->fullname . '</li>';
		}
		$output .= '</ul>';
		
		return array( $output, 'noparse' => true, 'isHTML' => true );
	}

	/*
	 * Get clients
	 *
	 */
	static function workspaceClients( \Parser $parser ) {
		$parser->getOutput()->updateCacheExpiry(0);

		$params = self::extractOptions( array_slice(func_get_args(), 1 ) );
		if( !isset( $params['workspace_id'] ) ) {
			if( isset( $GLOBALS['wgTogglWorkspaceID'] ) && $GLOBALS['wgTogglWorkspaceID'] ) {
				$workspace_id = $GLOBALS['wgTogglWorkspaceID'];
			} else {
				return self::errorMsg( 'toggl-error-workspace-id' );
			}
		} else {
			$workspace_id = $params['workspace_id'];
			unset( $params['workspace_id'] );
		}

		list( $code, $response ) = self::callTogglAPI( 'workspaces/' . $workspace_id . '/clients', $params );

		if( $code != '200' ) {
			return '<div class="op-error">' . wfMessage( 'toggl-response-error' )->params( $code )->inContentLanguage()->parse() . '</div>';
		}

		$output = '<ul>';
		foreach( $response as $client ) {
			$output .= '<li>' . $client->id . ': ' . $client->name . '</li>';
		}
		$output .= '</ul>';
		
		return array( $output, 'noparse' => true, 'isHTML' => true );
	}

	/*
	 * Get projects
	 *
	 */
	static function workspaceProjects( \Parser $parser ) {
		$parser->getOutput()->updateCacheExpiry(0);

		$params = self::extractOptions( array_slice(func_get_args(), 1 ) );
		if( !isset( $params['workspace_id'] ) ) {
			if( isset( $GLOBALS['wgTogglWorkspaceID'] ) && $GLOBALS['wgTogglWorkspaceID'] ) {
				$workspace_id = $GLOBALS['wgTogglWorkspaceID'];
			} else {
				return self::errorMsg( 'toggl-error-workspace-id' );
			}
		} else {
			$workspace_id = $params['workspace_id'];
			unset( $params['workspace_id'] );
		}

		list( $code, $response ) = self::callTogglAPI( 'workspaces/' . $workspace_id . '/projects', $params );

		if( $code != '200' ) {
			return '<div class="op-error">' . wfMessage( 'toggl-response-error' )->params( $code )->inContentLanguage()->parse() . '</div>';
		}

		$output = '<ul>';
		foreach( $response as $project ) {
			$output .= '<li>' . $project->id . ': ' . $project->name . '</li>';
		}
		$output .= '</ul>';
		
		return array( $output, 'noparse' => true, 'isHTML' => true );
	}

	/**
	 * Converts an array of values in form [0] => "name=value" into a real
	 * associative array in form [name] => value. If no = is provided,
	 * true is assumed like this: [name] => true
	 *
	 * @param array string $options
	 * @return array $results
	 */
	static function extractOptions( array $options ) {
		$results = array();

		foreach ( $options as $option ) {
			$pair = explode( '=', $option, 2 );
			if ( count( $pair ) === 2 ) {
				$name = trim( $pair[0] );
				$value = trim( $pair[1] );
				$results[$name] = $value;
			}

			if ( count( $pair ) === 1 ) {
				$name = trim( $pair[0] );
				$results[$name] = true;
			}
		}

		return $results;
	}
}
