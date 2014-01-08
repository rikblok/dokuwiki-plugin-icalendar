<?php

/**
 * Plugin iCalendar: Renders an iCal .ics file into HTML.
 * 
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @version    1.4
 * @date       November 2011
 * @author     J. Drost-Tenfelde <info@drost-tenfelde.de>
 *
 * This plugin is based on the iCalEvents plugin by Robert Rackl <wiki@doogie.de>.
 *
 */

// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();
 
if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');
require_once(DOKU_PLUGIN.'syntax.php');

include_once(DOKU_INC.'lib/plugins/iCalendar/functions.php');
 
/**
 * This plugin gets an iCalendar file via HTTP and parses it into HTML.
 *
 * Usage: {{iCalendar>http://host/myCalendar.ics#from=today&previewDays=30}}
 * 
 * You can filter the events that are shown with two parametes:
 * 1. 'from' a date from which on to show events. MUST be in the format MM/dd/yyyy
 *           or you can simplay say "from=today".
 *           If from is ommited, then all events are shown.
 * 2. 'previewDays' amount of days to preview into the future.
 *
 * <code>from <= eventdate <= from+(previewDays*24*60*3600)</code>
 *
 * There are some more configuration settins in plugins/iCalendar/conf/default.php
 * 
 * @see http://de.wikipedia.org/wiki/ICalendar
 */
class syntax_plugin_iCalendar extends DokuWiki_Syntax_Plugin
{ 
    function getInfo() {
      return array(
        'author' => 'J. Drost-Tenfelde',
        'email'  => 'info@drost-tenfelde.de',
        'date'   => '2011-09-28',
        'name'   => 'iCalendar',
        'desc'   => 'Parses an iCalendar .ics file into HTML',
        'url'    => 'http://www.drost-tenfelde.de/?id=dokuwiki:plugins:icalendar',
      );
    }
 
    // implement necessary Dokuwiki_Syntax_Plugin methods
    function getType() { return 'substition'; }
    function getSort() { return 42; }
    function connectTo($mode) { $this->Lexer->addSpecialPattern('\{\{iCalendar>.*?\}\}',$mode,'plugin_iCalendar'); }
    
	/**
	 * parse parameters from the {{iCalendar>...}} tag.
	 * @return an array that will be passed to the renderer function
	 */
	function handle($match, $state, $pos, &$handler) {

		$match = substr($match, 13, -2); // strip {{iCalendar> from start and }} from end
		list($icsURL, $flagStr) = explode('#', $match);
		parse_str($flagStr, $params);

        // Get the from parameter
		if ($params['from'] == 'today') {
			$from = time();
		} else if (preg_match('#(\d\d)/(\d\d)/(\d\d\d\d)#', $params['from'], $fromDate)) {
			// must be MM/dd/yyyy
			$from = mktime(0, 0, 0, $fromDate[1], $fromDate[2], $fromDate[3]);
		} else if (preg_match('/\d+/', $params['from'])) {
			$from = $params['from']; 
		}
        // Get the to parameter
		if ($params['to'] == 'today') {
			$to = mktime(24, 0, 0, date("m") , date("d"), date("Y"));

		} else if (preg_match('#(\d\d)/(\d\d)/(\d\d\d\d)#', $params['to'], $toDate)) {
			// must be MM/dd/yyyy
			$to = mktime(0, 0, 0, $toDate[1], $toDate[2], $toDate[3]);
		} else if (preg_match('/\d+/', $params['to'])) {
			$to = $params['to']; 
		}
        
        // Get the numberOfEntries parameter
        if ($params['numberOfEntries']) {
			$numberOfEntries = $params['numberOfEntries'];
		} else {
			$numberOfEntries = -1;
      	}
      	
        // Get the show end dates parameter
		if ($params['showEndDates'] == 1 ) {
    		$showEndDates = true;
		} else {
			$showEndDates = false;
      	}
        // Get the show as list parameter
        if ( $params['showAs'] ) {
            $showAs = $params['showAs'];
        }
        else {
            $showAs = 'default';
        }
        
        // Get the showAs parameter (since v1.4)
		if ( $params['showAs'] ) {
            $showAs = $params['showAs'];  
		}
		else {
            // Backward compatibiltiy of v1.3 or earlier
            if ($params['showAsList'] == 1) {
                $showAs = 'list';
            } else {
                $showAs = 'default';
            }
        }
        // Get the appropriate template
        $template = $this->getConf($showAs);
        if ( !isset($template ) || $template == '' ) {
			$template = $this->getConf('default');
		}       
        
        // Find out if the events should be sorted in reserve
        $sort_descending = false;
        if ( $params['sort'] == 'DESC') {
            $sort_descending = true;
        }
        //echo $rsort;
        //exit( 0 );        
        
        // Get the previewDays parameter
        if ( $params['previewDays'] ) {
            $previewDays = $params['previewDays'];
        }
        else {
            $previewDays = -1;
        }
      
		#echo "url=$icsURL from = $from    numberOfEntries = $numberOfEntries<br>";
		return array($icsURL, $from, $to, $previewDays, $numberOfEntries, $showEndDates, $template, $sort_descending); 
	}
    
	/**
	 * loads the ics file via HTTP, parses it and renders an HTML table.
	 */
	function render($mode, &$renderer, $data) {
		list($url, $from, $to, $previewDays, $numberOfEntries, $showEndDates, $template, $sort_descending) = $data;
		$ret = ''.$mediadir;
        
		if ($mode == 'xhtml') {
			# parse the ICS file
			$entries = $this->_parseIcs($url, $from, $to, $previewDays, $numberOfEntries, $sort_descending);

			if ($this->error) {
				$renderer->doc .= "Error in Plugin iCalendar: ".$this->error;
				return true;
			}

			#loop over entries and create a table row for each one.
			$rowCount = 0;

			foreach ($entries as $entry) {
				$rowCount++;

				# Get the html for the entries
				$entryTemplate = $template;
				
				// {description}
				$entryTemplate = str_replace('{description}', $entry['description'], $entryTemplate );

                // {summary}
                $entryTemplate = str_replace('{summary}', $entry['summary'], $entryTemplate );
                
                // {summary_link}
                $summary_link = array();
                $summary_link['class']  = 'urlintern';
                $summary_link['style']  = 'background-image: url(lib/plugins/iCalendar/ics.png); background-repeat:no-repeat; padding-left:16px; text-decoration: none;';
                $summary_link['pre']    = '';
                $summary_link['suf']    = '';
                $summary_link['more']   = 'rel="nofollow"';
                $summary_link['target'] = '';               
                $summary_link['title']  = $entry['summary'];
                $summary_link['url']    = 'lib/plugins/iCalendar/vevent.php?vevent='.urlencode( $entry['vevent'] );
                $summary_link['name']  = $entry['summary'];       
                $entryTemplate = str_replace('{summary_link}', '<html>'.$renderer->_formatLink($summary_link).'</html>', $entryTemplate );    
                
                // See if a location was set
				$location = $entry['location'];
				if ( $location != '' ) {
                    // {location}
					$entryTemplate = str_replace('{location}', $location, $entryTemplate );
					
					// {location_link}
					$location_link = 'http://maps.google.com/maps?q='.str_replace(' ', '+', str_replace(',', ' ', $location));
					$entryTemplate = str_replace('{location_link}', '[['.$location_link.'|'.$location.']]', $entryTemplate );				
				}
				else {
				    // {location}
					$entryTemplate = str_replace('{location}', 'Unknown', $entryTemplate );
					// {location_link}
					$entryTemplate = str_replace('{location_link}', 'Unknown', $entryTemplate );
				}

				$dateString = "";

				// Get the start and end day
				$startDay = date("Ymd", $entry['startunixdate']);
				$endDay = date("Ymd", $entry['endunixdate']);

				if ( $endDay > $startDay )
				{
					if ( $entry['allday'] )
					{
						$dateString = $entry['startdate'].'-'.$entry['enddate'];
					}
					else {
						$dateString = $entry['startdate'].' '.$entry['starttime'].'-'.$entry['enddate'].' '.$entry['endtime'];
					}
				}
				else {
					if ( $showEndDates ) {
						if ( $entry['allday'] )
						{
							$dateString = $entry['startdate'];
						}
						else {
							$dateString = $entry['startdate'].' '.$entry['starttime'].'-'.$entry['endtime'];
						}
					}
					else {
						$dateString = $entry['startdate'];
					}
				}
				
				// {date}
				$entryTemplate = str_replace('{date}', $dateString, $entryTemplate );
				
				$ret .= $entryTemplate.'
';
                
			}
			//$renderer->doc .= $ret;
			$html = p_render($mode, p_get_instructions( $ret ), $info );
			$html = str_replace( '\\n', '<br />', $html );
			$renderer->doc .= $html;
			
			return true;
		}
		return false;
	}
    
	/**
	 * Load the iCalendar file from 'url' and parse all
	 * events that are within the range
	 * from <= eventdate <= from+previewSec
	 *
	 * @param url HTTP URL of an *.ics file
	 * @param from unix timestamp in seconds (may be null)
	 * @param to unix timestamp in seconds (may be null)
	 * @param previewDays Limit the entries to 30 days in the future	 
	 * @param numberOfEntries Number of entries to display
	 * @param $sort_descending	 
	 * @return an array of entries sorted by their startdate
	 */
	function _parseIcs($url, $from, $to, $previewDays, $numberOfEntries, $sort_descending ) {
	    global $conf;
	
		$http    = new DokuHTTPClient();
		if (!$http->get($url)) {
			$this->error = "Could not get '$url': ".$http->status;
			return array();
		}
		$content    = $http->resp_body;
		$entries    = array();
        
		# If dateformat is set in plugin configuration ('dformat'), then use it.
		# Otherwise fall back to dokuwiki's default dformat from the global /conf/dokuwiki.php.
		$dateFormat = $this->getConf('dformat') ? $this->getConf('dformat') : $conf['dformat'];	
		//$timeFormat = $this->getConf('tformat') ? $this->getConf('tformat') : $conf['tformat'];
				
		# regular expressions for items that we want to extract from the iCalendar file
		$regex_vevent      = '/BEGIN:VEVENT(.*?)END:VEVENT/s';
        
		#split the whole content into VEVENTs        
		preg_match_all($regex_vevent, $content, $matches, PREG_PATTERN_ORDER);

        if ( $previewDays > 0 )
        {
            $previewSec = $previewDays * 24 * 3600;
        }
        else {
            $previewSec = -1;
        }
                
		// loop over VEVENTs and parse out some itmes
		foreach ($matches[1] as $vevent) {
            $entry = parse_vevent( $vevent, $dateFormat );        
          
			// if entry is to old then filter it
			if ($from && $entry['endunixdate']) { 
				if ($entry['endunixdate'] < $from) { continue; }
				if (($previewSec > 0) && ($entry['startunixdate'] > time()+$previewSec)) { continue; }
			}

			// if entry is to new then filter it
			if ($to && $entry['startunixdate']) { 
				if ($entry['startunixdate'] > $to) { continue; } 
			}
  		
			$entries[] = $entry;
		}

		if ( $to && ($from == null) )
		{
			// sort entries by startunixdate
			usort($entries, 'compareByEndUnixDate');             
		} else if ( $from ) {
			// sort entries by startunixdate
			usort($entries, 'compareByStartUnixDate');
        }
        else if ( $sort_descending ) {
            $entries = array_reverse( $entries, true );
        }
        
		// See if a maximum number of entries was set
		if ( $numberOfEntries > 0 )
		{
            $entries = array_slice( $entries, 0, $numberOfEntries );
            
            
            // Reverse array?
            if ( $from && $sort_descending) {
                $entries = array_reverse( $entries, true );
            }
            else if ( $to && !$from && (!$sort_descending)) {
                $entries = array_reverse( $entries, true );
            }            
		}
        	    
		return $entries;
	}
}

/** compares two entries by their startunixdate value */
function compareByStartUnixDate($a, $b) {
  return strnatcmp($a['startunixdate'], $b['startunixdate']);
}

/** compares two entries by their startunixdate value */
function compareByEndUnixDate($a, $b) {
  return strnatcmp($b['endunixdate'], $a['endunixdate']);
}

?>