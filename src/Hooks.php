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
	protected static $clients = [];

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
	 * @param string $method
	 *
	 * @return array code, response
	 */
	static function callAPI( $url, $params = array(), $method='GET' ) {
		$userpwd = $GLOBALS['wgUser']->getOption('toggl-apikey') . ':api_token';

		$curl = curl_init();
		if( $method == 'GET' ) {
			$url  .= '?' . http_build_query( $params );
		} else {
			curl_setopt( $curl, CURLOPT_POST, true );
			curl_setopt( $curl, CURLOPT_POSTFIELDS, json_encode( $params ) );
		}

		curl_setopt( $curl, CURLOPT_URL, $url );
		curl_setopt( $curl, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
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
		$url = 'https://api.track.toggl.com/api/v9/' . $type;
		$cache_key = $type . '-' . implode('-',$params);
		if( apcu_exists( $cache_key ) ) {
			$data = apcu_fetch( $cache_key );
		} else {
			$data = self::callAPI( $url, $params );
			apcu_store( $cache_key, $data, 60*5);
		}
		return $data;
	}


	/**
	 * Send call to reports API
	 *
	 * @param string $type Report type (weekly/details/summary)
	 * @param array $params
	 * @param string $method
	 */
	static function callReportAPI( $type, $params = array(), $method='GET' ) {
		$url = 'https://api.track.toggl.com/reports/api/v3/' . $type;
		$cache_key = implode('-',$params);
		if( apcu_exists( $cache_key ) ) {
			$report_data = apcu_fetch( $cache_key );
		} else {
			$report_data = self::callAPI( $url, $params, $method );
			apcu_store( $cache_key, $report_data, 60*5);
		}
		return $report_data;
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
		$error = '<div class="toggl-error alert alert-danger">' . $error . '</div>';
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
		
		list( $code, $response ) = self::callReportAPI( 'workspace/' . $params['workspace_id'] . '/summary/time_entries', $params, 'POST' );
		if( $code == '200' ) {
			self::$report_summary[$hash] = $response;
		}
		return array( $code, $response );	
	}


	/**
	 * Get hours
	 *
	 * @return Float Total hours
	 */
	static function reportSummaryHours( \Parser $parser ) {
		$parser->getOutput()->updateCacheExpiry(0);
		$params = self::extractOptions( array_slice(func_get_args(), 1 ) );
		//$params['user_agent'] = $GLOBALS['wgEmergencyContact'];

		// backwards compatibility
		$aliases = [
			'start_date' => 'since',
			'end_date' => 'until',
			'sub_grouping' => 'subgrouping'
		];
		foreach( $aliases as $new => $old ) {
			if( !isset( $params[$new] ) && isset( $params[$old] ) ) {
				$params[$new] = $params[$old];
			}
		}

		if( !isset( $params['grouping'] ) && ( !isset( $params['sub_grouping'] ) || $params['sub_grouping'] != 'users' ) ) {
			$params['grouping'] = 'users';
		}
		if( !isset( $params['sub_grouping'] ) && ( !isset( $params['grouping'] ) || $params['grouping'] != 'clients' ) ) {
			$params['sub_grouping'] = 'clients';
		}

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
			'sub_grouping',
			'start_date',
			'end_date'
		])) );

		if( $code != '200' ) {
			return self::errorMsg( 'toggl-response-error', [ $code, $response ] );
		}

		$filter['group'] = $params[substr( $params['grouping'], 0, -1 ) . '_id'] ?? false;
		$filter['subgroup'] = $params[ substr( $params['sub_grouping'], 0, -1 ) . '_id'] ?? false;

		// count total working hours
		$seconds = 0;
		if( !$response->groups ) {
			return 0;
		}
		foreach( $response->groups as $group ) {
			if( $filter['group'] && $filter['group'] != $group->id ) {
				continue;
			}
			foreach( $group->sub_groups as $subgroup ) {
				if( $filter['subgroup'] && $filter['subgroup'] != $subgroup->id ) {
					continue;
				}
				$seconds += $subgroup->seconds;
			}
		}

		return round( $seconds / (3600), 2 );
	}


	/**
	 * Get report
	 *
	 * @return String Summary as unordered list
	 */
	static function reportSummary( \Parser $parser ) {
		$parser->getOutput()->updateCacheExpiry(0);
		$params = self::extractOptions( array_slice(func_get_args(), 1 ) );
		//$params['user_agent'] = $GLOBALS['wgEmergencyContact'];

		// backwards compatibility
		$aliases = [
			'start_date' => 'since',
			'end_date' => 'until',
			'sub_grouping' => 'subgrouping'
		];
		foreach( $aliases as $new => $old ) {
			if( !isset( $params[$new] ) && isset( $params[$old] ) ) {
				$params[$new] = $params[$old];
			}
		}

		if( !isset( $params['workspace_id'] ) ) {
			if( isset( $GLOBALS['wgTogglWorkspaceID'] ) && $GLOBALS['wgTogglWorkspaceID'] ) {
				$params['workspace_id'] = $GLOBALS['wgTogglWorkspaceID'];
			} else {
				return self::errorMsg( 'toggl-error-workspace-id' );
			}
		}

		if( isset( $params['user_ids'] ) ) {
			$params['user_ids'] = explode( ',', $params['user_ids'] );
			foreach( $params['user_ids'] as $key => $id ) {
				$params['user_ids'][$key] = (int) $id;
			}
		}

		list( $code, $response ) = self::getReportSummary( array_intersect_key($params, array_flip([
			'user_agent',
			'workspace_id',
			'grouping',
			'sub_grouping',
			'start_date',
			'end_date',
			'user_ids',
		])) );

		if( $code != '200' ) {
			return self::errorMsg( 'toggl-response-error', [ $code, $response ] );
		}

		$output = '<ul>';
		//usort( $response->data, function ($a, $b) { return -( $a->time <=> $b->time ); });
		foreach( $response->groups as $group ) {
			$output .= '<li>';
			switch( isset( $params['grouping'] ) ? $params['grouping'] : 'projects' ) {
				case 'clients':
					$output .= self::getClients( $params['workspace_id'] )[$group->id]->name;
					break;
				case 'users':
					$output .= $group->id;
					break;
				case 'projects':
				default:
					$output .= $group->title->project . ' - ' . $group->title->client;
					break;
			}
			$seconds = 0;
			foreach( $group->sub_groups as $item ) {
				$seconds += $item->seconds;
			}
			$output .= ' <small>(' . round( $seconds / (3600) ) . 'h)</small>: ';
			//$output .= '<ul>';
				//usort( $group->items, function( $a, $b ) { return -( $a->time <=> $b->time ); });
				foreach( $group->sub_groups as $item ) {
					$output .= $item->title;
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
			return self::errorMsg( 'toggl-response-error', $code );
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
			return self::errorMsg( 'toggl-response-error', $code );
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

		$clients = self::getClients( $workspace_id, $params );

		$output = '<ul>';
		foreach( $clients as $client ) {
			$output .= '<li>' . $client->id . ': ' . $client->name . '</li>';
		}
		$output .= '</ul>';
		
		return array( $output, 'noparse' => true, 'isHTML' => true );
	}


	/**
	 * Get clients from API
	 *
	 * @param Integer $workspace_id
	 * @param Array $params
	 *
	 * @return Array Clients
	 */
	static function getClients( $workspace_id, $params = [] ) {
		$hash = md5( $workspace_id . json_encode( $params ) );
		// already in the cash?
		if( isset( self::$clients[$hash] ) ) {
			return self::$clients[$hash];
		}
		list( $code, $response ) = self::callTogglAPI( 'workspaces/' . $workspace_id . '/clients', $params );

		if( $code != '200' ) {
			return [];
		} else {
			$clients = [];
			foreach( $response as $client ) {
				$clients[$client->id] = $client;
			}
			self::$clients[$hash] = $clients;
			return $clients;
		}
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
			return self::errorMessage( 'toggl-response-error', $code );
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
