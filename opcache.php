<?php

// Display error and warning message
error_reporting(E_ALL & ~E_NOTICE);
ini_set("display_errors", 1);

define('THOUSAND_SEPARATOR',true);

if (!extension_loaded('Zend OPcache'))
{
	echo '<div style="background-color: #F2DEDE; color: #B94A48; padding: 1em;">You do not have the Zend OPcache extension loaded, sample data is being shown instead.</div>';
	require 'data-sample.php';
}

class OpCacheDataModel
{
	private $_configuration;
	private $_status;
	private $_d3Scripts = array();
	
	public function __construct()
	{
		$this->_configuration = opcache_get_configuration();
		$this->_status = opcache_get_status();
	}
	
	public function getPageTitle()
	{
		return 'PHP ' . phpversion() . " with OpCache {$this->_configuration['version']['version']}";
	}
	
	public function getStatusDataRows()
	{
		$rows = array();
		if ( $this->_status === false )
		{
			return '<br>Opcache is disabled';
		}
		foreach ($this->_status as $key => $value)
		{
			if ($key === 'scripts')
			{
				continue;
			}
			
			if (is_array($value))
			{
				foreach ($value as $k => $v)
				{
					if ($v === false)
					{
						$value = 'false';
					}
					if ($v === true)
					{
						$value = 'true';
					}
					if ($k === 'used_memory' || $k === 'free_memory' || $k === 'wasted_memory')
					{
						$v = $this->_size_for_humans($v);
					}
					if ($k === 'current_wasted_percentage' || $k === 'opcache_hit_rate')
					{
						$v = number_format($v, 2) . '%';
					}
					if ($k === 'blacklist_miss_ratio')
					{
						$v = number_format($v, 2) . '%';
					}
					if ($k === 'start_time' || $k === 'last_restart_time')
					{
						$v = ($v ? date(DATE_RFC822, $v) : 'never');
					}
					if (THOUSAND_SEPARATOR === true && is_int($v))
					{
						$v = number_format($v);
					}
					
					$rows[] = "<tr><th>$k</th><td>$v</td></tr>\n";
				}
				continue;
			}
			if ($value === false)
			{
				$value = 'false';
			}
			if ($value === true)
			{
				$value = 'true';
			}
			$rows[] = "<tr><th>$key</th><td>$value</td></tr>\n";
		}
		
		return implode("\n", $rows);
	}
	
	public function getConfigDataRows()
	{
		$rows = array();
		foreach ($this->_configuration['directives'] as $key => $value)
		{
			if ($value === false)
			{
				$value = 'false';
			}
			if ($value === true)
			{
				$value = 'true';
			}
			if ($key == 'opcache.memory_consumption')
			{
				$value = $this->_size_for_humans($value);
			}
			$rows[] = "<tr><th>$key</th><td>$value</td></tr>\n";
		}
		
		return implode("\n", $rows);
	}
	
	public function getScriptStatusRows()
	{
		if ( $this->_status === false )
		{
			return '<br>Opcache disabled';
		}
		$dirs = array();
		foreach ($this->_status['scripts'] as $key => $data)
		{
			$dirs[dirname($key)][basename($key)] = $data;
			$this->_arrayPset($this->_d3Scripts, $key, array('name' => basename($key), 'size' => $data['memory_consumption'],));
		}
		
		asort($dirs);
		
		$basename = '';
		while (true)
		{
			if (count($this->_d3Scripts) != 1)
				break;
			$basename .= DIRECTORY_SEPARATOR . key($this->_d3Scripts);
			$this->_d3Scripts = reset($this->_d3Scripts);
		}
		
		$this->_d3Scripts = $this->_processPartition($this->_d3Scripts, $basename);
		$id = 1;
		
		$rows = array();
		foreach ($dirs as $dir => $files)
		{
			$count = count($files);
			$file_plural = $count > 1 ? 's' : null;
			$m = 0;
			foreach ($files as $file => $data)
			{
				$m += $data["memory_consumption"];
			}
			$m = $this->_size_for_humans($m);
			
			if ($count > 1)
			{
				$rows[] = '<tr>';
				$rows[] = "<th class=\"clickable\" id=\"head-{$id}\" colspan=\"3\" onclick=\"toggleVisible('#head-{$id}', '#row-{$id}')\">{$dir} ({$count} file{$file_plural}, {$m})</th>";
				$rows[] = '</tr>';
			}
			
			foreach ($files as $file => $data)
			{
				$rows[] = "<tr id=\"row-{$id}\">";
				$rows[] = "<td>" . $this->_format_value($data["hits"]) . "</td>";
				$rows[] = "<td>" . $this->_size_for_humans($data["memory_consumption"]) . "</td>";
				$rows[] = $count > 1 ? "<td>{$file}</td>" : "<td>{$dir}/{$file}</td>";
				$rows[] = '</tr>';
			}
			
			++$id;
		}
		
		return implode("\n", $rows);
	}
	
	public function getScriptStatusCount()
	{
		if ( $this->_status === false )
		{
			return '0';
		}
		return count($this->_status["scripts"]);
	}
	
	public function getGraphDataSetJson()
	{
		$dataset = array();
		$dataset['memory'] = array($this->_status['memory_usage']['used_memory'],
								   $this->_status['memory_usage']['free_memory'],
								   $this->_status['memory_usage']['wasted_memory'],
								   );
		
		$dataset['keys'] = array($this->_status['opcache_statistics']['num_cached_keys'],
								 $this->_status['opcache_statistics']['max_cached_keys'] - $this->_status['opcache_statistics']['num_cached_keys'],
								 0
								 );
		
		$dataset['hits'] = array($this->_status['opcache_statistics']['misses'],
								 $this->_status['opcache_statistics']['hits'],
								 0,
								 );
		
		$dataset['restarts'] = array($this->_status['opcache_statistics']['oom_restarts'],
									 $this->_status['opcache_statistics']['manual_restarts'],
									 $this->_status['opcache_statistics']['hash_restarts'],
									 );
		
		if (THOUSAND_SEPARATOR === true)
		{
			$dataset['TSEP'] = 1;
		}
		else
		{
			$dataset['TSEP'] = 0;
		}
		
		return json_encode($dataset);
	}
	
	public function getHumanUsedMemory()
	{
		return $this->_size_for_humans($this->getUsedMemory());
	}
	
	public function getHumanFreeMemory()
	{
		return $this->_size_for_humans($this->getFreeMemory());
	}
	
	public function getHumanWastedMemory()
	{
		return $this->_size_for_humans($this->getWastedMemory());
	}
	
	public function getUsedMemory()
	{
		return $this->_status['memory_usage']['used_memory'];
	}
	
	public function getFreeMemory()
	{
		return $this->_status['memory_usage']['free_memory'];
	}
	
	public function getWastedMemory()
	{
		return $this->_status['memory_usage']['wasted_memory'];
	}
	
	public function getWastedMemoryPercentage()
	{
		return number_format($this->_status['memory_usage']['current_wasted_percentage'], 2);
	}
	
	public function getD3Scripts()
	{
		return $this->_d3Scripts;
	}
	
	private function _processPartition($value, $name = null)
	{
		if (array_key_exists('size', $value))
		{
			return $value;
		}
		
		$array = array('name' => $name,'children' => array());
		
		foreach ($value as $k => $v)
		{
			$array['children'][] = $this->_processPartition($v, $k);
		}
		
		return $array;
	}
	
	private function _format_value($value)
	{
		if (THOUSAND_SEPARATOR === true)
		{
			return number_format($value);
		}
		else
		{
			return $value;
		}
	}
	
	private function _size_for_humans($bytes)
	{
		if ($bytes > 1048576)
		{
			return sprintf('%.2f&nbsp;MB', $bytes / 1048576);
		}
		else
		{
			if ($bytes > 1024)
			{
				return sprintf('%.2f&nbsp;kB', $bytes / 1024);
			}
			else
			{
				return sprintf('%d&nbsp;bytes', $bytes);
			}
		}
	}
	
	// Borrowed from Laravel
	private function _arrayPset(&$array, $key, $value)
	{
		if (is_null($key))
		{
			return $array = $value;
		}
		$keys = explode(DIRECTORY_SEPARATOR, ltrim($key, DIRECTORY_SEPARATOR));
		while (count($keys) > 1)
		{
			$key = array_shift($keys);
			if (!isset($array[$key]) || !is_array($array[$key]))
			{
				$array[$key] = array();
			}
			$array =& $array[$key];
		}
		$array[array_shift($keys)] = $value;
		return $array;
	}
	
	public function clearCache()
	{
		opcache_reset();
	}
	
}

$dataModel = new OpCacheDataModel();

if (isset($_GET['clear']) && $_GET['clear'] == 1)
{
	$dataModel->clearCache();
	header('Location: ' . $_SERVER['PHP_SELF']);
}
?>
<!DOCTYPE html>
<meta charset="utf-8">
<html>
<head>
	<link rel="stylesheet" href="default.css?<?php echo date("YmdHis")?>">
	<script src="inc/d3-3.0.1.min.js"></script>
	<script src="inc/jquery-1.11.0.min.js"></script>
	<script>
		var hidden = {};
		function toggleVisible(head, row)
		{
			if (!hidden[row])
			{
				d3.selectAll(row).transition().style('display', 'none');
				hidden[row] = true;
				d3.select(head).transition().style('color', '#ccc');
			}
			else
			{
				d3.selectAll(row).transition().style('display');
				hidden[row] = false;
				d3.select(head).transition().style('color', '#000');
			}
		}
	</script>
	<title><?php echo $dataModel->getPageTitle(); ?></title>
</head>

<body>
<div id="container">
	<h1><?php echo $dataModel->getPageTitle(); ?></h1>

	<div class="button">
		<a href="?clear=1">Clear cache</a>
	</div>

	<div class="tabs">

		<div class="tab">
			<input type="radio" id="tab-status" name="tab-group-1" checked>
			<label for="tab-status">Status</label>
			<div class="content">
				<table>
					<?php echo $dataModel->getStatusDataRows(); ?>
				</table>
			</div>
		</div>

		<div class="tab">
			<input type="radio" id="tab-config" name="tab-group-1">
			<label for="tab-config">Configuration</label>
			<div class="content">
				<table>
					<?php echo $dataModel->getConfigDataRows(); ?>
				</table>
			</div>
		</div>

		<div class="tab">
			<input type="radio" id="tab-scripts" name="tab-group-1">
			<label for="tab-scripts">Scripts (<?php echo $dataModel->getScriptStatusCount(); ?>)</label>
			<div class="content">
				<table style="font-size:0.8em;">
					<tr>
						<th width="10%">Hits</th>
						<th width="20%">Memory</th>
						<th width="70%">Path</th>
					</tr>
					<?php echo $dataModel->getScriptStatusRows(); ?>
				</table>
			</div>
		</div>

		<div class="tab">
			<input type="radio" id="tab-visualise" name="tab-group-1">
			<label for="tab-visualise">Visualise Partition</label>
			<div class="content"></div>
		</div>
	</div>

	<div id="graph">
		<form>
			<label><input type="radio" name="dataset" value="memory" checked> Memory</label>
			<label><input type="radio" name="dataset" value="keys"> Keys</label>
			<label><input type="radio" name="dataset" value="hits"> Hits</label>
			<label><input type="radio" name="dataset" value="restarts"> Restarts</label>
		</form>

		<div id="stats"></div>
	</div>
</div>

<div id="close-partition">&#10006; Close Visualisation</div>
<div id="partition"></div>

<script><?php include "./default.js" ?></script>

</body>
</html>
