<?php
/*---------------------------------------------------------------------------
* FeedX Debug Class - Displays information about FeedX snippet configuraiton 
* 	and provides a useful facility for building custom templates
*----------------------------------------------------------------------------
*
* Adapted from the Ditto 2 debug class by Mark Kaplan <www.modxcms.com>
*
*--------------------------------------------------------------------------*/

class FXDebug
{
	function saveDebugConsole($debug_html, $feedx_version) {
		global $modx;
		header('Content-Type: text/html; charset=' . $modx->config['modx_charset']);
		header('Content-Disposition: attachment; filename="feedx-' . strtolower($feedx_version) . '_debug_doc' . $modx->documentIdentifier . '.html"');
		exit($debug_html);
	}

	function render_link($feedx, $dbg_templates)
	{
		global $modx, $feedx_lang;
		$base_path = str_replace($modx->config['base_path'], $modx->config['site_url'], $feedx->config['feedxPath']);
		$url_hash = dechex(crc32($feedx->config['url']));
		$placeholders = array(
			'[+open_url+]' => $modx->makeUrl($modx->documentIdentifier, '', 'dbg_dump=open&amp;dbg_hash=' . $url_hash),
			'[+dbg_title+]' => $feedx_lang['debug'],
			'[+dbg_icon_url+]' => $base_path . 'debug/bug.png',
			'[+save_url+]' => $modx->makeUrl($modx->documentIdentifier, '', 'dbg_dump=save&amp;dbg_hash=' . $url_hash),
			'[+dbg_icon_url+]' => $base_path . 'debug/bug.png',
			'[+open_dbg_console+]' => $feedx_lang['open_dbg_console'],
			'[+save_dbg_console+]' => $feedx_lang['save_dbg_console'],
		);
		return str_replace(array_keys($placeholders), array_values($placeholders), $dbg_templates['links']);
	}

	function render_popup($feedx, $feedx_version, $dbg_templates)
	{
		global $modx, $feedx_lang;

		if (count($feedx->elements) == 0)
			$feedx->getData();

		$cTabs = array();

		if (count($feedx->elements) > 0)
		{
			$tbOutput = $dataOutput = '';
			$this->dataRender($feedx->elements, $feedx->config['replaceColon'], $tbOutput, $dataOutput);

			$cTabs[$feedx_lang['info']] = $this->prepareBasicInfo($feedx, $feedx_version);
			$cTabs[$feedx_lang['modx']] = $this->makeMODxInfo();
			$cTabs[$feedx_lang['loaded_templates']] = $this->parameters2table($this->retrieveTemplates($feedx), $feedx_lang['templates'], false);
			$cTabs[$feedx_lang['template_builder']] = '<div class="feedx_dbg_fields">' . $tbOutput . '</div>';
			$cTabs[$feedx_lang['feed_data']] = $dataOutput;
		}
		else
		{
			$cTabs[$feedx_lang['info']] = $this->prepareBasicInfo($feedx, $feedx_version, true);
			$cTabs[$feedx_lang['modx']] = $this->makeMODxInfo();
		}

		$tabs = '';
		foreach ($cTabs as $name=>$content) {
			$tabs .= $this->makeTab($name, $content);
		}

		$placeholders = array
		(
			'[+base_url+]' => $modx->config['site_url'] . 'manager',
			'[+feedx_base_url+]' => str_replace($modx->config['base_path'], $modx->config['site_url'], $feedx->config['feedxPath']),
			'[+theme+]' => $modx->config['manager_theme'],
			'[+title+]' => $feedx_lang['debug'],
			'[+content+]' => $tabs,
			'[+charset+]' => $modx->config['modx_charset'],
		);
	
		return str_replace(array_keys($placeholders), array_values($placeholders), $dbg_templates['main']);
	}

	function makeTab($title,$content)
	{
		$output= '<div class="tab-page" id="tab_'  . $title  .  '">  
			    <h2 class="tab">' . $title. '</h2>  
			    <script type="text/javascript">tpResources.addTabPage( document.getElementById( "tab_'  . $title  .  '" ) );</script> 
				';
		$output .= $content;
		$output.='</div>';
		return $output;
	}

	function makeMODxInfo()
	{
		global $feedx_lang, $modx;

		$output = $this->parameters2table($modx->placeholders, $feedx_lang['placeholders']);
		$output .= $this->parameters2table($modx->documentObject, $feedx_lang['document_info']);
		return $output;
        }

	function prepareBasicInfo($feedx, $feedx_version, $error = false)
	{
		global $modx, $feedx_lang;

		$items[$feedx_lang['version']] = 'FeedX ' . $feedx_version;
		if ($error)
			$items[$feedx_lang['url']] = $feedx->config['url'] . ' [' . $feedx_lang['url_error'] . ']';
		else
			$items[$feedx_lang['url']] = $feedx->config['url'];
		$items[$feedx_lang['cache_type']] = ($feedx->config['cacheType'] == 0) ? $feedx_lang['data_caching'] : $feedx_lang['data_output_caching'];
		$items[$feedx_lang['cache_time']] = $feedx->config['cacheTime'] . ' ' . $feedx_lang['seconds'];
		if ($feedx->config['outerChunk'] == '')
			$items[$feedx_lang['preset']] = $feedx->config['preset'];
		else
			$items[$feedx_lang['outer_chunk']] = $feedx->config['outerChunk'];
		$items[$feedx_lang['max_elements']] = str_replace(':', ', ', $feedx->config['maxElements']);
		$items[$feedx_lang['start_elements']] = str_replace(':', ', ', $feedx->config['startElements']);
		$items[$feedx_lang['sort_elements']] = str_replace(':', ', ', $feedx->config['sortElements']);
		$items[$feedx_lang['filter_elements']] = str_replace(':', ', ', $feedx->config['filterElements']);
		$items[$feedx_lang['odd_elements']] = str_replace(':', ', ', $feedx->config['oddElements']);
		$items[$feedx_lang['even_elements']] = str_replace(':', ', ', $feedx->config['evenElements']);
		$items[$feedx_lang['first_element']] = str_replace(':', ', ', $feedx->config['firstElement']);
		$items[$feedx_lang['last_element']] = str_replace(':', ', ', $feedx->config['lastElement']);
		$items[$feedx_lang['user_placeholders']] = str_replace(':', ', ', $feedx->config['userPh']);

		return $this->parameters2table($items, $feedx_lang['basic_info'], false, false);
	}

	function retrieveTemplates($feedx)
	{
		$type = ($feedx->config['outerChunk'] != '') ? true : false;
		$loadedTemplates = array();
		$this->innerTemplates($feedx->config['feedxPath'] . 'tpl/' . $feedx->config['preset'] . '/', ($feedx->config['outerChunk'] == '') ? 'outer' : $feedx->config['outerChunk'], $type, $loadedTemplates);

		foreach (array($feedx->config['oddElements'], $feedx->config['evenElements'], $feedx->config['firstElement'], $feedx->config['lastElement']) as $param)
		{
			if ($param != '')
			{
				list($elem, $chunk) = explode('->', $param);
				if (!isset($loadedTemplates[$elem]))
					$this->innerTemplates($feedx->config['feedxPath'] . 'tpl/' . $feedx->config['preset'] . '/', $chunk, $type, $loadedTemplates);
			}
		}
 
		return $loadedTemplates;
	}

	function innerTemplates($presetPath, $chunk, $type = false, &$templates)
	{
		global $feedx_lang, $modx;

		if ($type)
		{
			$tpl = $modx->getChunk($chunk);
			if ($tpl == null)
				$tpl = $feedx_lang['chunk_load_error'];
		}
		else
		{
			if (file_exists($presetPath . $chunk . '.tpl'))
				$tpl = file_get_contents($presetPath . $chunk . '.tpl');
			else
				$tpl = $feedx_lang['preset_load_error'];
		}

		$templates[$chunk] = $tpl;

		preg_match_all('#{{([^\(-]+)(\(([0-9]+),?([0-9]+)?\))?->([^}]+)}}#', $tpl, $matches);
                $size = count($matches[1]);
                for ($i=0; $i<$size; $i++)
		{
			$this->innerTemplates($presetPath, $matches[5][$i], $type, $templates);
		}
	}

	function dataRender(&$data, $colonReplacement, &$tbOutput, &$dataOutput)
	{
		$templateResult = $dataResult = array();
		$this->templateBuilder($data, $colonReplacement, $templateResult, $dataResult);

		foreach ($templateResult as $value)
			$tbOutput .= $this->array2table($value, true, true);

		foreach ($dataResult as $value)
			foreach ($value as $header => $content)
				$dataOutput .= $this->parameters2table($content, $header, false);
	}

	function templateBuilder(&$data, $colonReplacement, &$templateResult, &$dataResult, $depth = 0, $parent = '-')
	{
		global $feedx_lang;

		$i = 0;
		$origParent = $parent;
		while (isset($dataResult[$depth][$parent]))
		{
			$parent = $origParent . ' [' . ++$i . ']';
		}

		$unique = array();  // For keeping track of repeating elements
		foreach ($data as $elem)
		{
			$elem['name'] = str_replace(':', $colonReplacement, $elem['name']);

			if ($unique[$depth.$elem['name']] < 1)
				$tagName = $elem['name'];
			else
				$tagName = $elem['name'] . '.' . $unique[$depth.$elem['name']];

			if (is_array($elem['attr']))
			{
				foreach ($elem['attr'] as $name => $value) // Replace attribute values
				{
					$name = str_replace(':', $colonReplacement, $name);
					if ($parent == $origParent)  // Show repeating elements only once
						$templateResult[$depth][$parent][$feedx_lang['placeholders']][] = '[+' . $tagName . '.' . $name . '+]';
					$dataResult[$depth][$parent][$tagName . '.' . $name] = $value;
				}
			}

			if (isset($elem['value']))  // Replace tag values
			{
				if ($parent == $origParent)  // Show repeating elements only once
					$templateResult[$depth][$parent][$feedx_lang['placeholders']][] = '[+' . $tagName . '+]';
				$dataResult[$depth][$parent][$tagName] = $elem['value'];
			}

			if (is_array($elem['children']))
			{
				if (!isset($unique[$depth.$elem['name']]))  // Show repeating elements only once
					$templateResult[$depth][$parent][$feedx_lang['children']][] = '{{' . $elem['name'] . '->...}}';
				$this->templateBuilder($elem['children'], $colonReplacement, $templateResult, $dataResult, $depth+1, $elem['name']);
			}

			$unique[$depth.$elem['name']]++;
		}
	}

	//---Helper Functions------------------------------------------------ //

	function modxPrep($value) {
		$value = (strpos($value,"<") !== FALSE) ? "<pre>".htmlentities($value)."</pre>" : $value;
		$value = str_replace("[","&#091;",$value);
		$value = str_replace("]","&#093;",$value);
		$value = str_replace("{","&#123;",$value);
		$value = str_replace("}","&#125;",$value);
		return $value;
	}

	function parameters2table($parameters, $header, $sort = true, $prep = true) {
		global $feedx_lang;
		if (!is_array($parameters))
			return $feedx_lang['resource_array_error'];
		if ($sort === true)
			ksort($parameters);

		$output = '<table>
				  <tbody>
				    <tr>
				      <th>'.$header.'</th>
				    </tr>
				    <tr>
				      <td>
				      <table>
				        <tbody>
		';
		foreach ($parameters as $key=>$value) {
			if (!is_string($value) && !is_float($value) && !is_int($value)) {
				if (is_array($value)) {
					$value = var_export($value,true);
				} else {
					$name = gettype($value);
					$value = strtoupper($name{0}).substr($name,1);
				}
			}
			$v = ($prep == true) ? $this->modxPrep($value) : $value;
			$v = wordwrap($v,100,"\r\n",1);
			$output .= '
					    <tr>
					      <th>'.$key.'</th>
					      <td>'.$v.'</td>
					    </tr>
			';
		}
		$output .=
		'
				        </tbody>
				      </table>
				      </td>
				    </tr>
				  </tbody>
				</table>
				';

		return $output;
	}
	
	/**
	 * Translate a result array into a HTML table
	 *
	 * @author      Aidan Lister <aidan@php.net>
	 * @version     1.3.1
	 * @link        http://aidanlister.com/repos/v/function.array2table.php
	 * @param       array  $array      The result (numericaly keyed, associative inner) array.
	 * @param       bool   $recursive  Recursively generate tables for multi-dimensional arrays
	 * @param       bool   $return     return or echo the data
	 * @param       string $null       String to output for blank cells
	 */
	function array2table($array, $recursive = false, $return = false, $null = '&nbsp;') {
	    // Sanity check
	    if (empty($array) || !is_array($array)) {
	        return false;
	    }

	    if (!isset($array[0]) || !is_array($array[0])) {
	        $array = array($array);
	    }

	    // Start the table
	    $table = "<table>\n";
		$head = array_keys($array[0]);
	if (!is_numeric($head[0])) {
	    // The header
	    $table .= "\t<tr>";
	    // Take the keys from the first row as the headings
	    foreach (array_keys($array[0]) as $heading) {
	        $table .= '<th>' . $heading . '</th>';
	    }
	    $table .= "</tr>\n";
	}
	    // The body
	    foreach ($array as $row) {
	        $table .= "\t<tr>" ;
	        foreach ($row as $cell) {
	            $table .= '<td>';

	            // Cast objects
	            if (is_object($cell)) { $cell = (array) $cell; }

	            if ($recursive === true && is_array($cell) && !empty($cell)) {
	                // Recursive mode
	                $table .= "\n" . $this->array2table($cell, true, true) . "\n";
	            } else {
	                $table .= (strlen($cell) > 0) ?
					htmlspecialchars((string) $cell) :
					$null;
	            }

	            $table .= '</td>';
	        }

	        $table .= "</tr>\n";
	    }

	    // End the table
	    $table .= '</table>';

	    // Method of output
	    if ($return === false) {
	        echo $table;
	    } else {
	        return $table;
	    }
	}
}

?>
