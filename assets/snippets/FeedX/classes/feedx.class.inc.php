<?php
/*---------------------------------------------------------------------------
* FeedX Class - Contains xml/feed retrieval, caching, parsing, 
*               and rendering funcitons for the FeedX snippet
*		for the MODx content management system.
*----------------------------------------------------------------------------
*
* This program is free software; you can redistribute it and/or modify it 
* under the terms of the GNU General Public License as published by the 
* Free Software Foundation.
*
* This program is distributed in the hope that it will be useful, but 
* WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY 
* or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU General Public License 
* for more details.
*
* @author Brian Stanback (www.stanback.net)
* @copyright Brian Stanback 2007
* @created: 2007/02/27
* @updated: 2007/04/08
* @version 0.1.2
*
*--------------------------------------------------------------------------*/

class FeedX
{
	var $config;  // Array containing snippet configuration values
	var $elements;  // Array containing parsed feed data

	/**
	* Class constructor, set configuration parameters
	*/
	function FeedX($params, $language = array())
	{
		$GLOBALS['feedx_lang'] = $language;
		$this->config = $params;
	}

	/**
	* Retrieve a version of the requested feed and return rendered output
	*/
	function execute()
	{
		global $modx;

		$output = '';

		if ($this->config['cacheType'] == 1)
		{
			$outputCache = ($this->config['cacheType'] == 1) ? $this->config['cachePath'] . 'out_' . dechex(crc32(implode('', $this->config))) : '';
			if (file_exists($outputCache) && time() - $this->config['cacheTime'] < filemtime($outputCache))
				$output = file_get_contents($outputCache);  // Get cached output
		}

		if (!$output)
		{
			// Retrieve element-specific configuration parameters
			$chunks = array();
			$this->getConfig($chunks, $this->config['maxElements'], 'max');
			$this->getConfig($chunks, $this->config['startElements'], 'start');
			$this->getConfig($chunks, $this->config['filterElements'], 'filter');
			$this->getConfig($chunks, $this->config['sortElements'], 'sort');
			$this->getConfig($chunks, $this->config['oddElements'], 'odd');
			$this->getConfig($chunks, $this->config['evenElements'], 'even');
			$this->getConfig($chunks, $this->config['firstElement'], 'first');
			$this->getConfig($chunks, $this->config['lastElement'], 'last');

			// Get outer chunk/file template
			if ($this->config['outerChunk'] != '')
				$tpl = $modx->getChunk($this->config['outerChunk']);
			else
				$tpl = file_get_contents($this->config['feedxPath'] . 'tpl/' . $this->config['preset'] . '/' . 'outer.tpl');

			if ($this->getData())  // Retrieve the data, updating cache if necessary
			{
				if ($this->config['userPh'] != '')  // Apply custom placeholders
				{
					if (!$this->config['usePhx']) $placeholders = array();  // Use manual replacement array for non-PHx mode
					foreach (explode(':', $this->config['userPh']) as $placeholder)
					{
						$placeholder = str_replace($this->config['replaceColon'], ':', $placeholder);
						list($name, $value) = explode('->', $placeholder);
						if (isset($placeholders))
							$placeholders['[+' . $name . '+]'] = $value;
						else
							$modx->setPlaceholder($name, $value);
					}
				}

				$output = $this->render($this->elements, $chunks, $tpl);  // Render and return result

				if (isset($placeholders)) $output = str_replace(array_keys($placeholders), array_values($placeholders), $output);

				if ($this->config['cacheType'] == 1)  // Update the output cache
				{
					$fh = fopen($outputCache, 'w+');
					fwrite($fh, $output);
					fclose($fh);
				}
			}
		}

		if (function_exists('mb_convert_encoding'))  // Convert output encoding, if available
			return mb_convert_encoding($output, $modx->config['modx_charset'], 'auto');
		else
			return $output;
	}

	/**
	* Retrieve the current feed's data structure
	*/
	function getData()
	{
		$success = true;

		$cache = $this->config['cachePath'] . 'url_' . dechex(crc32($this->config['url']));
		if (!file_exists($cache) || time() - $this->config['cacheTime'] > filemtime($cache))
		{
			// Retrieve feed, populating $this->elements
			$success = $this->retrieve($cache);
		}
		else
			$this->elements = unserialize(file_get_contents($cache));  // Get cached data

		return $success;
	}

	/**
	* Render formatted output from feed data and template
	*/
	function render(&$data, &$chunks, &$tpl, $depth = 0)
	{
		global $modx;

		$colonReplacement = $this->config['replaceColon'];

		if ($this->config['usePhx']) $phx = new PHxParser(0,500);  // Use PHx, if enabled
		else $placeholders = array();

		// Extract chunk calls
		preg_match_all('#{{([^\(-]+)(\(([0-9]+),?([0-9]+)?\))?->([^}]+)}}#', $tpl, $result);
		$size = count($result[1]);
		for ($i=0; $i<$size; $i++)
		{
			$idx = 0;
			while (isset($chunks[$result[1][$i]][$idx]['call']))
				$idx++;
			
			$chunks[$result[1][$i]][$idx]['call'] = $result[0][$i];
			if (is_numeric($result[3][$i])) $chunks[$result[1][$i]][$idx]['max'] = $result[3][$i];
			elseif (!isset($chunks[$result[1][$i]][$idx]['max'])) $chunks[$result[1][$i]][$idx]['max'] = 250;
			if (is_numeric($result[4][$i])) $chunks[$result[1][$i]][$idx]['start'] = $result[4][$i] * -1;
			$chunks[$result[1][$i]][$idx]['chunk'] = $result[5][$i];

			if (isset($chunks[$result[1][$i]][$idx]['filter']))
			{
				list($elem, $comparison, $match) = explode(' ', $chunks[$result[1][$i]][$idx]['filter'], 3);
				$this->filterNodes($data, $result[1][$i], str_replace($colonReplacement, ':', $elem), $comparison, $match);
			}
			if (isset($chunks[$result[1][$i]][$idx]['sort']))
			{
				list($elem, $order) = explode(' ', $chunks[$result[1][$i]][$idx]['sort'], 2);
				$this->sortNodes($data, $result[1][$i], str_replace($colonReplacement, ':', $elem), $order);
			}
		}

		$unique = array();  // For keeping track of repeating elements
		foreach ($data as $child)
		{
			$child['name'] = str_replace(':', $colonReplacement, $child['name']);
			if ($unique[$depth.$child['name']] < 1)
				$tagName = $child['name'];
			else
				$tagName = $child['name'] . '.' . $unique[$depth.$child['name']];

			if (is_array($child['attr']))
			{
				foreach ($child['attr'] as $name => $value)  // Replace attribute values
				{
					if ($this->config['convEntities'] == 0)
						$value = htmlentities($value);

					$name = str_replace(':', $colonReplacement, $name);
					if (is_object($phx)) 
						$phx->setPHxVariable($tagName . '.' . $name, $value);
					else
						$placeholders['[+' . $tagName . '.' . $name . '+]'] = $value;
				}
			}
			if (isset($child['value']))  // Replace tag values
			{
				if ($this->config['convEntities'] == 0)
					$child['value'] = htmlentities($child['value']);

				if (is_object($phx)) 
					$phx->setPHxVariable($tagName, $child['value']);
				else
					$placeholders['[+' . $tagName . '+]'] = $child['value'];
			}
			if (is_array($child['children']) && is_array($chunks[$child['name']]))
			{
				$numChunks = count($chunks[$child['name']]);
				for ($i=0; $i<$numChunks; $i++)
				{
					if (isset($chunks[$child['name']][$i]['call']))
					{
						// Come up with a start and end value for displaying chunks based on first or last node
						if (isset($chunks[$child['name']][$i]['first']) && !isset($chunks[$child['name']][$i]['tail']))
						{
							$cCount = 0;
							foreach ($data as $elem)
								if ($elem['name'] == $child['name'])
									$cCount++;  // Manually count up elements

							if ($cCount < $chunks[$child['name']][$i]['max'])
								$chunks[$child['name']][$i]['tail'] = $cCount + $chunks[$child['name']][$i]['start'] - 1;
							else
								$chunks[$child['name']][$i]['tail'] = $chunks[$child['name']][$i]['max'] - 1;
						}

						if ($chunks[$child['name']][$i]['start'] < 0)  // An offset was specified
							$chunks[$child['name']][$i]['start']++;
						elseif ($chunks[$child['name']][$i]['start'] < $chunks[$child['name']][$i]['max'])  // Check for limit
						{
							if (isset($chunks[$child['name']][$i]['first']) && $chunks[$child['name']][$i]['start'] == 0)
								$tplName = $chunks[$child['name']][$i]['first'];
							elseif (isset($chunks[$child['name']][$i]['last']) && $chunks[$child['name']][$i]['start'] == $chunks[$child['name']][$i]['tail'])
								$tplName = $chunks[$child['name']][$i]['last'];
							elseif (isset($chunks[$child['name']][$i]['odd']) && !($chunks[$child['name']][$i]['start'] & 1))
								$tplName = $chunks[$child['name']][$i]['odd'];
							elseif (isset($chunks[$child['name']][$i]['even']) && $chunks[$child['name']][$i]['start'] & 1)
								$tplName = $chunks[$child['name']][$i]['even'];
							else
								$tplName = $chunks[$child['name']][$i]['chunk'];

							if ($this->config['outerChunk'] != '')
								$out = $this->render($child['children'], $chunks, $modx->getChunk($tplName), $depth+1);  // Render a chunk
							else
								$out = $this->render($child['children'], $chunks, file_get_contents($this->config['feedxPath'] . 'tpl/' . $this->config['preset'] . '/' . $tplName . '.tpl'), $depth+1);  // Render a file
							$tpl = str_replace($chunks[$child['name']][$i]['call'], $out . $chunks[$child['name']][$i]['call'], $tpl);

							$chunks[$child['name']][$i]['start']++;
						}
					}
				}
			}

			$unique[$depth.$child['name']]++;
		}

		if ($depth == 0)  // Clean up chunk placeholders
		{
			foreach ($chunks as $chunk)
				foreach ($chunk as $item)
					if (isset($item['call']))
						$tpl = str_replace($item['call'], '', $tpl);
		}

		if (is_object($phx))
			return $phx->Parse($tpl);  // Pass through PHx
		else
			return str_replace(array_keys($placeholders), array_values($placeholders), $tpl);
	}

	/**
	* Pull requested xml data from the cache, creating/updating the cache
	* file if necessary
	*/
	function retrieve($file)
	{
		global $feedx_lang, $modx;

		$success = true;

		// Retrieve and parse feed
		if (function_exists('curl_init'))
		{
			// Use curl to retrieve feed
			$curl = curl_init();
			curl_setopt($curl, CURLOPT_URL, $this->config['url']);
			curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($curl, CURLOPT_HEADER, false);
			curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, $this->config['timeout']);
			$xml = curl_exec($curl);
			if (curl_errno($curl) !== 0) $success = false;  // An error occured
			curl_close($curl);
		}
		else
		{
			// Attempt to use built-in handler
			$xml = file_get_contents($this->config['url']);
			if ($xml == null) $success = false;  // An error occured
		}

		if ($success)
		{
			if ($this->parse($xml))
			{
				// Update the cache
				$fh = fopen($file, 'w+');
				fwrite($fh, serialize($this->elements));
				fclose($fh);
			}
			else
			{
				$modx->logEvent(1, 3, 'FeedX: ' . $feedx_lang['xml_parse_error'] . ': ' . $this->config['url']);
				$success = false;
			}
		}
		else
			$modx->logEvent(1, 3, 'FeedX: ' . $feedx_lang['download_error'] . ': ' . $this->config['url']);

		if (!$success)
		{
			// Unable to retrieve feed, fall back to cached data if available
			if (file_exists($file))
			{
				$this->elements = unserialize(file_get_contents($file));
				$success = true;
			}
		}

		return $success;
	}

	/**
	* Parse the feed data using PHP's XML Parser functions
	*/
	function parse($xml)
	{
		$this->elements = array();

		// Extract encoding type from xml data
		preg_match("#encoding=(\"|')([^\"']+)#i", $xml, $matches);
		$encType = isset($matches[2]) ? $matches[2] : 'UTF-8';

		$parser = xml_parser_create($encType);
		xml_parser_set_option($parser, XML_OPTION_CASE_FOLDING, 1);
		xml_set_object($parser, $this);
		xml_set_element_handler($parser, 'tagStart', 'tagEnd');
		xml_set_character_data_handler($parser, 'tagData');

		$result = xml_parse($parser, $xml);

		xml_parser_free($parser);

		return $result;
	}

	/**
	* XML Parser start tag handler
	*/
	function tagStart($parser, $name, $attrs)
	{
		$tag = array('name' => $name);
		if (count($attrs) > 0) $tag['attr'] = $attrs;
		array_push($this->elements, $tag);
	}

	/**
	* XML Parser end tag handler
	*/
	function tagEnd($parser, $name)
	{
		$this->elements[count($this->elements)-2]['children'][] = $this->elements[count($this->elements)-1];
		array_pop($this->elements);
	}

	/**
	* XML Parser tag data/value handler
	*/
	function tagData($parser, $data)
	{
		$data = trim($data);
		if ($data)
		{
			if (isset($this->elements[count($this->elements)-1]['value']))
				$this->elements[count($this->elements)-1]['value'] .= $data;
			else
				$this->elements[count($this->elements)-1]['value'] = $data;
		}
	}

	/**
	* Parse and return configuraiton parameters
	*/
	function getConfig(&$chunks, $string, $type)
	{
		if ($string != '')
		{
			foreach (explode(':', $string) as $item)
			{
				switch ($type)
				{
					case 'max':
					case 'filter':
					case 'sort':
						list($elem, $value) = explode('(', $item, 2);
						$value = substr($value, 0, -1);
					break;
					case 'start':
						list($elem, $value) = explode('(', $item, 2);
						$value = substr($value, 0, -1) * -1;
					break;
					default:
						list($elem, $value) = explode('->', $item, 2);
				}
				$chunks[$elem][0][$type] = $value;
			}
		}
	}

	/**
	* Filter nodes in the array according to comparison and matching regular expression
	*/
	function filterNodes(&$data, $element, $child, $comparison, $match)
	{
		$comparison = (strtoupper($comparison) == 'EQ');

		// Apply filter on dataset
		foreach ($data as $key => $item)
		{
			if ($item['name'] == $element && is_array($item['children']))  // Found matching item
			{
				foreach ($item['children'] as $s_item)
				{
					if ($s_item['name'] == $child)  // Found matching field
					{
						if (preg_match($match, $s_item['value']))
						{
							if ($comparison)
								unset($data[$key]);  // Remove matching items (eq)
						}
						elseif (!$comparison)
							unset($data[$key]);  // Remove non-matching items (ne)
						break;
					}
				}
			}
		}
	}

	/**
	* Perform a sort on the nodes in the array according to defined element and sort child
	*/
	function sortNodes(&$data, $element, $child, $sortOrder = 'ASC')
	{
		$sort = $sorted = $temp = array();  // Temporary arrays

		if (strtoupper($child) == '%RAND%')
		{
			// Populate sortable array
			foreach ($data as $key => $item)
				if ($item['name'] == $element && is_array($item['children']))
					$sort[$key] = rand();  // Set key value to random integer

			$sorted = $sort;
			asort($sorted);  // Sort array to randomize
		}
		else
		{
			// Separate name from attribute
			$pos = strrpos($child, '.');
			if ($pos !== false)
			{
				$attr = substr($child, $pos+1, strlen($child));
				$child = substr($child, 0, $pos);
			}

			// Populate sortable array
			foreach ($data as $key => $item)
				if ($item['name'] == $element && is_array($item['children']))
					foreach ($item['children'] as $s_item)
						if ($s_item['name'] == $child)
						{
							$sort[$key] = isset($attr) ? $s_item['attr'][$attr] : $s_item['value'];
							break;
						}

			$sorted = $sort;
			natcasesort($sorted);  // Apply natural sort to newly constructed array
			if (strtoupper($sortOrder) == 'DESC')
				arsort($sorted);  // Convert to descending order
		}

		// Swap original data based on sort results
		foreach ($sort as $key => $item)
		{
			$s_key = key($sorted);
			if ($s_key != $key)  // Swap
			{
				$temp[$key] = $data[$key];
				if (isset($temp[$s_key]))
					$data[$key] = $temp[$s_key];
				else
					$data[$key] = $data[$s_key];
			}
			next($sorted);
		}
	}
}
?>
